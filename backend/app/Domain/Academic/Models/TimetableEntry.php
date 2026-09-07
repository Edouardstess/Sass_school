<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Teacher\Models\Teacher;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekly lesson slot.
 *
 * `day_of_week` follows ISO-8601 (1 = Monday), matching Carbon's `dayOfWeekIso`
 * so no translation is needed when matching an attendance date to a slot.
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

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
