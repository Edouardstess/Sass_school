<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\Contracts\WhatsAppSender;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Notification\ValueObjects\DeliveryResult;
use App\Domain\School\Models\School;
use App\Domain\Shared\Enums\NotificationChannel;
use App\Domain\Subscription\Services\PlanLimitEnforcer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One entry point for informing a person of something.
 *
 * Responsibilities, in order:
 *   1. de-duplicate (a `dedupe_key` makes a re-run of a sweep harmless);
 *   2. decide which channels apply — the user's preferences intersected with
 *      what the school's plan and feature flags actually allow;
 *   3. render each channel's template;
 *   4. deliver, recording every attempt on `notification_logs`.
 *
 * A failure on one channel never aborts the others, and a failure for one
 * recipient never aborts a fan-out: a wrong phone number must not stop two
 * hundred e-mails.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly PlanLimitEnforcer $features,
        private readonly SmsSender $sms,
        private readonly WhatsAppSender $whatsApp,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  list<NotificationChannel>|null  $channels  null = every channel the
     *                                                    user and plan allow
     */
    public function send(
        User $recipient,
        string $key,
        array $variables,
        ?Model $subject = null,
        ?array $channels = null,
        ?string $dedupeKey = null,
        string $priority = 'normal',
    ): ?Notification {
        $school = $this->tenant->school() ?? $recipient->school;

        if ($school === null) {
            return null;
        }

        $locale = $recipient->locale ?? $school->locale ?? (string) config('app.locale');
        $channels ??= $this->channelsFor($recipient, $school, $key);

        $inApp = $this->renderer->resolve($school->id, $key, NotificationChannel::InApp, $locale);
        $rendered = $inApp !== null
            ? $this->renderer->render($inApp, $variables)
            : ['subject' => $key, 'body' => ''];

        try {
            $notification = Notification::query()->create([
                'school_id' => $school->id,
                'user_id' => $recipient->id,
                'template_id' => $inApp?->id,
                'key' => $key,
                'title' => $rendered['subject'] ?? $key,
                'body' => $rendered['body'],
                'data' => $variables,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'priority' => $priority,
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already sent. This is the mechanism that makes the reminder
            // sweep safe to re-run.
            return null;
        }

        foreach ($channels as $channel) {
            if ($channel === NotificationChannel::InApp) {
                $this->logDelivery($notification, $channel, (string) $recipient->id, NotificationLog::STATUS_DELIVERED);

                continue;
            }

            $this->deliverExternal($notification, $recipient, $school, $channel, $key, $variables, $locale);
        }

        return $notification;
    }

    /**
     * Channels this user should actually receive on.
     *
     * The intersection of three things: what the user asked for, what their
     * profile can reach (no phone number means no SMS), and what the school's
     * plan and feature flags permit.
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(User $recipient, School $school, string $key): array
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $recipient->id)
            ->where('key', $key)
            ->first();

        $channels = [];

        foreach (NotificationChannel::cases() as $channel) {
            // Default to in-app and e-mail when the user has expressed no
            // preference: silence by default would be worse than a message.
            $wanted = $preference !== null
                ? $preference->allows($channel)
                : in_array($channel, [NotificationChannel::InApp, NotificationChannel::Email], true);

            if (! $wanted) {
                continue;
            }

            $flag = $channel->featureFlag();

            if ($flag !== null && ! $this->features->featureEnabled($school, $flag)) {
                continue;
            }

            if ($channel === NotificationChannel::Email && blank($recipient->email)) {
                continue;
            }

            if (in_array($channel, [NotificationChannel::Sms, NotificationChannel::WhatsApp], true)
                && blank($recipient->phone)) {
                continue;
            }

            $channels[] = $channel;
        }

        return $channels;
    }

    /** @param array<string, scalar|null> $variables */
    private function deliverExternal(
        Notification $notification,
        User $recipient,
        School $school,
        NotificationChannel $channel,
        string $key,
        array $variables,
        string $locale,
    ): void {
        $template = $this->renderer->resolve($school->id, $key, $channel, $locale);

        if ($template === null) {
            // No template for this channel is a configuration gap, not an
            // error: record it so it is visible, and move on.
            $this->logDelivery($notification, $channel, '', NotificationLog::STATUS_SKIPPED, 'No template configured.');

            return;
        }

        $rendered = $this->renderer->render($template, $variables);
        $address = $channel === NotificationChannel::Email ? (string) $recipient->email : (string) $recipient->phone;

        $log = $this->logDelivery($notification, $channel, $address, NotificationLog::STATUS_QUEUED);

        try {
            $result = match ($channel) {
                NotificationChannel::Email => $this->sendEmail($address, $rendered, $school),
                NotificationChannel::Sms => $this->sms->send($address, $rendered['body']),
                NotificationChannel::WhatsApp => $this->whatsApp->send($address, $rendered['body']),
                NotificationChannel::InApp => null,
            };

            if ($result === null) {
                return;
            }

            $log->forceFill([
                'status' => $result->successful ? NotificationLog::STATUS_SENT : NotificationLog::STATUS_FAILED,
                'provider' => $result->provider,
                'provider_message_id' => $result->messageId,
                'error' => $result->error,
                'attempts' => $log->attempts + 1,
                'sent_at' => $result->successful ? now() : null,
            ])->save();
        } catch (Throwable $e) {
            // One channel failing must not take the others down with it.
            Log::warning('Notification delivery failed', [
                'channel' => $channel->value,
                'notification' => $notification->id,
                'error' => $e->getMessage(),
            ]);

            $log->forceFill([
                'status' => NotificationLog::STATUS_FAILED,
                'error' => $e->getMessage(),
                'attempts' => $log->attempts + 1,
            ])->save();
        }
    }

    /** @param array{subject: string|null, body: string} $rendered */
    private function sendEmail(string $address, array $rendered, School $school): DeliveryResult
    {
        Mail::raw($rendered['body'], function ($message) use ($address, $rendered, $school): void {
            $message->to($address)
                ->subject($rendered['subject'] ?? $school->name)
                ->from(
                    (string) config('mail.from.address'),
                    $school->name,
                );
        });

        return DeliveryResult::sent('mail');
    }

    private function logDelivery(
        Notification $notification,
        NotificationChannel $channel,
        string $recipient,
        string $status,
        ?string $error = null,
    ): NotificationLog {
        return NotificationLog::query()->create([
            'school_id' => $notification->school_id,
            'notification_id' => $notification->id,
            'channel' => $channel->value,
            'recipient' => $recipient,
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === NotificationLog::STATUS_DELIVERED ? now() : null,
        ]);
    }
}
