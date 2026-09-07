<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\School\Models\School;
use App\Domain\Student\Models\Guardian;
use App\Domain\Student\Models\Student;
use App\Domain\Teacher\Models\Teacher;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An account.
 *
 * Users are *not* tenant-scoped by the global scope: authentication has to
 * find a user before a tenant is known, and platform admins have no tenant at
 * all. Tenant safety for users is enforced in UserPolicy and in the queries
 * that list them, both of which filter on the active school explicitly.
 *
 * @property string $id
 * @property string|null $school_id
 * @property string $email
 * @property string $status
 * @property int $permissions_version
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVITED = 'invited';

    public const STATUS_DISABLED = 'disabled';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'school_id', 'first_name', 'last_name', 'email', 'phone',
        'avatar_path', 'password', 'locale', 'timezone', 'status',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'password' => 'hashed',
            // Encrypted at rest: a database dump must not yield working TOTP
            // seeds or usable recovery codes.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    // ------------------------------------------------------------- identity

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->school_id === null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    // -------------------------------------------------------- authorization

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role')
            ->withPivot(['assigned_at', 'assigned_by']);
    }

    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission')
            ->withPivot(['granted', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Effective permission names: everything the user's roles grant, plus
     * individual grants, minus individual revocations.
     *
     * Resolution is delegated to PermissionRegistry, which caches the result
     * per request and in Redis keyed by `permissions_version` — so revoking a
     * role takes effect on the very next request rather than after a TTL.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return app(PermissionRegistry::class)->forUser($this);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }

    /** @param list<string> $permissions */
    public function hasAnyPermission(array $permissions): bool
    {
        return array_intersect($permissions, $this->permissionNames()) !== [];
    }

    public function hasRole(string $name): bool
    {
        return $this->roles->contains(fn (Role $role): bool => $role->name === $name);
    }

    /** Invalidate cached permissions after a role or grant change. */
    public function bumpPermissionsVersion(): void
    {
        $this->increment('permissions_version');
        app(PermissionRegistry::class)->forget($this);
    }

    // ------------------------------------------------------------ relations

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** The teacher profile attached to this account, when there is one. */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
