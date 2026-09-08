<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\ValueObjects\DeliveryResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development SMS driver: writes to the log instead of sending.
 *
 * Deliberately reports success, because in development the *flow* is what is
 * under test — the notification log, the delivery record, the retry path. It
 * is never selected in production, where SMS_DRIVER names a real vendor and a
 * missing vendor makes the channel unavailable rather than silently fake.
 */
final class LogSmsSender implements SmsSender
{
    public function provider(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $message): DeliveryResult
    {
        Log::channel(config('logging.default'))->info('[sms] outbound message', [
            'to' => $this->maskPhone($to),
            'length' => mb_strlen($message),
            'preview' => Str::limit($message, 80),
        ]);

        return DeliveryResult::sent($this->provider(), 'log-'.Str::uuid());
    }

    /** Phone numbers are personal data; logs keep only enough to trace. */
    private function maskPhone(string $phone): string
    {
        return strlen($phone) <= 4 ? '****' : substr($phone, 0, 4).str_repeat('*', strlen($phone) - 6).substr($phone, -2);
    }
}
