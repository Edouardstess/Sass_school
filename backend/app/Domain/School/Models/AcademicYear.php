<?php

declare(strict_types=1);

namespace App\Domain\School\Models;

use App\Domain\Academic\Models\GradePeriod;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A school year. Exactly one may be `active` per tenant — enforced by a
 * partial unique index, not merely by the service that flips the flag.
 *
 * @property string $id
 * @property string $name
 * @property string $status
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property numeric-string $grading_scale_max
 * @property numeric-string $passing_grade
 */
class AcademicYear extends BaseModel
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'school_id', 'name', 'starts_on', 'ends_on', 'status',
        'grading_scale_max', 'passing_grade',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'grading_scale_max' => 'decimal:2',
            'passing_grade' => 'decimal:2',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Grades and enrolments may only be written while the year is active. */
    public function acceptsWrites(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function gradePeriods(): HasMany
    {
        return $this->hasMany(GradePeriod::class)->orderBy('sequence');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
