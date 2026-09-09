<?php

declare(strict_types=1);

namespace App\Domain\School\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Student\Models\Student;
use App\Domain\Subscription\Models\Subscription;
use Carbon\CarbonImmutable;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant.
 *
 * Note this model does NOT use BelongsToTenant: it *is* the tenant, and it is
 * queried by the platform layer across all schools.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string $status
 */
/**
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $updated_at
 */
class School extends BaseModel
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'slug', 'name', 'legal_name', 'email', 'phone', 'website',
        'address_line1', 'address_line2', 'city', 'state', 'country', 'postal_code',
        'logo_path', 'locale', 'timezone', 'currency', 'status',
    ];

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ state

    /** Whether users of this school are allowed to sign in and use the product. */
    public function isOperational(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true)
            && $this->deleted_at === null;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    // ------------------------------------------------------------ relations

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** @return HasMany<AcademicYear, $this> */
    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    /** @return HasMany<SchoolSetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(SchoolSetting::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The one subscription that is currently in force. The partial unique
     * index on the table guarantees there is at most one.
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['trialing', 'active', 'past_due']);
    }

    /** @return HasOne<AcademicYear, $this> */
    public function activeAcademicYear(): HasOne
    {
        return $this->hasOne(AcademicYear::class)->where('status', 'active');
    }

    protected static function newFactory(): SchoolFactory
    {
        return SchoolFactory::new();
    }
}
