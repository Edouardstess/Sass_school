<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\AttendanceJustification;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Services\AttendanceService;
use App\Domain\Shared\Enums\AttendanceStatus;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $user = $this->user($request);

        $records = AttendanceRecord::query()
            ->with(['student:id,first_name,middle_name,last_name,matricule', 'schoolClass:id,name'])
            ->when($request->filled('class_id'), fn (Builder $q) => $q->where('school_class_id', $request->query('class_id')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', $request->query('student_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when(
                $request->filled('from') && $request->filled('to'),
                fn (Builder $q) => $q->between((string) $request->query('from'), (string) $request->query('to')),
            )
            ->when(! $user->hasPermission('attendance.view'), fn (Builder $q) => $q->whereIn(
                'student_id',
                $this->relatedStudentIds($user),
            ))
            ->orderByDesc('attendance_date')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($records, null);
    }

    /**
     * The register for one class on one day: every enrolled student with the
     * mark already recorded, if any. This is what the teacher's screen loads.
     */
    public function roster(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('view', $schoolClass);

        $date = CarbonImmutable::parse((string) $request->query('date', now()->toDateString()));

        $existing = AttendanceRecord::query()
            ->where('school_class_id', $schoolClass->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->whereNull('timetable_entry_id')
            ->get()
            ->keyBy('student_id');

        /** @var Collection<int, Student> $students */
        $students = $schoolClass->students()->orderBy('students.last_name')->get();

        return ApiResponse::success([
            'date' => $date->toDateString(),
            'class' => ['id' => $schoolClass->id, 'name' => $schoolClass->name],
            'students' => $students->map(function (Student $student) use ($existing): array {
                $record = $existing->get($student->id);

                return [
                    'student_id' => $student->id,
                    'matricule' => $student->matricule,
                    'full_name' => $student->full_name,
                    'status' => $record?->status->value,
                    'minutes_late' => $record?->minutes_late,
                    'remark' => $record?->remark,
                    'is_justified' => (bool) $record?->is_justified,
                    'record_id' => $record?->id,
                ];
            })->values()->all(),
        ]);
    }

    public function store(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('record', AttendanceRecord::class);
        $this->authorize('view', $schoolClass);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'timetable_entry_id' => ['nullable', 'uuid'],
            'entries' => ['required', 'array', 'min:1', 'max:200'],
            'entries.*.student_id' => ['required', 'uuid'],
            'entries.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'entries.*.minutes_late' => ['nullable', 'integer', 'min:1', 'max:600'],
            'entries.*.remark' => ['nullable', 'string', 'max:300'],
        ]);

        $result = $this->attendance->recordForClass(
            class: $schoolClass,
            date: CarbonImmutable::parse($data['date']),
            entries: $data['entries'],
            actor: $this->user($request),
            timetableEntryId: $data['timetable_entry_id'] ?? null,
        );

        return ApiResponse::success($result, __('attendance.recorded'));
    }

    /** A guardian submits an explanation for their own child's absence. */
    public function justify(Request $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        $this->authorize('justify', $attendanceRecord);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'document_id' => ['nullable', 'uuid'],
        ]);

        $justification = $this->attendance->submitJustification(
            $attendanceRecord,
            $data['reason'],
            $this->user($request),
            $data['document_id'] ?? null,
        );

        return ApiResponse::created([
            'id' => $justification->id,
            'status' => $justification->status,
        ], __('attendance.justification_submitted'));
    }

    public function reviewJustification(Request $request, AttendanceJustification $justification): JsonResponse
    {
        $this->authorize('approveJustification', $justification->attendanceRecord);

        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $reviewed = $this->attendance->reviewJustification(
            $justification,
            (bool) $data['approved'],
            $this->user($request),
            $data['note'] ?? null,
        );

        return ApiResponse::success([
            'id' => $reviewed->id,
            'status' => $reviewed->status,
        ], __('responses.updated'));
    }

    /** Attendance figures for one student over a window. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $data = $request->validate([
            'student_id' => ['required', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $record = AttendanceRecord::query()->where('student_id', $data['student_id'])->first();

        if ($record !== null) {
            $this->authorize('view', $record);
        }

        return ApiResponse::success($this->attendance->summaryForStudent(
            $data['student_id'],
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
        ));
    }

    /** @return list<string> */
    private function relatedStudentIds($user): array
    {
        $user->loadMissing(['guardian', 'student']);

        $ids = [];

        if ($user->student !== null) {
            $ids[] = $user->student->id;
        }

        if ($user->guardian !== null) {
            $ids = [...$ids, ...$user->guardian->students()->pluck('students.id')->all()];
        }

        return $ids;
    }
}
