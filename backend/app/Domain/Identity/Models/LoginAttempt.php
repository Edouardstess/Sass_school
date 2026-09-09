<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;

/**
 * Every authentication attempt, successful or not.
 *
 * Feeds two things: the brute-force lockout (counting recent failures for an
 * e-mail/IP pair) and the security view of the audit trail.

 *
 * @property CarbonImmutable|null $created_at
 */
class LoginAttempt extends BaseModel
{
    public const REASON_INVALID_CREDENTIALS = 'invalid_credentials';

    public const REASON_ACCOUNT_DISABLED = 'account_disabled';

    public const REASON_SCHOOL_SUSPENDED = 'school_suspended';

    public const REASON_TWO_FACTOR_REQUIRED = 'two_factor_required';

    public const REASON_TWO_FACTOR_FAILED = 'two_factor_failed';

    public const REASON_THROTTLED = 'throttled';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'school_id', 'email', 'ip_address', 'user_agent',
        'successful', 'failure_reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
