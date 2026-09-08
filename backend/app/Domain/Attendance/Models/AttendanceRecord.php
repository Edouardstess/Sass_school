<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Models;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\TimetableEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\AttendanceStatus;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attendance mark.
 *
 * `timetable_entry_id` is null for daily roll-call and set for per-period
 * attendance; two partial unique indexes keep both shapes free of duplicates.
 */
/**
 * @property string $id
 * @property string $student_id
 * @property AttendanceStatus $status
 * @property bool $is_justified
 * @property CarbonImmutable $attendance_date
 * @property Student|null $student
 */
class AttendanceRecord extends BaseModel
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'school_id', 'student_id', 'school_class_id', 'academic_year_id',
        'timetable_entry_id', 'recorded_by', 'attendance_date', 'status',
        'minutes_late', 'remark', 'is_justified',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'immutable_date',
            'status' => AttendanceStatus::class,
            'minutes_late' => 'integer',
            'is_justified' => 'boolean',
            'parent_notified_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function timetableEntry(): BelongsTo
    {
        return $this->belongsTo(TimetableEntry::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function justifications(): HasMany
    {
        return $this->hasMany(AttendanceJustification::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AttendanceRevision::class)->latest('created_at');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('attendance_date', [$from, $to]);
    }

    public function scopeAbsences(Builder $query): Builder
    {
        return $query->where('status', AttendanceStatus::Absent->value);
    }
}
