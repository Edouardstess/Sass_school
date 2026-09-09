<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\GradePeriod;
use App\Domain\Academic\Models\ReportCard;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Services\ReportCardService;
use App\Domain\Document\Services\DocumentStorage;
use App\Domain\Shared\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportCardController extends Controller
{
    public function __construct(
        private readonly ReportCardService $reportCards,
        private readonly DocumentStorage $documents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReportCard::class);

        $user = $this->user($request);

        $cards = ReportCard::query()
            ->with(['student:id,first_name,middle_name,last_name,matricule', 'gradePeriod:id,name', 'schoolClass:id,name'])
            ->when($request->filled('class_id'), fn (Builder $q) => $q->where('school_class_id', $request->query('class_id')))
            ->when($request->filled('grade_period_id'), fn (Builder $q) => $q->where('grade_period_id', $request->query('grade_period_id')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', $request->query('student_id')))
            // Parents and students see published cards for their own family.
            ->when(! $user->hasPermission('report_cards.view'), function (Builder $q) use ($user): void {
                $q->where('status', ReportCard::STATUS_PUBLISHED)
                    ->whereIn('student_id', $this->relatedStudentIds($user));
            })
            ->orderBy('rank')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($cards, null);
    }

    public function show(ReportCard $reportCard): JsonResponse
    {
        $this->authorize('view', $reportCard);

        $reportCard->load([
            'student:id,first_name,middle_name,last_name,matricule',
            'gradePeriod:id,name,academic_year_id',
            'schoolClass:id,name',
            'lines' => fn ($q) => $q->orderBy('subject_name'),
        ]);

        return ApiResponse::success([
            'id' => $reportCard->id,
            'status' => $reportCard->status,
            'average' => $reportCard->average === null ? null : (float) $reportCard->average,
            'rank' => $reportCard->rank,
            'class_size' => $reportCard->class_size,
            'class_average' => $reportCard->class_average === null ? null : (float) $reportCard->class_average,
            'absences_count' => $reportCard->absences_count,
            'late_count' => $reportCard->late_count,
            'remarks' => $reportCard->remarks,
            'computed_at' => $reportCard->computed_at?->toIso8601String(),
            'published_at' => $reportCard->published_at?->toIso8601String(),
            'has_pdf' => $reportCard->document_id !== null,
            'student' => [
                'id' => $reportCard->student?->id,
                'matricule' => $reportCard->student?->matricule,
                'full_name' => $reportCard->student?->full_name,
            ],
            'class' => ['id' => $reportCard->schoolClass?->id, 'name' => $reportCard->schoolClass?->name],
            'period' => ['id' => $reportCard->gradePeriod?->id, 'name' => $reportCard->gradePeriod?->name],
            'lines' => $reportCard->lines->map(fn ($line): array => [
                'subject_id' => $line->subject_id,
                'subject_name' => $line->subject_name,
                'coefficient' => (float) $line->coefficient,
                'average' => $line->average === null ? null : (float) $line->average,
                'class_average' => $line->class_average === null ? null : (float) $line->class_average,
                'min_score' => $line->min_score === null ? null : (float) $line->min_score,
                'max_score' => $line->max_score === null ? null : (float) $line->max_score,
                'rank' => $line->rank,
                'appreciation' => $line->appreciation,
                'teacher_name' => $line->teacher_name,
            ])->all(),
        ]);
    }

    /** Recompute a whole class for a period. */
    public function generate(Request $request): JsonResponse
    {
        $this->authorize('generate', ReportCard::class);

        $data = $request->validate([
            'class_id' => ['required', 'uuid'],
            'grade_period_id' => ['required', 'uuid'],
        ]);

        $result = $this->reportCards->computeForClass(
            SchoolClass::query()->findOrFail($data['class_id']),
            GradePeriod::query()->with('academicYear')->findOrFail($data['grade_period_id']),
        );

        return ApiResponse::success($result, __('academics.report_cards_generated'));
    }

    /** Publish, making the cards visible to families and queueing the PDFs. */
    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'uuid'],
            'grade_period_id' => ['required', 'uuid'],
        ]);

        $class = SchoolClass::query()->findOrFail($data['class_id']);
        $period = GradePeriod::query()->findOrFail($data['grade_period_id']);

        $sample = ReportCard::query()
            ->where('school_class_id', $class->id)
            ->where('grade_period_id', $period->id)
            ->first();

        if ($sample === null) {
            throw new DomainException(__('academics.report_card_not_ready'));
        }

        $this->authorize('publish', $sample);

        $count = $this->reportCards->publishForClass($class, $period, $this->user($request));

        return ApiResponse::success(['published' => $count], __('academics.report_cards_published'));
    }

    /** A signed, short-lived link to the rendered PDF. */
    public function download(Request $request, ReportCard $reportCard): JsonResponse
    {
        $this->authorize('view', $reportCard);

        if ($reportCard->document_id === null) {
            throw new DomainException(__('academics.report_card_pdf_pending'));
        }

        $document = $reportCard->document;

        return ApiResponse::success([
            'url' => $this->documents->temporaryUrlFor($document, $this->user($request)->id),
            'expires_in_minutes' => (int) config('schoolflow.security.signed_url_ttl_minutes', 10),
        ]);
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

        // An empty list would match nothing, which is the safe default for a
        // user with neither profile.
        return $ids;
    }
}
