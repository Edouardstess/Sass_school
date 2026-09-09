<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\School\Models\School;
use App\Domain\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A bundle of permissions.
 *
 * A role with a null `school_id` is a system template shared by every tenant;
 * a role with a school_id is one that school defined for itself.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Role extends BaseModel
{
    public const PLATFORM_SUPER_ADMIN = 'platform_super_admin';

    public const SCHOOL_OWNER = 'school_owner';

    public const SCHOOL_ADMIN = 'school_admin';

    public const PRINCIPAL = 'principal';

    public const TEACHER = 'teacher';

    public const ACCOUNTANT = 'accountant';

    public const PARENT = 'parent';

    public const STUDENT = 'student';

    protected $fillable = ['school_id', 'name', 'label', 'description', 'is_platform_role', 'is_system'];

    protected function casts(): array
    {
        return [
            'is_platform_role' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role');
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** System templates plus the roles this school defined itself. */
    public function scopeAvailableTo(Builder $query, ?string $schoolId): Builder
    {
        return $query->where(function (Builder $inner) use ($schoolId): void {
            $inner->whereNull('school_id');

            if ($schoolId !== null) {
                $inner->orWhere('school_id', $schoolId);
            }
        });
    }

    /** Roles a tenant may assign — never the platform role. */
    public function scopeAssignableInTenant(Builder $query): Builder
    {
        return $query->where('is_platform_role', false);
    }
}
