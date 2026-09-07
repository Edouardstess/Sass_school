<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every SchoolFlow model.
 *
 * UUID primary keys are used throughout rather than auto-increment integers:
 * identifiers appear in URLs, and sequential ids would let anyone enumerate a
 * tenant's students by counting up. Laravel's `HasUuids` produces *ordered*
 * UUIDs, so the non-guessability does not cost index locality on insert.
 *
 * `$guarded = []` is deliberately NOT used anywhere; every model declares an
 * explicit `$fillable`, because mass assignment is one of the OWASP items this
 * system is built to resist.
 */
abstract class BaseModel extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';
}
