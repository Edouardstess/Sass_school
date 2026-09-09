<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Student\Models\Enrollment;
use App\Domain\Student\Models\Student;
use App\Domain\Teacher\Models\Teacher;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A class group ("6ème A") for one academic year.
 *
 * Note the table is `school_classes`: `class` is reserved in PHP and `classes`
 * would collide with framework conventions in unpleasant ways.
 */
/**
 * @property string $id
 * @property string $name
 * @property string|null $section
 * @property int|null $capacity
 * @property string $academic_year_id
 * @property AcademicYear|null $academicYear
 * @property Level|null $level
 * @property Room|null $room
 * @property Teacher|null $homeroomTeacher
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SchoolClass extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, SoftDeletes;

    protected $table = 'school_classes';

    protected $fillable = [
        'school_id', 'academic_year_id', 'level_id', 'name', 'section',
        'capacity', 'homeroom_teacher_id', 'room_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Level, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Teacher, $this> */
    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    /** @return HasMany<ClassSubject, $this> */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** Students currently sitting in this class. */
    public function students(): HasManyThrough
    {
        return $this->hasManyThrough(
            Student::class,
            Enrollment::class,
            'school_class_id',
            'id',
            'id',
            'student_id',
        )->where('enrollments.status', 'active');
    }

    /** @return HasMany<TimetableEntry, $this> */
    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function scopeForYear(Builder $query, string $academicYearId): Builder
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    /** Seats left, or null when the class has no declared capacity. */
    public function remainingSeats(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, $this->capacity - $this->enrollments()->where('status', 'active')->count());
    }
}
