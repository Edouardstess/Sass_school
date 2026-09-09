<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\Assessment;
use App\Domain\Academic\Models\Grade;
use App\Domain\Academic\Services\GradeService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marks.
 *
 * Recording is batched by default: a teacher submits a whole class at once,
 * and the batch is one transaction so nineteen marks cannot be saved with the
 * twentieth lost.
 */
class GradeController extends Controller
{
    public function __construct(private readonly GradeService $grades) {}

    public function store(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorize('createFor', [Grade::class, $assessment]);

        $data = $request->validate([
            'grades' => ['required', 'array', 'min:1', 'max:200'],
            'grades.*.student_id' => ['required', 'uuid'],
            'grades.*.score' => ['nullable', 'numeric', 'min:0'],
            'grades.*.is_absent' => ['boolean'],
            'grades.*.is_excused' => ['boolean'],
            'grades.*.comment' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->grades->recordBatch($assessment, $data['grades'], $this->user($request));

        return ApiResponse::success($result, __('academics.grades_recorded'));
    }

    /** Amend a single mark; the reason is recorded on the revision. */
    public function update(Request $request, Grade $grade): JsonResponse
    {
        $this->authorize('update', $grade);

        $data = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0'],
            'is_absent' => ['boolean'],
            'is_excused' => ['boolean'],
            'comment' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->grades->record(
            assessment: $grade->assessment,
            studentId: $grade->student_id,
            score: $data['score'] ?? null,
            isAbsent: (bool) ($data['is_absent'] ?? false),
            isExcused: (bool) ($data['is_excused'] ?? false),
            comment: $data['comment'] ?? null,
            actor: $this->user($request),
            reason: $data['reason'] ?? null,
        );

        return ApiResponse::success([
            'id' => $updated->id,
            'score' => $updated->score === null ? null : (float) $updated->score,
            'is_absent' => $updated->is_absent,
        ], __('responses.updated'));
    }

    /** The audit trail for one mark. */
    public function revisions(Grade $grade): JsonResponse
    {
        $this->authorize('view', $grade);

        return ApiResponse::success(
            $grade->revisions()->with('author:id,first_name,last_name')->get()
                ->map(fn ($revision): array => [
                    'id' => $revision->id,
                    'old_score' => $revision->old_score === null ? null : (float) $revision->old_score,
                    'new_score' => $revision->new_score === null ? null : (float) $revision->new_score,
                    'reason' => $revision->reason,
                    'author' => $revision->author?->full_name,
                    'created_at' => $revision->created_at?->toIso8601String(),
                ])->all()
        );
    }
}
