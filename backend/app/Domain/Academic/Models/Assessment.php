<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\AssessmentType;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A graded exercise. Marks are recorded against it on the `max_score` scale it
 * declares; the calculator normalises before combining, so a quiz out of 20
 * and an exam out of 100 mix correctly.
 */
/**
 * @property string $id
 * @property string $class_subject_id
 * @property string $grade_period_id
 * @property string $status
 * @property AssessmentType $type
 * @property numeric-string $max_score
 * @property numeric-string $weight
 * @property GradePeriod|null $gradePeriod
 * @property ClassSubject|null $classSubject
 */
class Assessment extends BaseModel
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_LOCKED = 'locked';

    protected $fillable = [
        'school_id', 'academic_year_id', 'grade_period_id', 'class_subject_id',
        'created_by', 'title', 'type', 'max_score', 'weight', 'assessed_on',
        'description', 'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => AssessmentType::class,
            'max_score' => 'decimal:2',
            'weight' => 'decimal:2',
            'assessed_on' => 'immutable_date',
            'published_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function gradePeriod(): BelongsTo
    {
        return $this->belongsTo(GradePeriod::class);
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    /** Marks may be written only while the assessment and its period are open. */
    public function acceptsGrades(): bool
    {
        return $this->status !== self::STATUS_LOCKED
            && $this->gradePeriod?->acceptsGrades() !== false;
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }
}
