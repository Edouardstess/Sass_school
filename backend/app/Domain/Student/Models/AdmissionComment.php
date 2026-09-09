<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal review note on an application. Never shown to the applicant.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AdmissionComment extends BaseModel
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'application_id', 'user_id', 'body'];

    /** @return BelongsTo<AdmissionApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
