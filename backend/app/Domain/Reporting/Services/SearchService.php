<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Student\Models\Guardian;
use App\Domain\Student\Models\Student;
use App\Domain\Student\Services\StudentService;
use App\Domain\Teacher\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;

/**
 * Global search across the tenant.
 *
 * Every query runs through the tenant-scoped model, so results can only ever
 * come from the caller's own school, and each section is skipped unless the
 * caller holds the permission for that module — a parent searching finds their
 * own children, not the staff directory.
 *
 * Matching leans on the pg_trgm GIN indexes created in the schema migration,
 * so `ILIKE '%term%'` stays index-assisted rather than scanning the table.
 */
final class SearchService
{
    private const PER_SECTION = 5;

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function search(User $user, string $term, int $limit = self::PER_SECTION): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            // A single character would match most of the tenant and is never
            // a useful search; refusing it also removes a cheap DoS.
            return [];
        }

        $results = [];

        if ($user->hasAnyPermission(['students.view', 'students.view_own'])) {
            $results['students'] = $this->students($user, $term, $limit);
        }

        if ($user->hasPermission('teachers.view')) {
            $results['teachers'] = Teacher::query()
                ->applySearch($term, ['first_name', 'last_name', 'employee_number', 'email'])
                ->limit($limit)->get()
                ->map(fn (Teacher $t): array => [
                    'id' => $t->id,
                    'label' => $t->full_name,
                    'sublabel' => $t->employee_number,
                    'type' => 'teacher',
                ])->all();
        }

        if ($user->hasPermission('guardians.view')) {
            $results['guardians'] = Guardian::query()
                ->applySearch($term, ['first_name', 'last_name', 'email', 'phone'])
                ->limit($limit)->get()
                ->map(fn (Guardian $g): array => [
                    'id' => $g->id,
                    'label' => $g->full_name,
                    'sublabel' => $g->phone,
                    'type' => 'guardian',
                ])->all();
        }

        if ($user->hasPermission('classes.view')) {
            $results['classes'] = SchoolClass::query()
                ->applySearch($term, ['name'])
                ->with('level:id,name')
                ->limit($limit)->get()
                ->map(fn (SchoolClass $c): array => [
                    'id' => $c->id,
                    'label' => $c->name,
                    'sublabel' => $c->level?->name,
                    'type' => 'class',
                ])->all();
        }

        if ($user->hasAnyPermission(['invoices.view', 'finance.view'])) {
            $results['invoices'] = Invoice::query()
                ->applySearch($term, ['number'])
                ->with('student:id,first_name,last_name')
                ->limit($limit)->get()
                ->map(fn (Invoice $i): array => [
                    'id' => $i->id,
                    'label' => $i->number,
                    'sublabel' => $i->student?->full_name,
                    'meta' => $i->balance()->format(),
                    'type' => 'invoice',
                ])->all();

            $results['payments'] = Payment::query()
                ->applySearch($term, ['reference', 'external_reference', 'payer_name'])
                ->limit($limit)->get()
                ->map(fn (Payment $p): array => [
                    'id' => $p->id,
                    'label' => $p->reference,
                    'sublabel' => $p->payer_name,
                    'meta' => $p->amount()->format(),
                    'type' => 'payment',
                ])->all();
        }

        return array_filter($results, static fn (array $section): bool => $section !== []);
    }

    /** @return list<array<string, mixed>> */
    private function students(User $user, string $term, int $limit): array
    {
        $query = Student::query()
            ->applySearch($term, ['first_name', 'last_name', 'matricule', 'email'])
            ->with('enrollments.schoolClass:id,name');

        // A parent searching sees their own children only.
        if (! $user->hasPermission('students.view')) {
            $query = app(StudentService::class)->restrictToRelated($query, $user);
        }

        /** @var Collection<int, Student> $found */
        $found = $query->limit($limit)->get();

        return $found
            ->map(fn (Student $s): array => [
                'id' => $s->id,
                'label' => $s->full_name,
                'sublabel' => $s->matricule,
                'meta' => $s->enrollments->first()?->schoolClass?->name,
                'type' => 'student',
            ])->all();
    }
}
