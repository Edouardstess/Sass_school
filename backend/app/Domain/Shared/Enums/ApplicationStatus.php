<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Admission pipeline.
 *
 *   SUBMITTED → UNDER_REVIEW → ACCEPTED → ENROLLED
 *                            ↘ REJECTED
 *   (an applicant may WITHDRAW at any point before enrolment)
 */
enum ApplicationStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Enrolled = 'enrolled';
    case Withdrawn = 'withdrawn';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Accepted, self::Rejected, self::Withdrawn],
            self::UnderReview => [self::Accepted, self::Rejected, self::Withdrawn],
            self::Accepted => [self::Enrolled, self::Withdrawn],
            self::Rejected, self::Enrolled, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::Accepted], true);
    }
}
