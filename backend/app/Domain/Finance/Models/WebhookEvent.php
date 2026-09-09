<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;

/**
 * A raw inbound provider webhook.
 *
 * Not tenant-scoped: a webhook arrives before we know which school it concerns.
 * The unique key on (provider, external_event_id) is what makes replays and
 * provider retries idempotent.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $updated_at
 */
class WebhookEvent extends BaseModel
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider', 'external_event_id', 'event_type', 'payload',
        'signature', 'ip_address', 'status', 'processed_at', 'error', 'attempts',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }
}
