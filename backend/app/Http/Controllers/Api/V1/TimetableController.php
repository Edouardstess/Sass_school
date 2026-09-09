<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\TimetableEntry;
use App\Domain\Academic\Services\TimetableConflictDetector;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\School\Models\AcademicYear;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The weekly timetable.
 *
 * Writes go through TimetableConflictDetector, which refuses a slot that would
 * double-book a teacher, a room or a class, and reports every clash at once so
 * the user can fix them in one pass.
 */
class TimetableController extends Controller
{
    public function __construct(
        private readonly TimetableConflictDetector $conflicts,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolClass::class);

        $yearId = $request->query('academic_year_id') ?? AcademicYear::query()->active()->value('id');

        $entries = TimetableEntry::query()
            ->with(['subject:id,name,color', 'schoolClass:id,name', 'teacher:id,first_name,last_name', 'room:id,name'])
            ->when($yearId !== null, fn (Builder $q) => $q->where('academic_year_id', $yearId))
            ->when($request->filled('class_id'), fn (Builder $q) => $q->where('school_class_id', $request->query('class_id')))
            ->when($request->filled('teacher_id'), fn (Builder $q) => $q->where('teacher_id', $request->query('teacher_id')))
            ->when($request->filled('room_id'), fn (Builder $q) => $q->where('room_id', $request->query('room_id')))
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();

        return ApiResponse::success($entries->map(fn (TimetableEntry $e): array => $this->present($e))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SchoolClass::class);

        $data = $this->validatePayload($request);

        $this->conflicts->assertFree(
            academicYearId: $data['academic_year_id'],
            dayOfWeek: (int) $data['day_of_week'],
            startsAt: $data['starts_at'],
            endsAt: $data['ends_at'],
            schoolClassId: $data['school_class_id'],
            teacherId: $data['teacher_id'] ?? null,
            roomId: $data['room_id'] ?? null,
        );

        $entry = TimetableEntry::query()->create($data);
        $this->audit->created($entry, 'Timetable slot created');

        return ApiResponse::created(
            $this->present($entry->load(['subject', 'schoolClass', 'teacher', 'room'])),
            __('responses.created'),
        );
    }

    public function update(Request $request, TimetableEntry $timetableEntry): JsonResponse
    {
        $this->authorize('update', $timetableEntry->schoolClass ?? SchoolClass::query()->findOrFail($timetableEntry->school_class_id));

        $data = $this->validatePayload($request);

        $this->conflicts->assertFree(
            academicYearId: $data['academic_year_id'],
            dayOfWeek: (int) $data['day_of_week'],
            startsAt: $data['starts_at'],
            endsAt: $data['ends_at'],
            schoolClassId: $data['school_class_id'],
            teacherId: $data['teacher_id'] ?? null,
            roomId: $data['room_id'] ?? null,
            // Otherwise the entry being edited would clash with itself.
            ignoreEntryId: $timetableEntry->id,
        );

        $before = $timetableEntry->getOriginal();
        $timetableEntry->fill($data)->save();
        $this->audit->updated($timetableEntry, $before);

        return ApiResponse::success(
            $this->present($timetableEntry->load(['subject', 'schoolClass', 'teacher', 'room'])),
            __('responses.updated'),
        );
    }

    public function destroy(TimetableEntry $timetableEntry): JsonResponse
    {
        $this->authorize('delete', $timetableEntry->schoolClass ?? SchoolClass::query()->findOrFail($timetableEntry->school_class_id));

        $timetableEntry->delete();
        $this->audit->deleted($timetableEntry);

        return ApiResponse::noContent();
    }

    /**
     * Dry-run a slot without saving it, so the UI can warn while the user is
     * still filling the form rather than only on submit.
     */
    public function checkConflicts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolClass::class);

        $data = $this->validatePayload($request);

        $conflicts = $this->conflicts->detect(
            academicYearId: $data['academic_year_id'],
            dayOfWeek: (int) $data['day_of_week'],
            startsAt: $data['starts_at'],
            endsAt: $data['ends_at'],
            schoolClassId: $data['school_class_id'],
            teacherId: $data['teacher_id'] ?? null,
            roomId: $data['room_id'] ?? null,
            ignoreEntryId: $request->query('ignore_entry_id'),
        );

        return ApiResponse::success(['available' => $conflicts === [], 'conflicts' => $conflicts]);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'uuid'],
            'school_class_id' => ['required', 'uuid'],
            'subject_id' => ['required', 'uuid'],
            'teacher_id' => ['nullable', 'uuid'],
            'room_id' => ['nullable', 'uuid'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ]);

        // Normalise to the H:i:s the time column stores, so string comparison
        // in the overlap query is consistent.
        $data['starts_at'] .= ':00';
        $data['ends_at'] .= ':00';

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(TimetableEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'day_of_week' => $entry->day_of_week,
            'starts_at' => substr((string) $entry->starts_at, 0, 5),
            'ends_at' => substr((string) $entry->ends_at, 0, 5),
            'class' => ['id' => $entry->schoolClass?->id, 'name' => $entry->schoolClass?->name],
            'subject' => [
                'id' => $entry->subject?->id,
                'name' => $entry->subject?->name,
                'color' => $entry->subject?->color,
            ],
            'teacher' => $entry->teacher === null ? null : [
                'id' => $entry->teacher->id, 'full_name' => $entry->teacher->full_name,
            ],
            'room' => $entry->room === null ? null : ['id' => $entry->room->id, 'name' => $entry->room->name],
        ];
    }
}
