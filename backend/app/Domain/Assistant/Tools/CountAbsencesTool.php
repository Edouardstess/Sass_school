<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Contracts\AssistantTool;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AttendanceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** "Quels élèves ont plus de 10 absences ?" */
final class CountAbsencesTool implements AssistantTool
{
    public function name(): string
    {
        return 'count_absences';
    }

    public function description(): string
    {
        return 'Count absences per student over a date range, optionally filtered to a '
            .'class and to students above a threshold. Use this for questions about '
            .'attendance problems, e.g. "which students have more than 10 absences".';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD. Defaults to 90 days ago.'],
                'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD. Defaults to today.'],
                'class_name' => ['type' => 'string'],
                'minimum_absences' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'unjustified_only' => ['type' => 'boolean', 'default' => false],
            ],
            'required' => [],
        ];
    }

    public function authorize(User $user): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function execute(User $user, array $arguments): array
    {
        // Dates are parsed rather than interpolated, so a malformed value
        // throws instead of reaching the query.
        $from = CarbonImmutable::parse((string) ($arguments['from'] ?? CarbonImmutable::now()->subDays(90)->toDateString()));
        $to = CarbonImmutable::parse((string) ($arguments['to'] ?? CarbonImmutable::now()->toDateString()));
        $threshold = max(1, (int) ($arguments['minimum_absences'] ?? 1));

        $rows = AttendanceRecord::query()
            ->join('students', 'attendance_records.student_id', '=', 'students.id')
            ->leftJoin('school_classes', 'attendance_records.school_class_id', '=', 'school_classes.id')
            ->where('attendance_records.status', AttendanceStatus::Absent->value)
            ->whereBetween('attendance_records.attendance_date', [$from->toDateString(), $to->toDateString()])
            ->when(! empty($arguments['unjustified_only']), fn ($q) => $q->where('attendance_records.is_justified', false))
            ->when(! empty($arguments['class_name']), fn ($q) => $q->where('school_classes.name', (string) $arguments['class_name']))
            ->groupBy('students.id', 'students.first_name', 'students.last_name', 'students.matricule', 'school_classes.name')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->orderByRaw('count(*) desc')
            ->limit(50)
            ->toBase()
            ->get([
                'students.matricule',
                'students.first_name',
                'students.last_name',
                'school_classes.name as class_name',
                DB::raw('count(*) as absences'),
            ]);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'threshold' => $threshold,
            'matching_students' => $rows->count(),
            'students' => $rows->map(fn ($row): array => [
                'matricule' => $row->matricule,
                'name' => trim($row->first_name.' '.$row->last_name),
                'class' => $row->class_name,
                'absences' => (int) $row->absences,
            ])->all(),
        ];
    }
}
