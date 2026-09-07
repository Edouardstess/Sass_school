<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The intent to inform one user of one thing.
 *
 * `dedupe_key` is the natural key that makes fan-out jobs re-runnable: the
 * J-3 reminder sweep can run twice without a parent receiving two messages.
 */
class Notification extends BaseModel
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'user_id', 'template_id', 'key', 'title', 'body',
        'data', 'subject_type', 'subject_id', 'priority', 'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
