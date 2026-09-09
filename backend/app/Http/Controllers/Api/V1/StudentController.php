<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Student\Models\Student;
use App\Domain\Student\Services\StudentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\StoreStudentRequest;
use App\Http\Requests\Students\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student records.
 *
 * Every query starts from `Student::query()`, which carries the tenant global
 * scope, so a caller can never widen the result set past their own school —
 * including through `?class_id` or a route parameter.
 */
class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $students,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);

        $user = $this->user($request);

        $query = Student::query()
            ->with('guardians')
            ->applySearch($request->query('search'), ['first_name', 'last_name', 'matricule', 'email'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('class_id'), fn (Builder $q) => $q->inClass((string) $request->query('class_id')))
            ->when($request->filled('academic_year_id'), fn (Builder $q) => $q->inYear((string) $request->query('academic_year_id')))
            ->when($request->filled('gender'), fn (Builder $q) => $q->where('gender', $request->query('gender')));

        // A parent or student holds `students.view_own` rather than
        // `students.view`; narrow the list to the records they are attached to
        // instead of refusing the endpoint outright.
        if (! $user->hasPermission('students.view')) {
            $query = $this->students->restrictToRelated($query, $user);
        }

        $paginator = $query
            ->applySort($request->query('sort'), ['last_name', 'first_name', 'matricule', 'created_at', 'enrolled_on'], 'last_name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($paginator, StudentResource::class);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $this->authorize('create', Student::class);

        $student = $this->students->create(
            $request->validated(),
            $this->user($request),
        );

        return ApiResponse::created(
            new StudentResource($student->load('guardians')),
            __('students.created'),
        );
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $student->load([
            'guardians',
            'enrollments.schoolClass.level',
            'enrollments.academicYear',
        ]);

        return ApiResponse::success(new StudentResource($student));
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $this->authorize('update', $student);

        $before = $student->getOriginal();
        $student = $this->students->update($student, $request->validated());
        $this->audit->updated($student, $before, "Updated student {$student->matricule}");

        return ApiResponse::success(
            new StudentResource($student->load('guardians')),
            __('responses.updated'),
        );
    }

    /**
     * Soft delete.
     *
     * Student records are never hard-deleted: enrolment history, invoices and
     * report cards reference them, and a school's obligation to retain
     * academic records outlives its interest in tidying the list.
     */
    public function destroy(Request $request, Student $student): JsonResponse
    {
        $this->authorize('delete', $student);

        $this->students->archive($student);
        $this->audit->deleted($student, "Archived student {$student->matricule}");

        return ApiResponse::noContent();
    }

    /** Attach or update a guardian relationship. */
    public function attachGuardian(Request $request, Student $student): JsonResponse
    {
        $this->authorize('update', $student);

        $data = $request->validate([
            'guardian_id' => ['required', 'uuid'],
            'relationship' => ['required', 'string', 'max:40'],
            'is_primary' => ['boolean'],
            'is_financial_responsible' => ['boolean'],
            'can_pick_up' => ['boolean'],
        ]);

        $student = $this->students->attachGuardian($student, $data);

        return ApiResponse::success(
            new StudentResource($student->load('guardians')),
            __('students.guardian_attached'),
        );
    }

    public function detachGuardian(Request $request, Student $student, string $guardianId): JsonResponse
    {
        $this->authorize('update', $student);

        $this->students->detachGuardian($student, $guardianId);

        return ApiResponse::noContent();
    }
}
