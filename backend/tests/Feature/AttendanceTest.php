<?php

declare(strict_types=1);

use App\Domain\Academic\Models\Level;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Services\EnrollmentService;
use App\Domain\Attendance\Models\AttendanceJustification;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceService;
use App\Domain\Identity\Models\Role;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\AttendanceStatus;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Student\Models\Student;
use App\Jobs\NotifyGuardiansOfAbsence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->school = $this->makeSchool();
    $this->year = AcademicYear::query()->where('school_id', $this->school->id)->firstOrFail();

    $this->withinTenant($this->school, function (): void {
        $level = Level::query()->create([
            'school_id' => $this->school->id, 'name' => '5ème', 'sequence' => 2,
        ]);

        $this->class = SchoolClass::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $level->id,
            'name' => '5ème A',
        ]);

        $this->students = collect(range(1, 3))->map(function () {
            $student = Student::factory()->create(['school_id' => $this->school->id]);
            app(EnrollmentService::class)->enroll($student, $this->class);

            return $student;
        });
    });

    $this->service = app(AttendanceService::class);
    $this->today = CarbonImmutable::now()->startOfDay();
});

it('records a register for a class', function (): void {
    $result = $this->withinTenant($this->school, fn () => $this->service->recordForClass(
        $this->class,
        $this->today,
        $this->students->map(fn ($s): array => [
            'student_id' => $s->id,
            'status' => AttendanceStatus::Present->value,
        ])->all(),
    ));

    expect($result['recorded'])->toBe(3)
        ->and(AttendanceRecord::query()->withoutTenantScope()->count())->toBe(3);
});

it('is idempotent — resubmitting updates rather than duplicating', function (): void {
    $entries = $this->students->map(fn ($s): array => [
        'student_id' => $s->id,
        'status' => AttendanceStatus::Present->value,
    ])->all();

    $this->withinTenant($this->school, function () use ($entries): void {
        $this->service->recordForClass($this->class, $this->today, $entries);
        $this->service->recordForClass($this->class, $this->today, $entries);
    });

    expect(AttendanceRecord::query()->withoutTenantScope()->count())->toBe(3);
});

it('writes a revision when a mark is corrected after the fact', function (): void {
    $student = $this->students->first();
    $actor = $this->makeUser($this->school, Role::SCHOOL_ADMIN);

    $this->withinTenant($this->school, function () use ($student, $actor): void {
        $this->service->recordForClass(
            $this->class, $this->today,
            [['student_id' => $student->id, 'status' => AttendanceStatus::Absent->value]],
            $actor,
        );

        $this->service->recordForClass(
            $this->class, $this->today,
            [['student_id' => $student->id, 'status' => AttendanceStatus::Present->value]],
            $actor,
        );
    });

    $record = AttendanceRecord::query()->withoutTenantScope()->where('student_id', $student->id)->firstOrFail();

    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->revisions()->count())->toBe(1)
        ->and($record->revisions()->first()->old_status)->toBe(AttendanceStatus::Absent->value)
        ->and($record->revisions()->first()->new_status)->toBe(AttendanceStatus::Present->value);
});

it('queues a guardian alert for an absence, exactly once', function (): void {
    Queue::fake();

    $student = $this->students->first();
    $entries = [['student_id' => $student->id, 'status' => AttendanceStatus::Absent->value]];

    $this->withinTenant($this->school, function () use ($entries): void {
        $this->service->recordForClass($this->class, $this->today, $entries);
        // A second identical submission must not alert the parent again.
        $this->service->recordForClass($this->class, $this->today, $entries);
    });

    Queue::assertPushed(NotifyGuardiansOfAbsence::class, 1);
});

it('does not alert on a present mark', function (): void {
    Queue::fake();

    $this->withinTenant($this->school, fn () => $this->service->recordForClass(
        $this->class, $this->today,
        [['student_id' => $this->students->first()->id, 'status' => AttendanceStatus::Present->value]],
    ));

    Queue::assertNotPushed(NotifyGuardiansOfAbsence::class);
});

