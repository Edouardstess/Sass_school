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

/**
 * @property string $id
 * @property string $name
 * @property numeric-string $default_coefficient
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Subject extends BaseModel
{
    use BelongsToTenant, Filterable, HasFactory, SoftDeletes;

    protected $fillable = ['school_id', 'name', 'code', 'default_coefficient', 'color', 'is_active'];

    protected function casts(): array
    {
        return [
            'default_coefficient' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ClassSubject, $this> */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }
}
