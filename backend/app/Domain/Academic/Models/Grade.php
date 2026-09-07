<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Student\Models\Student;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One student's mark on one assessment.
 *
 * A null score with `is_absent` is a missed assessment, which is *not* a zero:
 * the calculator excludes it from the weighted average rather than dragging
 * the student down for an illness.
 */
class Grade extends BaseModel
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'school_id', 'assessment_id', 'student_id', 'recorded_by',
        'score', 'is_absent', 'is_excused', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'is_absent' => 'boolean',
            'is_excused' => 'boolean',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(GradeRevision::class)->latest('created_at');
    }

    /** Whether this row contributes to an average. */
    public function counts(): bool
    {
        return $this->score !== null && ! $this->is_absent;
    }
}
