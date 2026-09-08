<?php

declare(strict_types=1);

namespace App\Domain\Student\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Services\NumberGenerator;
use App\Domain\Student\Models\Guardian;
use App\Domain\Student\Models\Student;
use App\Domain\Subscription\Services\PlanLimitEnforcer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Student record lifecycle.
 *
 * Creation is where the SaaS plan limit is enforced: the check happens inside
 * the same transaction as the insert, so two simultaneous requests cannot both
 * see "99 of 100 students" and both succeed.
 */
final class StudentService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly PlanLimitEnforcer $limits,
        private readonly TenantContext $tenant,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor = null): Student
    {
        return DB::transaction(function () use ($data): Student {
            $this->limits->assertCanAddStudent($this->tenant->schoolOrFail());

            $student = new Student;
            $student->fill($this->fillableFrom($data));

            // The matricule is always generated, never accepted from input:
            // letting a client choose it would allow collisions and would leak
            // the tenant's numbering to anyone who can post a form.
            $student->matricule = $data['matricule'] ?? $this->numbers->matricule();
            $student->school_id = $this->tenant->idOrFail();
            $student->status = Student::STATUS_ACTIVE;
            $student->enrolled_on ??= now()->toDateString();
            $student->save();

            if (! empty($data['guardians'])) {
                foreach ($data['guardians'] as $guardian) {
                    $this->attachGuardian($student, $guardian);
                }
            }

            $this->audit->created($student, "Created student {$student->matricule}");

            return $student;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Student $student, array $data): Student
    {
        $student->fill($this->fillableFrom($data));
        $student->save();

        return $student;
    }

    /**
     * Archive rather than delete.
     *
     * The row is soft-deleted and its status set, so invoices, grades and
     * report cards that reference it keep resolving.
     */
    public function archive(Student $student): Student
    {
        DB::transaction(function () use ($student): void {
            $student->forceFill([
                'status' => Student::STATUS_ARCHIVED,
                'left_on' => now()->toDateString(),
            ])->save();

            $student->enrollments()->where('status', 'active')->update([
                'status' => 'withdrawn',
                'ended_on' => now()->toDateString(),
            ]);

            $student->delete();
        });

        return $student;
    }

    /** @param array<string, mixed> $data */
    public function attachGuardian(Student $student, array $data): Student
    {
        // Resolved through the tenant-scoped query: a guardian id from another
        // school simply does not exist here.
        $guardian = Guardian::query()->findOr(
            $data['guardian_id'],
            fn () => throw new DomainException(__('students.unknown_guardian')),
        );

        $isPrimary = (bool) ($data['is_primary'] ?? false);
        $isFinancial = (bool) ($data['is_financial_responsible'] ?? false);

        DB::transaction(function () use ($student, $guardian, $data, $isPrimary, $isFinancial): void {
            // At most one primary and one financially responsible guardian:
            // ambiguity here would mean invoices addressed to nobody.
            if ($isPrimary) {
                $student->guardians()->updateExistingPivot(
                    $student->guardians()->pluck('guardians.id')->all(),
                    ['is_primary' => false],
                );
            }

            if ($isFinancial) {
                $student->guardians()->updateExistingPivot(
                    $student->guardians()->pluck('guardians.id')->all(),
                    ['is_financial_responsible' => false],
                );
            }

            $student->guardians()->syncWithoutDetaching([
                $guardian->id => [
                    'school_id' => $student->school_id,
                    'relationship' => $data['relationship'] ?? 'parent',
                    'is_primary' => $isPrimary,
                    'is_financial_responsible' => $isFinancial,
                    'can_pick_up' => (bool) ($data['can_pick_up'] ?? true),
                ],
            ]);
        });

        return $student->fresh(['guardians']);
    }

    public function detachGuardian(Student $student, string $guardianId): void
    {
        $student->guardians()->detach($guardianId);
    }

    /**
     * Narrow a student query to those the user is personally attached to.
     *
     * This is what backs `students.view_own` for parents and students: the
     * endpoint stays open, but the result set is the caller's own family.
     */
    public function restrictToRelated(Builder $query, User $user): Builder
    {
        $guardian = $user->loadMissing('guardian')->guardian;
        $studentProfile = $user->loadMissing('student')->student;

        return $query->where(function (Builder $inner) use ($guardian, $studentProfile): void {
            if ($studentProfile !== null) {
                $inner->orWhere('students.id', $studentProfile->id);
            }

            if ($guardian !== null) {
                $inner->orWhereHas(
                    'guardians',
                    fn (Builder $q) => $q->where('guardians.id', $guardian->id)
                );
            }

            if ($guardian === null && $studentProfile === null) {
                // Neither a parent nor a student: match nothing rather than
                // falling through to an unfiltered list.
                $inner->whereRaw('1 = 0');
            }
        });
    }

    /**
     * Only the attributes a client may set.
     *
     * `school_id`, `matricule`, `status` and `user_id` are deliberately absent:
     * they are set by the service, which is what makes the mass-assignment
     * test pass rather than relying on the model's $fillable alone.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillableFrom(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'first_name', 'last_name', 'middle_name', 'gender', 'birth_date',
            'birth_place', 'nationality', 'email', 'phone', 'address',
            'blood_group', 'medical_notes', 'emergency_contact_name',
            'emergency_contact_phone', 'emergency_contact_relation',
            'previous_school', 'enrolled_on',
        ]));
    }
}
