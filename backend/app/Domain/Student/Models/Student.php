<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\Academic\Models\Grade;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Finance\Models\Invoice;
use App\Domain\Finance\Models\Scholarship;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A pupil's permanent record.
 *
 * The student row itself is year-agnostic; which class they sit in is held by
 * `enrollments`, one row per academic year, so history is preserved rather
 * than overwritten each September.
 *
 * @property string $id
 * @property string $matricule
 * @property string $status
 */
/**
 * @property Collection<int, Guardian> $guardians
 * @property Collection<int, Enrollment> $enrollments
 * @property CarbonImmutable|null $birth_date
 * @property CarbonImmutable|null $enrolled_on
 * @property CarbonImmutable|null $left_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Student extends BaseModel
{
    /** @use HasFactory<StudentFactory> */
    use BelongsToTenant, Filterable, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRADUATED = 'graduated';

    public const STATUS_TRANSFERRED = 'transferred';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'school_id', 'user_id', 'matricule', 'first_name', 'last_name', 'middle_name',
        'gender', 'birth_date', 'birth_place', 'nationality', 'email', 'phone',
        'address', 'photo_path', 'blood_group', 'medical_notes',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'status', 'enrolled_on', 'left_on', 'previous_school',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'enrolled_on' => 'immutable_date',
            'left_on' => 'immutable_date',
        ];
    }

    /**
     * Note for callers writing a column-restricted eager load: this reads
     * `middle_name` as well as the first and last name. Omitting it from a
     * select produces an incomplete name, which strict attribute access
     * surfaces as an error rather than quietly dropping it.
     */
    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    // ------------------------------------------------------------ relations

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Guardian, $this> */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian')
            ->withPivot(['relationship', 'is_primary', 'is_financial_responsible', 'can_pick_up'])
            ->withTimestamps();
    }

    /** The guardian who receives invoices for this student. */
    public function financialGuardian(): ?Guardian
    {
        return $this->guardians->firstWhere('pivot.is_financial_responsible', true)
            ?? $this->guardians->firstWhere('pivot.is_primary', true)
            ?? $this->guardians->first();
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** The enrolment for the currently active academic year, if any. */
    public function currentEnrollment(): HasMany
    {
        return $this->enrollments()->where('status', 'active');
    }

    /** @return HasMany<Grade, $this> */
    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    /** @return HasMany<AttendanceRecord, $this> */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Scholarship, $this> */
    public function scholarships(): HasMany
    {
        return $this->hasMany(Scholarship::class);
    }

    // --------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInClass(Builder $query, string $classId): Builder
    {
        return $query->whereHas(
            'enrollments',
            fn (Builder $q) => $q->where('school_class_id', $classId)->where('status', 'active')
        );
    }

    public function scopeInYear(Builder $query, string $academicYearId): Builder
    {
        return $query->whereHas(
            'enrollments',
            fn (Builder $q) => $q->where('academic_year_id', $academicYearId)
        );
    }

    /** The class this student sits in for the given year, if enrolled. */
    public function classForYear(string $academicYearId): ?SchoolClass
    {
        /** @var Enrollment|null $enrollment */
        $enrollment = $this->enrollments()
            ->where('academic_year_id', $academicYearId)
            ->first();

        return $enrollment?->schoolClass;
    }

    protected static function newFactory(): StudentFactory
    {
        return StudentFactory::new();
    }
}
