<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Teacher\Models\Teacher;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekly lesson slot.
 *
 * `day_of_week` follows ISO-8601 (1 = Monday), matching Carbon's `dayOfWeekIso`
 * so no translation is needed when matching an attendance date to a slot.
 */
/**
 * @property string $id
 * @property string $school_class_id
 * @property string|null $teacher_id
 * @property string|null $room_id
 * @property string $starts_at
 * @property string $ends_at
 * @property SchoolClass|null $schoolClass
 * @property Subject|null $subject
 * @property Teacher|null $teacher
 * @property Room|null $room
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TimetableEntry extends BaseModel
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'school_id', 'academic_year_id', 'school_class_id', 'subject_id',
        'teacher_id', 'room_id', 'day_of_week', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
