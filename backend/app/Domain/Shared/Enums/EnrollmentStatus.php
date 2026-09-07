<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Transferred = 'transferred';
    case Withdrawn = 'withdrawn';

    public function isCurrent(): bool
    {
        return $this === self::Active;
    }
}
