<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\NotificationChannel;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-user, per-event opt-in for each channel. */
class NotificationPreference extends BaseModel
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'user_id', 'key', 'email', 'sms', 'whatsapp', 'in_app'];

    protected function casts(): array
    {
        return [
            'email' => 'boolean',
            'sms' => 'boolean',
            'whatsapp' => 'boolean',
            'in_app' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(NotificationChannel $channel): bool
    {
        return (bool) $this->getAttribute($channel->value);
    }
}
