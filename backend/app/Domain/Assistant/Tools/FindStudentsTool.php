<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Contracts\AssistantTool;
use App\Domain\Identity\Models\User;
use App\Domain\Student\Models\Student;

/**
 * "Combien d'élèves en 6ème A ?" / "Trouve les élèves nommés Alexis."
 *
 * Filters are named parameters with fixed semantics, never a query fragment.
 */
final class FindStudentsTool implements AssistantTool
{
    private const MAX_RESULTS = 50;

    public function name(): string
    {
        return 'find_students';
    }

    public function description(): string
    {
        return 'Search the school\'s students by name, matricule, class or status. '
            .'Returns a count and up to 50 matching students. Use this to answer '
            .'questions about who is enrolled, how many students a class has, or to '
            .'locate a specific pupil.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Part of the student\'s first or last name.'],
                'class_name' => ['type' => 'string', 'description' => 'Exact class name, e.g. "6ème A".'],
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'graduated', 'transferred', 'withdrawn', 'archived'],
                ],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RESULTS],
            ],
            'required' => [],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasPermission('students.view');
    }

    public function execute(User $user, array $arguments): array
    {
        $limit = min((int) ($arguments['limit'] ?? 20), self::MAX_RESULTS);

        // Every clause below is a bound parameter on a named column. The model
        // supplies values, never structure.
        $query = Student::query()
            ->when(! empty($arguments['name']), fn ($q) => $q->applySearch(
                (string) $arguments['name'],
                ['first_name', 'last_name', 'matricule'],
            ))
            ->when(! empty($arguments['status']), fn ($q) => $q->where('status', (string) $arguments['status']))
            ->when(! empty($arguments['class_name']), fn ($q) => $q->whereHas(
                'enrollments.schoolClass',
                fn ($inner) => $inner->where('school_classes.name', (string) $arguments['class_name']),
            ));

        $total = (clone $query)->count();

        $students = $query
            ->with('enrollments.schoolClass:id,name')
            ->orderBy('last_name')
            ->limit($limit)
            ->get();

        return [
            'total' => $total,
            'returned' => $students->count(),
            'students' => $students->map(fn (Student $s): array => [
                'matricule' => $s->matricule,
                'name' => $s->full_name,
                'class' => $s->enrollments->first()?->schoolClass?->name,
                'status' => $s->status,
            ])->all(),
        ];
    }
}
