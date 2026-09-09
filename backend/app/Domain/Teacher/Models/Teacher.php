<?php

declare(strict_types=1);

namespace App\Domain\Teacher\Models;

use App\Domain\Academic\Models\ClassSubject;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\TimetableEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string|null $user_id
 * @property Collection<int, ClassSubject> $classSubjects
 * @property string $status
 * @property CarbonImmutable|null $birth_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $hired_on
 * @property CarbonImmutable|null $updated_at
 */
class Teacher extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'school_id', 'user_id', 'employee_number', 'first_name', 'last_name',
        'gender', 'birth_date', 'email', 'phone', 'address', 'photo_path',
        'specialty', 'qualification', 'hired_on', 'status',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'hired_on' => 'immutable_date',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Every (class, subject) pair this teacher is responsible for. */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    /** @return HasMany<SchoolClass, $this> */
    public function homeroomClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'homeroom_teacher_id');
    }

    /** @return HasMany<TimetableEntry, $this> */
    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Whether this teacher may record marks for a given assignment.
     *
     * Consulted by GradePolicy in addition to the permission check, so holding
     * `grades.create` is necessary but never sufficient.
     */
    public function teaches(string $classSubjectId): bool
    {
        return $this->classSubjects()
            ->whereKey($classSubjectId)
            ->where('is_active', true)
            ->exists();
    }
}
