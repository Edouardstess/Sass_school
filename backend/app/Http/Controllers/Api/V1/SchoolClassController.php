<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SchoolClassResource;
use App\Http\Resources\StudentResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolClassController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolClass::class);

        // Default to the active year: a teacher opening the class list wants
        // this year's classes, not eight years of history.
        $yearId = $request->query('academic_year_id')
            ?? AcademicYear::query()->active()->value('id');

        $classes = SchoolClass::query()
            ->with(['level:id,name,sequence', 'homeroomTeacher:id,first_name,last_name', 'room:id,name'])
            ->withCount(['enrollments as students_count' => fn (Builder $q) => $q->where('status', 'active')])
            ->when($yearId !== null, fn (Builder $q) => $q->where('academic_year_id', $yearId))
            ->when($request->filled('level_id'), fn (Builder $q) => $q->where('level_id', $request->query('level_id')))
            ->applySearch($request->query('search'), ['name'])
            ->applySort($request->query('sort'), ['name', 'created_at'], 'name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($classes, SchoolClassResource::class);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SchoolClass::class);

        $data = $request->validate([
            'academic_year_id' => ['required', 'uuid'],
            'level_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:20'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:200'],
            'homeroom_teacher_id' => ['nullable', 'uuid'],
            'room_id' => ['nullable', 'uuid'],
        ]);

        $class = SchoolClass::query()->create($data);
        $this->audit->created($class, "Created class {$class->name}");

        return ApiResponse::created(
            new SchoolClassResource($class->load(['level', 'homeroomTeacher', 'room'])),
            __('responses.created'),
        );
    }

    public function show(SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('view', $schoolClass);

        $schoolClass->load([
            'level', 'homeroomTeacher', 'room', 'academicYear',
            'classSubjects.subject', 'classSubjects.teacher',
        ])->loadCount(['enrollments as students_count' => fn (Builder $q) => $q->where('status', 'active')]);

        return ApiResponse::success(new SchoolClassResource($schoolClass));
    }

    public function update(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('update', $schoolClass);

        $before = $schoolClass->getOriginal();

        $schoolClass->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:20'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:200'],
            'homeroom_teacher_id' => ['nullable', 'uuid'],
            'room_id' => ['nullable', 'uuid'],
            'level_id' => ['sometimes', 'uuid'],
            'is_active' => ['boolean'],
        ]))->save();

        $this->audit->updated($schoolClass, $before);

        return ApiResponse::success(
            new SchoolClassResource($schoolClass->load(['level', 'homeroomTeacher', 'room'])),
            __('responses.updated'),
        );
    }

    public function destroy(SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('delete', $schoolClass);

        // Enrolments reference the class; deleting it would strand a
        // student's academic history.
        if ($schoolClass->enrollments()->where('status', 'active')->exists()) {
            throw new DomainException(__('academics.class_has_students'));
        }

        $schoolClass->delete();
        $this->audit->deleted($schoolClass);

        return ApiResponse::noContent();
    }

    /** The students currently enrolled in this class. */
    public function students(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('view', $schoolClass);

        $students = $schoolClass->students()
            ->with('guardians')
            ->orderBy('students.last_name')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($students, StudentResource::class);
    }

    /** Assign a subject (and optionally a teacher) to this class. */
    public function assignSubject(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('assignTeacher', $schoolClass);

        $data = $request->validate([
            'subject_id' => ['required', 'uuid'],
            'teacher_id' => ['nullable', 'uuid'],
            'coefficient' => ['nullable', 'numeric', 'min:0.1', 'max:20'],
            'weekly_hours' => ['nullable', 'integer', 'min:1', 'max:40'],
        ]);

        $assignment = ClassSubject::query()->updateOrCreate(
            ['school_class_id' => $schoolClass->id, 'subject_id' => $data['subject_id']],
            [
                'teacher_id' => $data['teacher_id'] ?? null,
                'coefficient' => $data['coefficient'] ?? 1,
                'weekly_hours' => $data['weekly_hours'] ?? null,
                'is_active' => true,
            ],
        );

        $this->audit->log(
            AuditAction::Update,
            $schoolClass,
            ['description' => "Subject assigned to {$schoolClass->name}"],
        );

        return ApiResponse::success([
            'id' => $assignment->id,
            'subject_id' => $assignment->subject_id,
            'teacher_id' => $assignment->teacher_id,
            'coefficient' => (float) $assignment->coefficient,
        ], __('academics.subject_assigned'));
    }

    public function removeSubject(SchoolClass $schoolClass, ClassSubject $classSubject): JsonResponse
    {
        $this->authorize('assignTeacher', $schoolClass);

        if ($classSubject->school_class_id !== $schoolClass->id) {
            throw new DomainException(__('responses.not_found'));
        }

        // Assessments hang off the assignment; deactivating keeps existing
        // marks resolvable while removing it from the class going forward.
        $classSubject->forceFill(['is_active' => false])->save();

        return ApiResponse::noContent();
    }
}
