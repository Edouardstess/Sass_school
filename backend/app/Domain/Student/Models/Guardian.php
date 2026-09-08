<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A parent or legal guardian.
 *
 * Many-to-many with students in both directions: a guardian may have several
 * children at the school, and a child may have several responsible adults.
 */
/**
 * @property string $id
 * @property string|null $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property User|null $user
 */
class Guardian extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, SoftDeletes;

    protected $fillable = [
        'school_id', 'user_id', 'first_name', 'last_name', 'gender',
        'email', 'phone', 'phone_alt', 'address', 'occupation',
        'national_id', 'preferred_channel',
    ];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardian')
            ->withPivot(['relationship', 'is_primary', 'is_financial_responsible', 'can_pick_up'])
            ->withTimestamps();
    }

    /** Students this guardian is billed for. */
    public function billedStudents(): BelongsToMany
    {
        return $this->students()->wherePivot('is_financial_responsible', true);
    }
}
