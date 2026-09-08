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
 * @property Level|null $level
 * @property Room|null $room
 * @property Teacher|null $homeroomTeacher
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

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }

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
