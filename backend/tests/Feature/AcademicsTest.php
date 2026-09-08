<?php

declare(strict_types=1);

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Models\GradePeriod;
use App\Domain\Academic\Models\Level;
use App\Domain\Academic\Models\ReportCard;
use App\Domain\Academic\Models\Room;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TimetableEntry;
use App\Domain\Academic\Services\EnrollmentService;
use App\Domain\Academic\Services\GradeService;
use App\Domain\Academic\Services\ReportCardService;
use App\Domain\Academic\Services\TimetableConflictDetector;
use App\Domain\Identity\Models\Role;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\AssessmentType;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Student\Models\Student;
use App\Domain\Teacher\Models\Teacher;

beforeEach(function (): void {
    $this->school = $this->makeSchool();
    $this->year = AcademicYear::query()->where('school_id', $this->school->id)->firstOrFail();

    $this->withinTenant($this->school, function (): void {
        $this->level = Level::query()->create([
            'school_id' => $this->school->id, 'name' => '6ème', 'sequence' => 1,
        ]);

        $this->room = Room::query()->create([
            'school_id' => $this->school->id, 'name' => 'Salle 1', 'capacity' => 30,
        ]);

        $this->teacher = Teacher::query()->create([
            'school_id' => $this->school->id,
            'employee_number' => 'EMP-0001',
            'first_name' => 'Marie', 'last_name' => 'Joseph',
            'status' => Teacher::STATUS_ACTIVE,
        ]);

        $this->maths = Subject::query()->create([
            'school_id' => $this->school->id, 'name' => 'Mathématiques', 'default_coefficient' => 4,
        ]);

        $this->classA = SchoolClass::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $this->level->id,
            'name' => '6ème A', 'capacity' => 2,
        ]);

        $this->classB = SchoolClass::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'level_id' => $this->level->id,
            'name' => '6ème B', 'capacity' => 30,
        ]);

        $this->assignment = ClassSubject::query()->create([
            'school_id' => $this->school->id,
            'school_class_id' => $this->classA->id,
            'subject_id' => $this->maths->id,
            'teacher_id' => $this->teacher->id,
            'coefficient' => 4,
        ]);

        $this->period = GradePeriod::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'name' => 'Trimestre 1', 'sequence' => 1,
            'starts_on' => $this->year->starts_on,
            'ends_on' => $this->year->starts_on->addMonths(3),
        ]);
    });
});

// ----------------------------------------------------------------- timetable

it('accepts a free timetable slot', function (): void {
    $this->withinTenant($this->school, function (): void {
        app(TimetableConflictDetector::class)->assertFree(
            academicYearId: $this->year->id,
            dayOfWeek: 1, startsAt: '08:00', endsAt: '09:30',
            schoolClassId: $this->classA->id,
            teacherId: $this->teacher->id,
            roomId: $this->room->id,
        );
    });
})->throwsNoExceptions();

it('detects a teacher double-booked across two classes', function (): void {
    $this->withinTenant($this->school, function (): void {
        TimetableEntry::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classA->id,
            'subject_id' => $this->maths->id,
            'teacher_id' => $this->teacher->id,
            'room_id' => $this->room->id,
            'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:30',
        ]);

        $conflicts = app(TimetableConflictDetector::class)->detect(
            academicYearId: $this->year->id,
            dayOfWeek: 1, startsAt: '09:00', endsAt: '10:00',
            schoolClassId: $this->classB->id,   // a different class…
            teacherId: $this->teacher->id,      // …but the same teacher
        );

        expect($conflicts)->not->toBeEmpty()
            ->and(array_column($conflicts, 'type'))->toContain('teacher');
    });
});

it('detects a room double-booked', function (): void {
    $this->withinTenant($this->school, function (): void {
        TimetableEntry::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classA->id,
            'subject_id' => $this->maths->id,
            'room_id' => $this->room->id,
            'day_of_week' => 2, 'starts_at' => '10:00', 'ends_at' => '11:30',
        ]);

        $conflicts = app(TimetableConflictDetector::class)->detect(
            academicYearId: $this->year->id,
            dayOfWeek: 2, startsAt: '11:00', endsAt: '12:00',
            schoolClassId: $this->classB->id,
            roomId: $this->room->id,
        );

        expect(array_column($conflicts, 'type'))->toContain('room');
    });
});

