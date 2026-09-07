<?php

declare(strict_types=1);

namespace App\Domain\Student\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\BaseModel;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal review note on an application. Never shown to the applicant. */
class AdmissionComment extends BaseModel
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'application_id', 'user_id', 'body'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(AdmissionApplication::class, 'application_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
