<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Document\Models\Certificate;
use App\Domain\Document\Services\CertificateService;
use App\Domain\Document\Services\DocumentStorage;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Student\Models\Student;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CertificateController extends Controller
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly DocumentStorage $documents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Certificate::class);

        $certificates = Certificate::query()
            ->with(['student:id,first_name,middle_name,last_name,matricule'])
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', $request->query('student_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->query('type')))
            ->latest('issued_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($certificates, null);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('issue', Certificate::class);

        $data = $request->validate([
            'student_id' => ['required', 'uuid'],
            'type' => ['required', Rule::in([
                Certificate::TYPE_ENROLLMENT,
                Certificate::TYPE_ATTENDANCE,
                Certificate::TYPE_COMPLETION,
                Certificate::TYPE_TRANSCRIPT,
            ])],
        ]);

        $student = Student::query()->findOrFail($data['student_id']);
        $this->authorize('view', $student);

        $certificate = $this->certificates->issue($student, $data['type'], $this->user($request));

        return ApiResponse::created($this->present($certificate), __('certificates.issued'));
    }

    public function show(Certificate $certificate): JsonResponse
    {
        $this->authorize('view', $certificate);

        return ApiResponse::success($this->present($certificate->load('student')));
    }

    public function download(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorize('view', $certificate);

        if ($certificate->document_id === null) {
            throw new DomainException(__('academics.report_card_pdf_pending'));
        }

        return ApiResponse::success([
            'url' => $this->documents->temporaryUrlFor($certificate->document, $this->user($request)->id),
        ]);
    }

    public function revoke(Request $request, Certificate $certificate): JsonResponse
    {
        $this->authorize('revoke', $certificate);

        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        return ApiResponse::success(
            $this->present($this->certificates->revoke($certificate, $data['reason'])),
            __('certificates.revoked'),
        );
    }

    /** @return array<string, mixed> */
    private function present(Certificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'type' => $certificate->type,
            'number' => $certificate->number,
            'verification_code' => $certificate->verification_code,
            'verification_url' => rtrim((string) config('app.frontend_url'), '/').'/verify/'.$certificate->verification_code,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'expires_at' => $certificate->expires_at?->toIso8601String(),
            'revoked_at' => $certificate->revoked_at?->toIso8601String(),
            'is_valid' => $certificate->isValid(),
            'has_pdf' => $certificate->document_id !== null,
            'student' => $certificate->relationLoaded('student') ? [
                'id' => $certificate->student?->id,
                'full_name' => $certificate->student?->full_name,
                'matricule' => $certificate->student?->matricule,
            ] : null,
        ];
    }
}