it('treats back-to-back lessons as non-conflicting', function (): void {
    $this->withinTenant($this->school, function (): void {
        TimetableEntry::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classA->id,
            'subject_id' => $this->maths->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 3, 'starts_at' => '08:00', 'ends_at' => '09:30',
        ]);

        // A lesson starting exactly when the previous one ends is legitimate.
        $conflicts = app(TimetableConflictDetector::class)->detect(
            academicYearId: $this->year->id,
            dayOfWeek: 3, startsAt: '09:30', endsAt: '11:00',
            schoolClassId: $this->classA->id,
            teacherId: $this->teacher->id,
        );

        expect($conflicts)->toBeEmpty();
    });
});

it('does not report an entry as conflicting with itself when edited', function (): void {
    $this->withinTenant($this->school, function (): void {
        $entry = TimetableEntry::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'school_class_id' => $this->classA->id,
            'subject_id' => $this->maths->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 4, 'starts_at' => '08:00', 'ends_at' => '09:30',
        ]);

        $conflicts = app(TimetableConflictDetector::class)->detect(
            academicYearId: $this->year->id,
            dayOfWeek: 4, startsAt: '08:00', endsAt: '10:00',
            schoolClassId: $this->classA->id,
            teacherId: $this->teacher->id,
            ignoreEntryId: $entry->id,
        );

        expect($conflicts)->toBeEmpty();
    });
});

it('rejects a slot that ends before it starts', function (): void {
    $this->withinTenant($this->school, fn () => app(TimetableConflictDetector::class)->detect(
        academicYearId: $this->year->id,
        dayOfWeek: 1, startsAt: '10:00', endsAt: '09:00',
        schoolClassId: $this->classA->id,
    ));
})->throws(DomainException::class);

// ---------------------------------------------------------------- enrolment

it('enrols a student into a class', function (): void {
    $enrollment = $this->withinTenant($this->school, function () {
        $student = Student::factory()->create(['school_id' => $this->school->id]);

        return app(EnrollmentService::class)->enroll($student, $this->classA);
    });

    expect($enrollment->school_class_id)->toBe($this->classA->id)
        ->and($enrollment->academic_year_id)->toBe($this->year->id);
});

it('refuses to enrol the same student twice in one year', function (): void {
    $this->withinTenant($this->school, function (): void {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        $service = app(EnrollmentService::class);

        $service->enroll($student, $this->classA);
        $service->enroll($student, $this->classB);
    });
})->throws(DomainException::class);

it('refuses to enrol beyond a class capacity', function (): void {
    $this->withinTenant($this->school, function (): void {
        $service = app(EnrollmentService::class);

        // classA has capacity 2.
        foreach (range(1, 3) as $ignored) {
            $service->enroll(
                Student::factory()->create(['school_id' => $this->school->id]),
                $this->classA->fresh(),
            );
        }
    });
})->throws(DomainException::class);

// ------------------------------------------------------------------- grades

it('records a mark and refuses one above the assessment scale', function (): void {
    $this->withinTenant($this->school, function (): void {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        app(EnrollmentService::class)->enroll($student, $this->classA);

        $assessment = Assessment::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'grade_period_id' => $this->period->id,
            'class_subject_id' => $this->assignment->id,
            'title' => 'Contrôle 1',
            'type' => AssessmentType::Quiz->value,
            'max_score' => 20,
            'weight' => 1,
            'assessed_on' => now()->toDateString(),
            'status' => Assessment::STATUS_PUBLISHED,
        ]);

        $grades = app(GradeService::class);

        $grade = $grades->record($assessment, $student->id, 17.5);
        expect((float) $grade->score)->toBe(17.5);

        expect(fn () => $grades->record($assessment, $student->id, 21))
            ->toThrow(DomainException::class);
    });
});

it('writes a revision whenever a mark is amended', function (): void {
    $this->withinTenant($this->school, function (): void {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        app(EnrollmentService::class)->enroll($student, $this->classA);
        $actor = $this->makeUser($this->school, Role::PRINCIPAL);

        $assessment = Assessment::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'grade_period_id' => $this->period->id,
            'class_subject_id' => $this->assignment->id,
            'title' => 'Examen',
            'type' => AssessmentType::Exam->value,
            'max_score' => 100, 'weight' => 3,
            'assessed_on' => now()->toDateString(),
            'status' => Assessment::STATUS_PUBLISHED,
        ]);

        $grades = app(GradeService::class);
        $grade = $grades->record($assessment, $student->id, 60);
        $grades->record($assessment, $student->id, 75, actor: $actor, reason: 'Marking error');

        $revisions = $grade->fresh()->revisions;

        expect($revisions)->toHaveCount(1)
            ->and((float) $revisions->first()->old_score)->toBe(60.0)
            ->and((float) $revisions->first()->new_score)->toBe(75.0)
            ->and($revisions->first()->reason)->toBe('Marking error');
    });
});

