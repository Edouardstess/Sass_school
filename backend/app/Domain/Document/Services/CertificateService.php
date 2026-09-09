<?php

declare(strict_types=1);

namespace App\Domain\Document\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Document\Models\Certificate;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\AuditAction;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Services\NumberGenerator;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\GenerateCertificatePdf;
use Illuminate\Support\Facades\DB;

/**
 * Issues officially verifiable documents.
 *
 * Two properties make the public verification route safe:
 *   - the code is random and non-sequential, so holding one tells you nothing
 *     about any other and reveals nothing about issuance volume;
 *   - `payload` freezes what was printed, so verification answers what the
 *     document said when issued rather than what the database says today.
 */
final class CertificateService
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function issue(
        Student $student,
        string $type,
        User $actor,
        ?AcademicYear $year = null,
    ): Certificate {
        $year ??= AcademicYear::query()->active()->first();

        if ($year === null) {
            throw new DomainException(__('academics.no_active_year'));
        }

        return DB::transaction(function () use ($student, $type, $actor, $year): Certificate {
            $enrollment = $student->enrollments()
                ->where('academic_year_id', $year->id)
                ->with('schoolClass.level')
                ->first();

            $certificate = new Certificate;
            $certificate->forceFill([
                'school_id' => $this->tenant->idOrFail(),
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'issued_by' => $actor->id,
                'type' => $type,
                'number' => $this->numbers->next('certificate'),
                'verification_code' => $this->numbers->verificationCode(),
                // Frozen at issue time on purpose.
                'payload' => [
                    'student_name' => $student->full_name,
                    'matricule' => $student->matricule,
                    'birth_date' => $student->birth_date?->toDateString(),
                    'class_name' => $enrollment?->schoolClass?->name,
                    'level_name' => $enrollment?->schoolClass?->level?->name,
                    'academic_year' => $year->name,
                    'school_name' => $this->tenant->schoolOrFail()->name,
                ],
                'issued_at' => now(),
            ])->save();

            GenerateCertificatePdf::dispatch($certificate->id, $certificate->school_id)
                ->onQueue('documents');

            $this->audit->log(AuditAction::Create, $certificate, [
                'description' => "Issued {$type} certificate {$certificate->number} for {$student->full_name}",
            ]);

            return $certificate;
        });
    }

    public function revoke(Certificate $certificate, string $reason): Certificate
    {
        if ($certificate->revoked_at !== null) {
            return $certificate;
        }

        $certificate->forceFill([
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();

        $this->audit->log(AuditAction::Update, $certificate, [
            'description' => "Revoked certificate {$certificate->number}: {$reason}",
        ]);

        return $certificate;
    }

    /**
     * Public verification.
     *
     * Returns an attestation, never the student's record: the holder's
     * initials rather than their full name, and no matricule, address or
     * marks. Anyone with the code can check the document is genuine without
     * learning anything else about the child.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $code): ?array
    {
        $certificate = Certificate::query()
            ->withoutTenantScope()
            ->where('verification_code', strtoupper(trim($code)))
            ->with('school:id,name,city,country')
            ->first();

        if ($certificate === null) {
            return null;
        }

        $payload = $certificate->payload ?? [];
        $name = (string) ($payload['student_name'] ?? '');

        return [
            'valid' => $certificate->isValid(),
            'type' => $certificate->type,
            'number' => $certificate->number,
            'issued_at' => $certificate->issued_at?->toDateString(),
            'expires_at' => $certificate->expires_at?->toDateString(),
            'revoked' => $certificate->revoked_at !== null,
            'revoked_at' => $certificate->revoked_at?->toDateString(),
            'school' => [
                'name' => $certificate->school?->name,
                'city' => $certificate->school?->city,
            ],
            'holder_initials' => $this->initials($name),
            'academic_year' => $payload['academic_year'] ?? null,
        ];
    }

    /** "Wideline Alexis" → "W. A." */
    private function initials(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        return implode(' ', array_map(
            static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)).'.',
            array_filter($parts),
        ));
    }
}
