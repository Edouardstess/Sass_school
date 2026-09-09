<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\School\Models\AcademicYear;
use App\Domain\School\Services\AcademicYearService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicYearResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AcademicYearController extends Controller
{
    public function __construct(
        private readonly AcademicYearService $years,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicYear::class);

        $years = AcademicYear::query()
            ->withCount(['classes', 'enrollments'])
            ->orderByDesc('starts_on')
            ->get();

        return ApiResponse::success(AcademicYearResource::collection($years));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AcademicYear::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'grading_scale_max' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'passing_grade' => ['nullable', 'numeric', 'min:0'],
            // Copy levels, classes, subjects and fees from a previous year —
            // the "copier configuration d'une année précédente" requirement.
            'copy_from_year_id' => ['nullable', 'uuid'],
        ]);

        $year = $this->years->create($data);

        return ApiResponse::created(new AcademicYearResource($year), __('academics.year_created'));
    }

    public function show(AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('view', $academicYear);

        return ApiResponse::success(
            new AcademicYearResource($academicYear->loadCount(['classes', 'enrollments'])->load('gradePeriods'))
        );
    }

    public function update(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('update', $academicYear);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'date', 'after:starts_on'],
            'grading_scale_max' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'passing_grade' => ['nullable', 'numeric', 'min:0'],
        ]);

        $before = $academicYear->getOriginal();
        $academicYear->fill($data)->save();
        $this->audit->updated($academicYear, $before);

        return ApiResponse::success(new AcademicYearResource($academicYear), __('responses.updated'));
    }

    /** Exactly one year may be active; activating one closes the previous. */
    public function activate(AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('activate', $academicYear);

        return ApiResponse::success(
            new AcademicYearResource($this->years->activate($academicYear)),
            __('academics.year_activated'),
        );
    }

    public function close(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('close', $academicYear);

        $data = $request->validate([
            'status' => ['required', Rule::in([AcademicYear::STATUS_CLOSED, AcademicYear::STATUS_ARCHIVED])],
        ]);

        return ApiResponse::success(
            new AcademicYearResource($this->years->close($academicYear, $data['status'], $this->user($request))),
            __('academics.year_closed_ok'),
        );
    }

    /** Promote a cohort into the next year. */
    public function promote(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('update', $academicYear);

        $data = $request->validate([
            'target_year_id' => ['required', 'uuid'],
            'class_mapping' => ['required', 'array', 'min:1'],
            'class_mapping.*' => ['required', 'uuid'],
            'only_passing' => ['boolean'],
        ]);

        $result = $this->years->promote(
            $academicYear,
            AcademicYear::query()->findOrFail($data['target_year_id']),
            $data['class_mapping'],
            (bool) ($data['only_passing'] ?? false),
        );

        return ApiResponse::success($result, __('academics.promotion_complete'));
    }
}
