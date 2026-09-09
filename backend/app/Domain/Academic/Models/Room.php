<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property string $id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Room extends BaseModel
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['school_id', 'name', 'building', 'capacity', 'is_active'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_active' => 'boolean'];
    }
}