it('refuses marks once the assessment is locked', function (): void {
    $this->withinTenant($this->school, function (): void {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        app(EnrollmentService::class)->enroll($student, $this->classA);
        $actor = $this->makeUser($this->school, Role::PRINCIPAL);

        $assessment = Assessment::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'grade_period_id' => $this->period->id,
            'class_subject_id' => $this->assignment->id,
            'title' => 'Projet', 'type' => AssessmentType::Project->value,
            'max_score' => 100, 'weight' => 2,
            'assessed_on' => now()->toDateString(),
            'status' => Assessment::STATUS_PUBLISHED,
        ]);

        $grades = app(GradeService::class);
        $grades->record($assessment, $student->id, 80);
        $grades->lock($assessment, $actor);

        $grades->record($assessment->fresh(), $student->id, 95);
    });
})->throws(DomainException::class);

it('refuses marks once the grading period is locked', function (): void {
    $this->withinTenant($this->school, function (): void {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        app(EnrollmentService::class)->enroll($student, $this->classA);

        $assessment = Assessment::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'grade_period_id' => $this->period->id,
            'class_subject_id' => $this->assignment->id,
            'title' => 'Devoir', 'type' => AssessmentType::Homework->value,
            'max_score' => 20, 'weight' => 1,
            'assessed_on' => now()->toDateString(),
            'status' => Assessment::STATUS_PUBLISHED,
        ]);

        $this->period->forceFill(['is_locked' => true, 'locked_at' => now()])->save();

        app(GradeService::class)->record($assessment->fresh(), $student->id, 15);
    });
})->throws(DomainException::class);

// -------------------------------------------------------------- report card

it('computes report cards with rank, class average and per-subject lines', function (): void {
    $result = $this->withinTenant($this->school, function () {
        $grades = app(GradeService::class);
        $enrol = app(EnrollmentService::class);

        // The assessment must hang off a subject taught to the class the
        // students are actually in; a card is computed from the class's own
        // class_subjects rows.
        $assignmentB = ClassSubject::query()->create([
            'school_id' => $this->school->id,
            'school_class_id' => $this->classB->id,
            'subject_id' => $this->maths->id,
            'teacher_id' => $this->teacher->id,
            'coefficient' => 4,
        ]);

        $assessment = Assessment::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->year->id,
            'grade_period_id' => $this->period->id,
            'class_subject_id' => $assignmentB->id,
            'title' => 'Examen', 'type' => AssessmentType::Exam->value,
            'max_score' => 100, 'weight' => 1,
            'assessed_on' => now()->toDateString(),
            'status' => Assessment::STATUS_PUBLISHED,
        ]);

        foreach ([90, 70, 50] as $index => $score) {
            $student = Student::factory()->create(['school_id' => $this->school->id]);
            $enrol->enroll($student, $this->classB);
            $grades->record($assessment, $student->id, (float) $score);
        }

        return app(ReportCardService::class)->computeForClass($this->classB, $this->period);
    });

    expect($result['class_size'])->toBe(3)
        ->and($result['computed'])->toBe(3)
        // (90 + 70 + 50) / 3
        ->and($result['class_average'])->toBe(70.0);

    $cards = ReportCard::query()
        ->withoutTenantScope()
        ->where('school_class_id', $this->classB->id)
        ->with('lines')
        ->orderBy('rank')
        ->get();

    expect($cards)->toHaveCount(3)
        ->and($cards->pluck('rank')->all())->toBe([1, 2, 3])
        ->and((float) $cards->first()->average)->toBe(90.0)
        ->and($cards->first()->class_size)->toBe(3)
        ->and($cards->first()->lines)->toHaveCount(1);

    $line = $cards->first()->lines->first();

    expect($line->subject_name)->toBe('Mathématiques')
        ->and((float) $line->coefficient)->toBe(4.0)
        ->and((float) $line->min_score)->toBe(50.0)
        ->and((float) $line->max_score)->toBe(90.0)
        ->and($line->appreciation)->toBe('Excellent');
});
