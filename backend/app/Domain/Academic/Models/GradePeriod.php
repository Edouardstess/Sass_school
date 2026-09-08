<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A term / trimester. Grades belong to one, and a locked period stops
 * accepting new or amended marks.
 */
/**
 * @property string $id
 * @property string $academic_year_id
 * @property string $name
 * @property bool $is_locked
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property AcademicYear|null $academicYear
 */
class GradePeriod extends BaseModel
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'school_id', 'academic_year_id', 'name', 'sequence',
        'starts_on', 'ends_on', 'weight',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'locked_at' => 'immutable_datetime',
            'is_locked' => 'boolean',
            'sequence' => 'integer',
            'weight' => 'decimal:2',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function acceptsGrades(): bool
    {
        return ! $this->is_locked;
    }
}
