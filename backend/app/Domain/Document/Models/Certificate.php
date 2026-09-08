<?php

declare(strict_types=1);

namespace App\Domain\Document\Models;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An officially issued, publicly verifiable document.
 *
 * `verification_code` is random rather than sequential, so possessing one code
 * tells you nothing about any other, and the public verification route reveals
 * only an attestation — never the student's full record.
 */
/**
 * @property Student|null $student
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
class Certificate extends BaseModel
{
    use BelongsToTenant;

    public const TYPE_ENROLLMENT = 'enrollment';

    public const TYPE_ATTENDANCE = 'attendance_attestation';

    public const TYPE_COMPLETION = 'completion';

    public const TYPE_TRANSCRIPT = 'transcript';

    protected $fillable = [
        'school_id', 'student_id', 'academic_year_id', 'issued_by', 'document_id',
        'type', 'number', 'verification_code', 'payload', 'issued_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
