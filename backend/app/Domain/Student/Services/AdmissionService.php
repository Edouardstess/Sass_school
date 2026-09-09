<?php

declare(strict_types=1);

namespace App\Domain\Student\Services;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Services\EnrollmentService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\ApplicationStatus;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Services\NumberGenerator;
use App\Domain\Student\Models\AdmissionApplication;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Student\Models\Guardian;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The admissions pipeline.
 *
 *   submitted → under_review → accepted → enrolled
 *                            ↘ rejected
 *
 * The interesting step is conversion: accepting an application creates a real
 * student, a guardian and an enrolment in one transaction, and records the
 * link back on the application so a second attempt is refused rather than
 * producing a duplicate pupil.
 */
final class AdmissionService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StudentService $students,
        private readonly EnrollmentService $enrollments,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Accept a public application. Runs with no authenticated user, so the
     * tenant is resolved from the school being applied to, not from a session.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(School $school, array $data): AdmissionApplication
    {
        return $this->tenant->runFor($school, function () use ($school, $data): AdmissionApplication {
            $year = AcademicYear::query()
                ->whereIn('status', [AcademicYear::STATUS_ACTIVE, AcademicYear::STATUS_DRAFT])
                ->orderByDesc('starts_on')
                ->first();

            if ($year === null) {
                throw new DomainException(__('admissions.no_open_year'));
            }

            $application = new AdmissionApplication;
            $application->forceFill([
                ...$data,
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
                'reference' => $this->numbers->next('application', null, $school->id),
                'status' => ApplicationStatus::Submitted->value,
                'submitted_at' => now(),
            ])->save();

            $this->audit->log(AuditAction::Create, $application, [
                'school_id' => $school->id,
                'description' => "Admission application {$application->reference} submitted",
            ]);

            return $application;
        });
    }

    public function decide(
        AdmissionApplication $application,
        ApplicationStatus $decision,
        User $reviewer,
        ?string $reason = null,
    ): AdmissionApplication {
        if (! $application->status->canTransitionTo($decision)) {
            throw new DomainException(__('admissions.invalid_transition', [
                'from' => $application->status->value,
                'to' => $decision->value,
            ]));
        }

        $application->forceFill([
            'status' => $decision->value,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'decision_reason' => $reason,
        ])->save();

        $this->audit->log(AuditAction::Update, $application, [
            'description' => "Application {$application->reference} → {$decision->value}",
            'metadata' => ['reason' => $reason],
        ]);

        return $application;
    }

    /**
     * Turn an accepted application into a student, a guardian and an
     * enrolment. Idempotent: an already-converted application is refused.
     */
    public function convertToStudent(
        AdmissionApplication $application,
        SchoolClass $class,
        User $actor,
    ): Student {
        if ($application->isConverted()) {
            throw new DomainException(__('admissions.already_converted', [
                'reference' => $application->reference,
            ]));
        }

        if ($application->status !== ApplicationStatus::Accepted) {
            throw new DomainException(__('admissions.must_be_accepted'));
        }

        return DB::transaction(function () use ($application, $class, $actor): Student {
            $student = $this->students->create([
                'first_name' => $application->first_name,
                'last_name' => $application->last_name,
                'middle_name' => $application->middle_name,
                'gender' => $application->gender,
                'birth_date' => $application->birth_date?->toDateString(),
                'birth_place' => $application->birth_place,
                'nationality' => $application->nationality,
                'address' => $application->address,
                'previous_school' => $application->previous_school,
                'emergency_contact_name' => trim($application->guardian_first_name.' '.$application->guardian_last_name),
                'emergency_contact_phone' => $application->guardian_phone,
                'emergency_contact_relation' => $application->guardian_relationship,
            ], $actor);

            // Reuse an existing guardian when the phone number matches, so a
            // second sibling does not create a duplicate parent record.
            $guardian = Guardian::query()
                ->where('phone', $application->guardian_phone)
                ->first();

            $guardian ??= Guardian::query()->create([
                'school_id' => $application->school_id,
                'first_name' => $application->guardian_first_name,
                'last_name' => $application->guardian_last_name,
                'email' => $application->guardian_email,
                'phone' => $application->guardian_phone,
            ]);

            $this->students->attachGuardian($student, [
                'guardian_id' => $guardian->id,
                'relationship' => $application->guardian_relationship,
                'is_primary' => true,
                'is_financial_responsible' => true,
            ]);

            $this->enrollments->enroll(
                student: $student,
                class: $class,
                type: Enrollment::TYPE_NEW,
                notes: "From admission application {$application->reference}",
            );

            $application->forceFill([
                'status' => ApplicationStatus::Enrolled->value,
                'student_id' => $student->id,
                'assigned_class_id' => $class->id,
            ])->save();

            $this->audit->log(AuditAction::Update, $application, [
                'description' => "Application {$application->reference} converted to student {$student->matricule}",
            ]);

            return $student;
        });
    }

    /**
     * Public status lookup by reference — how an applicant follows their file
     * without an account. Returns a status and nothing else.
     *
     * @return array<string, mixed>|null
     */
    public function publicStatus(School $school, string $reference): ?array
    {
        return $this->tenant->runFor($school, function () use ($reference): ?array {
            $application = AdmissionApplication::query()
                ->where('reference', strtoupper(trim($reference)))
                ->first();

            if ($application === null) {
                return null;
            }

            return [
                'reference' => $application->reference,
                'status' => $application->status->value,
                'submitted_at' => $application->submitted_at?->toDateString(),
                'reviewed_at' => $application->reviewed_at?->toDateString(),
                // Deliberately no decision reason and no personal data: the
                // reference travels by e-mail and is not a secret.
                'is_open' => $application->status->isOpen(),
            ];
        });
    }
}
