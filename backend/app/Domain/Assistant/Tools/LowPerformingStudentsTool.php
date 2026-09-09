<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Academic\Models\ReportCard;
use App\Domain\Assistant\Contracts\AssistantTool;
use App\Domain\Identity\Models\User;

/** "Montre-moi les élèves dont la moyenne est inférieure à 50." */
final class LowPerformingStudentsTool implements AssistantTool
{
    public function name(): string
    {
        return 'students_below_average';
    }

    public function description(): string
    {
        return 'List students whose computed report-card average falls below a '
            .'threshold, optionally for one class or grading period. Only computed '
            .'report cards are considered. Use this for questions about academic '
            .'difficulty or students needing support.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'threshold' => ['type' => 'number', 'description' => 'Average below which a student is listed.'],
                'class_name' => ['type' => 'string'],
                'period_name' => ['type' => 'string', 'description' => 'Grading period, e.g. "Trimestre 1".'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            ],
            'required' => ['threshold'],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasAnyPermission(['report_cards.view', 'grades.view']);
    }

    public function execute(User $user, array $arguments): array
    {
        $threshold = (float) $arguments['threshold'];
        $limit = min((int) ($arguments['limit'] ?? 20), 50);

        $cards = ReportCard::query()
            ->whereNotNull('average')
            ->where('average', '<', $threshold)
            ->when(! empty($arguments['class_name']), fn ($q) => $q->whereHas(
                'schoolClass',
                fn ($inner) => $inner->where('name', (string) $arguments['class_name']),
            ))
            ->when(! empty($arguments['period_name']), fn ($q) => $q->whereHas(
                'gradePeriod',
                fn ($inner) => $inner->where('name', (string) $arguments['period_name']),
            ))
            ->with(['student:id,first_name,last_name,matricule', 'schoolClass:id,name', 'gradePeriod:id,name'])
            ->orderBy('average')
            ->limit($limit)
            ->get();

        return [
            'threshold' => $threshold,
            'matching_students' => $cards->count(),
            'students' => $cards->map(fn (ReportCard $card): array => [
                'matricule' => $card->student?->matricule,
                'name' => $card->student?->full_name,
                'class' => $card->schoolClass?->name,
                'period' => $card->gradePeriod?->name,
                'average' => $card->average === null ? null : (float) $card->average,
                'rank' => $card->rank,
                'class_size' => $card->class_size,
            ])->all(),
        ];
    }
}
