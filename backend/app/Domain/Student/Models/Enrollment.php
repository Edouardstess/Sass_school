<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Enums\EnrollmentStatus;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's placement in one class for one academic year. */
/**
 * @property string $id
 * @property string $student_id
 * @property string $school_class_id
 * @property string $academic_year_id
 * @property EnrollmentStatus $status
 * @property CarbonImmutable|null $enrolled_on
 * @property SchoolClass|null $schoolClass
 * @property AcademicYear|null $academicYear
 */
class Enrollment extends BaseModel
{
    use BelongsToTenant, HasFactory;

    public const TYPE_NEW = 'new_admission';

    public const TYPE_RE_ENROLLMENT = 're_enrollment';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    protected $fillable = [
        'school_id', 'student_id', 'academic_year_id', 'school_class_id',
        'enrolled_on', 'ended_on', 'status', 'enrollment_type', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'immutable_date',
            'ended_on' => 'immutable_date',
            'status' => EnrollmentStatus::class,
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

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Active->value);
    }
}
