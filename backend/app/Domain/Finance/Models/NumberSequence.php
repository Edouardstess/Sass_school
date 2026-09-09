<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * Per-tenant counter behind invoice, receipt and matricule numbering.
 *
 * Read and incremented under `lockForUpdate` inside a transaction, so two
 * simultaneous requests cannot mint the same invoice number.

 *
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class NumberSequence extends BaseModel
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'scope', 'period', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
