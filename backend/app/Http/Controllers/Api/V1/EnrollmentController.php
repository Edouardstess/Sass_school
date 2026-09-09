<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Services\EnrollmentService;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Student::class);

        $data = $request->validate([
            'student_id' => ['required', 'uuid'],
            'school_class_id' => ['required', 'uuid'],
            'enrolled_on' => ['nullable', 'date'],
            'enrollment_type' => ['nullable', Rule::in([
                Enrollment::TYPE_NEW, Enrollment::TYPE_RE_ENROLLMENT, Enrollment::TYPE_TRANSFER_IN,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Both resolve through the tenant-scoped query, so an identifier from
        // another school simply does not exist here.
        $student = Student::query()->findOrFail($data['student_id']);
        $class = SchoolClass::query()->with('academicYear')->findOrFail($data['school_class_id']);

        $enrollment = $this->enrollments->enroll(
            student: $student,
            class: $class,
            enrolledOn: isset($data['enrolled_on']) ? CarbonImmutable::parse($data['enrolled_on']) : null,
            type: $data['enrollment_type'] ?? Enrollment::TYPE_NEW,
            notes: $data['notes'] ?? null,
        );

        return ApiResponse::created($this->present($enrollment->load(['schoolClass', 'academicYear'])), __('responses.created'));
    }

    /** Move a student to another class within the same year. */
    public function transfer(Request $request, Enrollment $enrollment): JsonResponse
    {
        $this->authorize('transfer', $enrollment->student);

        $data = $request->validate([
            'school_class_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $target = SchoolClass::query()->findOrFail($data['school_class_id']);

        $enrollment = $this->enrollments->transfer($enrollment, $target, $data['reason'] ?? null);

        return ApiResponse::success(
            $this->present($enrollment->load(['schoolClass', 'academicYear'])),
            __('academics.transferred'),
        );
    }

    public function withdraw(Request $request, Enrollment $enrollment): JsonResponse
    {
        $this->authorize('update', $enrollment->student);

        $data = $request->validate([
            'status' => ['required', Rule::in(['withdrawn', 'transferred', 'completed'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $enrollment = $this->enrollments->withdraw($enrollment, $data['status'], $data['reason'] ?? null);

        return ApiResponse::success($this->present($enrollment), __('responses.updated'));
    }

    /** @return array<string, mixed> */
    private function present(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'status' => $enrollment->status->value,
            'enrollment_type' => $enrollment->enrollment_type,
            'enrolled_on' => $enrollment->enrolled_on?->toDateString(),
            'ended_on' => $enrollment->ended_on?->toDateString(),
            'class' => $enrollment->relationLoaded('schoolClass') ? [
                'id' => $enrollment->schoolClass?->id,
                'name' => $enrollment->schoolClass?->name,
            ] : null,
            'academic_year' => $enrollment->relationLoaded('academicYear') ? [
                'id' => $enrollment->academicYear?->id,
                'name' => $enrollment->academicYear?->name,
            ] : null,
        ];
    }
}
