<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case Excused = 'excused';

    /** Counts against the student in attendance statistics. */
    public function isAbsence(): bool
    {
        return $this === self::Absent;
    }

    /** Whether a guardian should be alerted when this status is recorded. */
    public function triggersGuardianAlert(): bool
    {
        return in_array($this, [self::Absent, self::Late], true);
    }

    public function canBeJustified(): bool
    {
        return in_array($this, [self::Absent, self::Late], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Absent => 'Absent',
            self::Late => 'Retard',
            self::Excused => 'Excusé',
        };
    }
}
