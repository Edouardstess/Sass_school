<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\NotificationChannel;
use App\Domain\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message body with `{{placeholder}}` slots.
 *
 * Not tenant-scoped by the global scope: a lookup must be able to fall back
 * from the school's own override to the platform default in one query.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class NotificationTemplate extends BaseModel
{
    protected $fillable = [
        'school_id', 'key', 'channel', 'locale', 'subject', 'body',
        'available_variables', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'available_variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The school's override if it has one, otherwise the platform default.
     * Ordering by `school_id NULLS LAST` puts the tenant row first.
     */
    public function scopeResolveFor(
        Builder $query,
        ?string $schoolId,
        string $key,
        NotificationChannel $channel,
        string $locale,
    ): Builder {
        return $query
            ->where('key', $key)
            ->where('channel', $channel->value)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('school_id')->orWhere('school_id', $schoolId))
            ->orderByRaw('school_id IS NULL');
    }
}
