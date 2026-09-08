<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Shared\Models\BaseModel;
use App\Domain\Teacher\Models\Teacher;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Subject X is taught to class Y by teacher Z, with coefficient C."
 *
 * This is the row GradePolicy consults: a teacher may only enter marks for a
 * (class, subject) pair they are actually assigned to, no matter what
 * permissions their role carries.
 */
/**
 * @property string $id
 * @property string $school_class_id
 * @property string $subject_id
 * @property string|null $teacher_id
 * @property numeric-string $coefficient
 * @property Subject|null $subject
 * @property Teacher|null $teacher
 */
class ClassSubject extends BaseModel
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'school_id', 'school_class_id', 'subject_id', 'teacher_id',
        'coefficient', 'weekly_hours', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'coefficient' => 'decimal:2',
            'weekly_hours' => 'integer',
            'is_active' => 'boolean',
        ];
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

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function scopeTaughtBy(Builder $query, string $teacherId): Builder
    {
        return $query->where('teacher_id', $teacherId);
    }
}
