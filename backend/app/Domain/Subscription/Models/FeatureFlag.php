<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Models;

use App\Domain\School\Models\School;
use App\Domain\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kill switch. A row with a null `school_id` is the platform default; a row
 * carrying a school_id overrides it for that tenant only.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FeatureFlag extends BaseModel
{
    protected $fillable = ['school_id', 'key', 'enabled', 'description'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
