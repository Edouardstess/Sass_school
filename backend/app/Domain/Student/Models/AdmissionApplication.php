<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\Academic\Models\Level;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Document\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\AcademicYear;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Enums\ApplicationStatus;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A candidature submitted through the public admissions form.
 *
 * `student_id` is set when the application is converted, which is what makes
 * conversion idempotent: a second attempt finds the link and refuses.
 */
/**
 * @property string $id
 * @property string $reference
 * @property ApplicationStatus $status
 * @property string|null $student_id
 * @property CarbonImmutable|null $birth_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $updated_at
 */
class AdmissionApplication extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, SoftDeletes;

    protected $table = 'admission_applications';

    protected $fillable = [
        'school_id', 'academic_year_id', 'level_id', 'reference',
        'first_name', 'last_name', 'middle_name', 'gender', 'birth_date',
        'birth_place', 'nationality', 'address', 'previous_school',
        'guardian_first_name', 'guardian_last_name', 'guardian_relationship',
        'guardian_email', 'guardian_phone', 'status',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'status' => ApplicationStatus::class,
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return BelongsTo<Level, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function assignedClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'assigned_class_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<AdmissionComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(AdmissionComment::class, 'application_id')->latest();
    }

    /** Supporting files (birth certificate, previous transcripts…). */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isConverted(): bool
    {
        return $this->student_id !== null;
    }
}