it('refuses to record attendance for a future date', function (): void {
    $this->withinTenant($this->school, fn () => $this->service->recordForClass(
        $this->class,
        $this->today->addDay(),
        [['student_id' => $this->students->first()->id, 'status' => AttendanceStatus::Present->value]],
    ));
})->throws(DomainException::class);

it('refuses to mark a student who is not in the class', function (): void {
    $outsider = $this->withinTenant(
        $this->school,
        fn () => Student::factory()->create(['school_id' => $this->school->id]),
    );

    $this->withinTenant($this->school, fn () => $this->service->recordForClass(
        $this->class, $this->today,
        [['student_id' => $outsider->id, 'status' => AttendanceStatus::Absent->value]],
    ));
})->throws(DomainException::class);

it('approves a justification and marks the absence justified', function (): void {
    $student = $this->students->first();
    $guardianUser = $this->makeUser($this->school, Role::PARENT);
    $reviewer = $this->makeUser($this->school, Role::SCHOOL_ADMIN);

    $this->withinTenant($this->school, function () use ($student, $guardianUser, $reviewer): void {
        $this->service->recordForClass(
            $this->class, $this->today,
            [['student_id' => $student->id, 'status' => AttendanceStatus::Absent->value]],
        );

        $record = AttendanceRecord::query()->where('student_id', $student->id)->firstOrFail();

        $justification = $this->service->submitJustification($record, 'Medical appointment', $guardianUser);

        expect($justification->status)->toBe(AttendanceJustification::STATUS_PENDING)
            ->and($record->fresh()->is_justified)->toBeFalse();

        $this->service->reviewJustification($justification, true, $reviewer, 'Note received');

        expect($record->fresh()->is_justified)->toBeTrue();
    });
});

it('refuses a second justification while one is pending', function (): void {
    $student = $this->students->first();
    $guardianUser = $this->makeUser($this->school, Role::PARENT);

    $this->withinTenant($this->school, function () use ($student, $guardianUser): void {
        $this->service->recordForClass(
            $this->class, $this->today,
            [['student_id' => $student->id, 'status' => AttendanceStatus::Absent->value]],
        );

        $record = AttendanceRecord::query()->where('student_id', $student->id)->firstOrFail();

        $this->service->submitJustification($record, 'First', $guardianUser);
        $this->service->submitJustification($record, 'Second', $guardianUser);
    });
})->throws(DomainException::class);

it('refuses to justify a present mark', function (): void {
    $student = $this->students->first();
    $guardianUser = $this->makeUser($this->school, Role::PARENT);

    $this->withinTenant($this->school, function () use ($student, $guardianUser): void {
        $this->service->recordForClass(
            $this->class, $this->today,
            [['student_id' => $student->id, 'status' => AttendanceStatus::Present->value]],
        );

        $record = AttendanceRecord::query()->where('student_id', $student->id)->firstOrFail();

        $this->service->submitJustification($record, 'Why?', $guardianUser);
    });
})->throws(DomainException::class);

it('summarises attendance, counting a late arrival as attendance', function (): void {
    $student = $this->students->first();

    $this->withinTenant($this->school, function () use ($student): void {
        foreach ([
            [$this->today->subDays(4), AttendanceStatus::Present],
            [$this->today->subDays(3), AttendanceStatus::Present],
            [$this->today->subDays(2), AttendanceStatus::Late],
            [$this->today->subDay(), AttendanceStatus::Absent],
        ] as [$date, $status]) {
            $this->service->recordForClass(
                $this->class, $date,
                [['student_id' => $student->id, 'status' => $status->value]],
            );
        }
    });

    $summary = $this->withinTenant($this->school, fn () => $this->service->summaryForStudent(
        $student->id, $this->today->subDays(7), $this->today,
    ));

    expect($summary['present'])->toBe(2)
        ->and($summary['late'])->toBe(1)
        ->and($summary['absent'])->toBe(1)
        ->and($summary['total'])->toBe(4)
        // 3 of 4 days attended; being late is not being absent.
        ->and($summary['attendance_rate'])->toBe(75.0)
        ->and($summary['unjustified_absences'])->toBe(1);
});
