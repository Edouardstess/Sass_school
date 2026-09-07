<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Document\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A computed report card for one student in one grading period.
 *
 * Materialised rather than recomputed on read: dashboards, rankings, PDF
 * rendering and parent portals all consume it, and recalculating averages for
 * a 700-student school on every page view would not survive contact with
 * production.
 */
class ReportCard extends BaseModel
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'school_id', 'student_id', 'grade_period_id', 'school_class_id',
        'average', 'rank', 'class_size', 'class_average',
        'absences_count', 'late_count', 'remarks', 'status', 'computed_at', 'document_id',
    ];

    protected function casts(): array
    {
        return [
            'average' => 'decimal:2',
            'class_average' => 'decimal:2',
            'rank' => 'integer',
            'class_size' => 'integer',
            'absences_count' => 'integer',
            'late_count' => 'integer',
            'computed_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function gradePeriod(): BelongsTo
    {
        return $this->belongsTo(GradePeriod::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReportCardLine::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** Only a published card is visible to guardians and students. */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
