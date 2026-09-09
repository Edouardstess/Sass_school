<?php

declare(strict_types=1);

namespace App\Domain\School\Models;

use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * A single typed configuration value, namespaced by `group`.
 *
 * Values are stored as JSONB and cast back through `typed()` so the API can
 * return a real boolean/integer rather than the string "1".
 */
/**
 * @property string $id
 * @property string $school_id
 * @property string $group
 * @property string $key
 * @property array<string, mixed>|scalar|null $value
 * @property string $type
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SchoolSetting extends BaseModel
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'group', 'key', 'value', 'type'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** The value coerced to the type declared on the row. */
    public function typed(): mixed
    {
        // JSONB round-trips scalars wrapped in a single-element structure only
        // when they were stored that way; unwrap defensively.
        $raw = is_array($this->value) && array_key_exists('value', $this->value)
            ? $this->value['value']
            : $this->value;

        return match ($this->type) {
            'int' => (int) $raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOL),
            'decimal' => (float) $raw,
            'json' => $raw,
            default => $raw === null ? null : (string) $raw,
        };
    }
}
