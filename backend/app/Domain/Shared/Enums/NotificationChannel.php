<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case InApp = 'in_app';

    /** Channels that leave the platform and therefore cost money / can fail. */
    public function isExternal(): bool
    {
        return $this !== self::InApp;
    }

    /** The feature flag that must be on for this channel to be usable. */
    public function featureFlag(): ?string
    {
        return match ($this) {
            self::Sms => 'sms',
            self::WhatsApp => 'whatsapp',
            default => null,
        };
    }

    /** The recipient field this channel addresses. */
    public function recipientAttribute(): string
    {
        return match ($this) {
            self::Email => 'email',
            self::Sms, self::WhatsApp => 'phone',
            self::InApp => 'id',
        };
    }
}
