<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Document\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
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
/**
 * @property string $id
 * @property string $student_id
 * @property string $status
 * @property int|null $rank
 * @property int|null $class_size
 * @property Student|null $student
 * @property GradePeriod|null $gradePeriod
 * @property SchoolClass|null $schoolClass
 * @property Collection<int, ReportCardLine> $lines
 * @property CarbonImmutable|null $computed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $updated_at
 */
class ReportCard extends BaseModel
{
    use BelongsToTenant, Filterable;

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

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<GradePeriod, $this> */
    public function gradePeriod(): BelongsTo
    {
        return $this->belongsTo(GradePeriod::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    /** @return HasMany<ReportCardLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ReportCardLine::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
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
