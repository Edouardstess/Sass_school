<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Shared\Services\NumberGenerator;
use App\Domain\Teacher\Models\Teacher;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeacherResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Teacher::class);

        $teachers = Teacher::query()
            ->withCount('classSubjects')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('subject_id'), fn (Builder $q) => $q->whereHas(
                'classSubjects',
                fn (Builder $inner) => $inner->where('subject_id', $request->query('subject_id')),
            ))
            ->applySearch($request->query('search'), ['first_name', 'last_name', 'employee_number', 'email'])
            ->applySort($request->query('sort'), ['last_name', 'employee_number', 'hired_on', 'created_at'], 'last_name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($teachers, TeacherResource::class);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Teacher::class);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'qualification' => ['nullable', 'string', 'max:160'],
            'hired_on' => ['nullable', 'date'],
        ]);

        // The employee number is generated, never accepted from input.
        $data['employee_number'] = $this->numbers->next('matricule');
        $data['status'] = Teacher::STATUS_ACTIVE;

        $teacher = Teacher::query()->create($data);
        $this->audit->created($teacher, "Created teacher {$teacher->full_name}");

        return ApiResponse::created(new TeacherResource($teacher), __('responses.created'));
    }

    public function show(Teacher $teacher): JsonResponse
    {
        $this->authorize('view', $teacher);

        $teacher->load([
            'user:id,email,status',
            'classSubjects.subject:id,name',
            'classSubjects.schoolClass:id,name',
            'homeroomClasses:id,name,homeroom_teacher_id',
        ]);

        return ApiResponse::success(new TeacherResource($teacher));
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $this->authorize('update', $teacher);

        $before = $teacher->getOriginal();

        $teacher->fill($request->validate([
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'qualification' => ['nullable', 'string', 'max:160'],
            'hired_on' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([Teacher::STATUS_ACTIVE, Teacher::STATUS_ON_LEAVE, Teacher::STATUS_INACTIVE])],
        ]))->save();

        $this->audit->updated($teacher, $before);

        return ApiResponse::success(new TeacherResource($teacher), __('responses.updated'));
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $this->authorize('delete', $teacher);

        // Marks and timetable entries reference the teacher, so the record is
        // retired rather than removed.
        $teacher->forceFill(['status' => Teacher::STATUS_INACTIVE])->save();
        $teacher->delete();
        $this->audit->deleted($teacher);

        return ApiResponse::noContent();
    }

    /** The teacher's weekly timetable — what they see on signing in. */
    public function schedule(Request $request, Teacher $teacher): JsonResponse
    {
        $this->authorize('view', $teacher);

        $entries = $teacher->timetableEntries()
            ->with(['subject:id,name,color', 'schoolClass:id,name', 'room:id,name'])
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();

        return ApiResponse::success($entries->map(fn ($entry): array => [
            'id' => $entry->id,
            'day_of_week' => $entry->day_of_week,
            'starts_at' => substr((string) $entry->starts_at, 0, 5),
            'ends_at' => substr((string) $entry->ends_at, 0, 5),
            'subject' => ['id' => $entry->subject?->id, 'name' => $entry->subject?->name, 'color' => $entry->subject?->color],
            'class' => ['id' => $entry->schoolClass?->id, 'name' => $entry->schoolClass?->name],
            'room' => $entry->room === null ? null : ['id' => $entry->room->id, 'name' => $entry->room->name],
        ])->all());
    }
}
