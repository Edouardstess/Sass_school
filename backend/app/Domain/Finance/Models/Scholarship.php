<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A per-student award applied automatically when invoices are generated.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $updated_at
 */
class Scholarship extends BaseModel
{
    use BelongsToTenant;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = [
        'school_id', 'student_id', 'academic_year_id', 'granted_by',
        'name', 'type', 'value', 'currency', 'reason',
        'starts_on', 'ends_on', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'is_active' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function amountFor(Money $base): Money
    {
        return $this->type === self::TYPE_PERCENTAGE
            ? $base->percentage($this->value)
            : Money::of($this->value, $this->currency ?? $base->currency)->min($base);
    }

    /** Active and within its validity window on the given date. */
    public function scopeEffectiveOn(Builder $query, CarbonImmutable $date): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }
}
