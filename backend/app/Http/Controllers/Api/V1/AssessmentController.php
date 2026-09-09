<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Services\GradeCalculator;
use App\Domain\Academic\Services\GradeService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Shared\Enums\AssessmentType;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public function __construct(
        private readonly GradeService $grades,
        private readonly GradeCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Assessment::class);

        $user = $this->user($request);

        $assessments = Assessment::query()
            ->with(['classSubject.subject:id,name', 'classSubject.schoolClass:id,name', 'gradePeriod:id,name'])
            ->withCount('grades')
            ->when($request->filled('grade_period_id'), fn (Builder $q) => $q->where('grade_period_id', $request->query('grade_period_id')))
            ->when($request->filled('class_subject_id'), fn (Builder $q) => $q->where('class_subject_id', $request->query('class_subject_id')))
            ->when($request->filled('class_id'), fn (Builder $q) => $q->whereHas(
                'classSubject',
                fn (Builder $inner) => $inner->where('school_class_id', $request->query('class_id')),
            ))
            // A teacher's list is their own work, not the whole school's.
            ->when(
                ! $user->hasPermission('grades.lock') && $user->loadMissing('teacher')->teacher !== null,
                fn (Builder $q) => $q->whereHas(
                    'classSubject',
                    fn (Builder $inner) => $inner->where('teacher_id', $user->teacher?->id),
                ),
            )
            ->applySort($request->query('sort'), ['assessed_on', 'title', 'created_at'], '-assessed_on')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($assessments, null);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Assessment::class);

        $data = $request->validate([
            'class_subject_id' => ['required', 'uuid'],
            'grade_period_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::enum(AssessmentType::class)],
            'max_score' => ['required', 'numeric', 'min:1', 'max:1000'],
            'weight' => ['nullable', 'numeric', 'min:0.1', 'max:20'],
            'assessed_on' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $assignment = ClassSubject::query()
            ->with('schoolClass')
            ->findOrFail($data['class_subject_id']);

        // A teacher may only create work for the (class, subject) pairs they
        // are assigned to; holding grades.create is not enough.
        $this->assertMayTeach($request, $assignment);

        $assessment = Assessment::query()->create([
            ...$data,
            'academic_year_id' => $assignment->schoolClass?->academic_year_id,
            'created_by' => $this->user($request)->id,
            'weight' => $data['weight'] ?? AssessmentType::from($data['type'])->defaultWeight(),
            'status' => Assessment::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->audit->created($assessment, "Created assessment \"{$assessment->title}\"");

        return ApiResponse::created(
            $this->present($assessment->load(['classSubject.subject', 'classSubject.schoolClass', 'gradePeriod'])),
            __('responses.created'),
        );
    }

    /** The assessment plus the class roster and each student's mark. */
    public function show(Assessment $assessment): JsonResponse
    {
        $this->authorize('view', $assessment);

        $assessment->load([
            'classSubject.subject', 'classSubject.schoolClass', 'gradePeriod',
            'grades.student:id,first_name,middle_name,last_name,matricule',
        ]);

        $period = $assessment->gradePeriod;
        $year = $period?->academicYear;
        $scaleMax = $year === null ? 100.0 : (float) $year->grading_scale_max;

        return ApiResponse::success([
            ...$this->present($assessment),
            'statistics' => $this->calculator->assessmentStatistics(
                $assessment,
                $year === null ? 50.0 : (float) $year->passing_grade,
                $scaleMax,
            ),
            'grades' => $assessment->grades->map(fn ($grade): array => [
                'id' => $grade->id,
                'student' => [
                    'id' => $grade->student?->id,
                    'matricule' => $grade->student?->matricule,
                    'full_name' => $grade->student?->full_name,
                ],
                'score' => $grade->score === null ? null : (float) $grade->score,
                'is_absent' => $grade->is_absent,
                'is_excused' => $grade->is_excused,
                'comment' => $grade->comment,
            ])->all(),
        ]);
    }

    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorize('update', $assessment);

        $before = $assessment->getOriginal();

        $assessment->fill($request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'type' => ['sometimes', Rule::enum(AssessmentType::class)],
            'max_score' => ['sometimes', 'numeric', 'min:1', 'max:1000'],
            'weight' => ['sometimes', 'numeric', 'min:0.1', 'max:20'],
            'assessed_on' => ['sometimes', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]))->save();

        $this->audit->updated($assessment, $before);

        return ApiResponse::success($this->present($assessment), __('responses.updated'));
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        $this->authorize('delete', $assessment);

        $assessment->delete();
        $this->audit->deleted($assessment);

        return ApiResponse::noContent();
    }

    /** Freeze the marks. */
    public function lock(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorize('lock', $assessment);

        return ApiResponse::success(
            $this->present($this->grades->lock($assessment, $this->user($request))),
            __('academics.assessment_locked_ok'),
        );
    }

    public function unlock(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorize('lock', $assessment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::success(
            $this->present($this->grades->unlock($assessment, $this->user($request), $data['reason'])),
            __('academics.assessment_unlocked'),
        );
    }

    private function assertMayTeach(Request $request, ClassSubject $assignment): void
    {
        $user = $this->user($request);

        if ($user->hasAnyPermission(['grades.lock', 'grades.publish'])) {
            return;   // an administrator or principal, not the teacher
        }

        $teacher = $user->loadMissing('teacher')->teacher;

        if ($teacher === null || ! $teacher->teaches($assignment->id)) {
            throw new DomainException(__('academics.teacher_not_assigned'));
        }
    }

    /** @return array<string, mixed> */
    private function present(Assessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'type' => $assessment->type->value,
            'type_label' => $assessment->type->label(),
            'max_score' => (float) $assessment->max_score,
            'weight' => (float) $assessment->weight,
            'assessed_on' => $assessment->assessed_on?->toDateString(),
            'description' => $assessment->description,
            'status' => $assessment->status,
            'is_locked' => $assessment->isLocked(),
            'grades_count' => $assessment->grades_count ?? null,
            'class' => $assessment->relationLoaded('classSubject') ? [
                'id' => $assessment->classSubject?->schoolClass?->id,
                'name' => $assessment->classSubject?->schoolClass?->name,
            ] : null,
            'subject' => $assessment->relationLoaded('classSubject') ? [
                'id' => $assessment->classSubject?->subject?->id,
                'name' => $assessment->classSubject?->subject?->name,
            ] : null,
            'grade_period' => $assessment->relationLoaded('gradePeriod') ? [
                'id' => $assessment->gradePeriod?->id,
                'name' => $assessment->gradePeriod?->name,
            ] : null,
        ];
    }
}
