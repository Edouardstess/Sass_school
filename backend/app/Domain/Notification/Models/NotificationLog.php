<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Shared\Enums\NotificationChannel;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One delivery attempt on one channel. Retained for support and billing. */
class NotificationLog extends BaseModel
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'school_id', 'notification_id', 'channel', 'recipient', 'status',
        'provider', 'provider_message_id', 'error', 'attempts', 'sent_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
