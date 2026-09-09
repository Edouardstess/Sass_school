<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Academic\Models\Level;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\ApplicationStatus;
use App\Domain\Student\Models\AdmissionApplication;
use App\Domain\Student\Models\AdmissionComment;
use App\Domain\Student\Services\AdmissionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdmissionController extends Controller
{
    public function __construct(private readonly AdmissionService $admissions) {}

    // ------------------------------------------------------------- public

    /**
     * The public application form target.
     *
     * Unauthenticated by design — a family applying does not have an account
     * yet — so it is rate limited (`throttle:public-write`) and the tenant
     * comes from the URL slug rather than from anything the client asserts.
     */
    public function submit(Request $request, string $schoolSlug): JsonResponse
    {
        $school = School::query()->where('slug', $schoolSlug)->firstOrFail();

        abort_unless($school->isOperational(), 404);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['required', 'date', 'before:today', 'after:1990-01-01'],
            'birth_place' => ['nullable', 'string', 'max:160'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'previous_school' => ['nullable', 'string', 'max:160'],
            'level_id' => ['required', 'uuid'],

            'guardian_first_name' => ['required', 'string', 'max:120'],
            'guardian_last_name' => ['required', 'string', 'max:120'],
            'guardian_relationship' => ['required', 'string', 'max:40'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
            'guardian_phone' => ['required', 'string', 'max:40'],
        ]);

        $application = $this->admissions->submit($school, $data);

        return ApiResponse::created([
            // The reference is the applicant's only handle on their file.
            'reference' => $application->reference,
            'status' => $application->status->value,
            'submitted_at' => $application->submitted_at?->toDateString(),
        ], __('admissions.submitted'));
    }

    /** Public status lookup: reference in, status out, nothing else. */
    public function publicStatus(string $schoolSlug, string $reference): JsonResponse
    {
        $school = School::query()->where('slug', $schoolSlug)->firstOrFail();

        $status = $this->admissions->publicStatus($school, $reference);

        if ($status === null) {
            return ApiResponse::error(__('admissions.not_found'), 404);
        }

        return ApiResponse::success($status);
    }

    /** The levels a family may apply to, for the public form's dropdown. */
    public function publicLevels(string $schoolSlug): JsonResponse
    {
        $school = School::query()->where('slug', $schoolSlug)->firstOrFail();

        abort_unless($school->isOperational(), 404);

        $levels = Level::query()
            ->forTenant($school->id)
            ->orderBy('sequence')
            ->get(['id', 'name']);

        return ApiResponse::success([
            'school' => ['name' => $school->name, 'slug' => $school->slug],
            'levels' => $levels->map(fn ($l): array => ['id' => $l->id, 'name' => $l->name])->all(),
        ]);
    }

    // ---------------------------------------------------------- authenticated

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AdmissionApplication::class);

        $applications = AdmissionApplication::query()
            ->with(['level:id,name', 'reviewer:id,first_name,last_name'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->boolean('open_only'), fn (Builder $q) => $q->whereIn('status', [
                ApplicationStatus::Submitted->value,
                ApplicationStatus::UnderReview->value,
                ApplicationStatus::Accepted->value,
            ]))
            ->applySearch($request->query('search'), ['reference', 'first_name', 'last_name', 'guardian_phone'])
            ->orderByDesc('submitted_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($applications, null);
    }

    public function show(AdmissionApplication $admissionApplication): JsonResponse
    {
        $this->authorize('view', $admissionApplication);

        return ApiResponse::success(
            $admissionApplication->load(['level', 'reviewer', 'comments.author:id,first_name,last_name', 'documents'])
        );
    }

    public function decide(Request $request, AdmissionApplication $admissionApplication): JsonResponse
    {
        $this->authorize('decide', $admissionApplication);

        $data = $request->validate([
            'decision' => ['required', Rule::in([
                ApplicationStatus::UnderReview->value,
                ApplicationStatus::Accepted->value,
                ApplicationStatus::Rejected->value,
            ])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $application = $this->admissions->decide(
            $admissionApplication,
            ApplicationStatus::from($data['decision']),
            $this->user($request),
            $data['reason'] ?? null,
        );

        return ApiResponse::success([
            'reference' => $application->reference,
            'status' => $application->status->value,
        ], __('admissions.decided'));
    }

    /** Convert an accepted application into a real student record. */
    public function enroll(Request $request, AdmissionApplication $admissionApplication): JsonResponse
    {
        $this->authorize('enroll', $admissionApplication);

        $data = $request->validate(['school_class_id' => ['required', 'uuid']]);

        $student = $this->admissions->convertToStudent(
            $admissionApplication,
            SchoolClass::query()->with('academicYear')->findOrFail($data['school_class_id']),
            $this->user($request),
        );

        return ApiResponse::created(
            new StudentResource($student->load('guardians')),
            __('admissions.converted'),
        );
    }

    public function comment(Request $request, AdmissionApplication $admissionApplication): JsonResponse
    {
        $this->authorize('review', $admissionApplication);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $comment = AdmissionComment::query()->create([
            'school_id' => $admissionApplication->school_id,
            'application_id' => $admissionApplication->id,
            'user_id' => $this->user($request)->id,
            'body' => $data['body'],
        ]);

        return ApiResponse::created([
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], __('responses.created'));
    }
}
