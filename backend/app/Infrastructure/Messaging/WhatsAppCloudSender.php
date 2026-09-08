<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Notification\Contracts\WhatsAppSender;
use App\Domain\Notification\ValueObjects\DeliveryResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp Business Cloud API (Meta Graph).
 *
 * Note the constraint this integration carries: outside a 24-hour customer
 * service window Meta only delivers *pre-approved template* messages, so a
 * spontaneous absence alert needs an approved template registered on the
 * business account. Free-form text is sent here; when the window has closed
 * Meta rejects it and the failure is recorded on the delivery log rather than
 * being silently swallowed.
 */
final class WhatsAppCloudSender implements WhatsAppSender
{
    private const GRAPH_VERSION = 'v21.0';

    public function provider(): string
    {
        return 'whatsapp_cloud';
    }

    public function isConfigured(): bool
    {
        return config('services.whatsapp.phone_number_id') !== null
            && config('services.whatsapp.token') !== null;
    }

    public function send(string $to, string $message): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failed($this->provider(), 'WhatsApp credentials are not configured.');
        }

        try {
            $phoneNumberId = (string) config('services.whatsapp.phone_number_id');

            $response = Http::withToken((string) config('services.whatsapp.token'))
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post(sprintf('https://graph.facebook.com/%s/%s/messages', self::GRAPH_VERSION, $phoneNumberId), [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $this->normalise($to),
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $message],
                ]);

            if ($response->failed()) {
                return DeliveryResult::failed(
                    $this->provider(),
                    (string) data_get($response->json(), 'error.message', 'HTTP '.$response->status()),
                    $response->json() ?? [],
                );
            }

            return DeliveryResult::sent(
                $this->provider(),
                (string) data_get($response->json(), 'messages.0.id'),
                $response->json() ?? [],
            );
        } catch (Throwable $e) {
            return DeliveryResult::failed($this->provider(), $e->getMessage());
        }
    }

    /** The Graph API wants digits only, with the country code and no '+'. */
    private function normalise(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? $phone;

        return strlen($digits) === 8 ? '509'.$digits : $digits;
    }
}
