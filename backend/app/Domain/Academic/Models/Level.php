<?php

declare(strict_types=1);

namespace App\Domain\Academic\Models;

use App\Domain\Shared\Concerns\Filterable;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A grade level ("6ème", "NS1"). `sequence` orders them for promotion. */
/**
 * @property string $id
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Level extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, SoftDeletes;

    protected $fillable = ['school_id', 'name', 'code', 'sequence'];

    protected function casts(): array
    {
        return ['sequence' => 'integer'];
    }

    /** @return HasMany<SchoolClass, $this> */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }
}
