<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Notification\Contracts\WhatsAppSender;
use App\Domain\Notification\ValueObjects\DeliveryResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Development WhatsApp driver; see LogSmsSender for the rationale. */
final class LogWhatsAppSender implements WhatsAppSender
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
        Log::info('[whatsapp] outbound message', [
            'to' => substr($to, 0, 4).'****',
            'preview' => Str::limit($message, 80),
        ]);

        return DeliveryResult::sent($this->provider(), 'log-'.Str::uuid());
    }
}
