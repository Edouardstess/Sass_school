<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\ValueObjects\DeliveryResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Twilio, over its REST API directly rather than through the SDK.
 *
 * Sending is one authenticated form POST; pulling in the whole SDK for that
 * would add a dependency surface without adding capability.
 *
 * Failures come back as a `DeliveryResult`, never as an exception: a bad
 * number for one parent must not abort a fan-out to the rest of the class.
 */
final class TwilioSmsSender implements SmsSender
{
    private const API_BASE = 'https://api.twilio.com/2010-04-01';

    public function provider(): string
    {
        return 'twilio';
    }

    public function isConfigured(): bool
    {
        return config('services.sms.twilio.sid') !== null
            && config('services.sms.twilio.token') !== null
            && config('services.sms.twilio.from') !== null;
    }

    public function send(string $to, string $message): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failed($this->provider(), 'Twilio credentials are not configured.');
        }

        try {
            $sid = (string) config('services.sms.twilio.sid');

            $response = Http::withBasicAuth($sid, (string) config('services.sms.twilio.token'))
                ->asForm()
                ->timeout(15)
                ->post(self::API_BASE."/Accounts/{$sid}/Messages.json", [
                    'To' => $this->normalise($to),
                    'From' => (string) config('services.sms.twilio.from'),
                    'Body' => $message,
                ]);

            if ($response->failed()) {
                return DeliveryResult::failed(
                    $this->provider(),
                    (string) data_get($response->json(), 'message', 'HTTP '.$response->status()),
                    $response->json() ?? [],
                );
            }

            return DeliveryResult::sent(
                $this->provider(),
                (string) data_get($response->json(), 'sid'),
                $response->json() ?? [],
            );
        } catch (Throwable $e) {
            return DeliveryResult::failed($this->provider(), $e->getMessage());
        }
    }

    /**
     * Best-effort E.164. A bare 8-digit Haitian number is prefixed with +509;
     * anything already carrying a country code is left alone.
     */
    private function normalise(string $phone): string
    {
        $digits = preg_replace('/[^0-9+]/', '', $phone) ?? $phone;

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (strlen($digits) === 8) {
            return '+509'.$digits;
        }

        return '+'.ltrim($digits, '0');
    }
}
