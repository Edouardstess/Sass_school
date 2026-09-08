<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Student\Models\Guardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guardian
 */
class GuardianResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_alt' => $this->phone_alt,
            'address' => $this->address,
            'occupation' => $this->occupation,
            'preferred_channel' => $this->preferred_channel,
            'has_account' => $this->user_id !== null,

            // Present when reached through a student, describing that link.
            'relationship' => $this->whenPivotLoaded('student_guardian', function (): array {
                $pivot = $this->resource->pivot;

                return [
                    'relationship' => $pivot->relationship,
                    'is_primary' => (bool) $pivot->is_primary,
                    'is_financial_responsible' => (bool) $pivot->is_financial_responsible,
                    'can_pick_up' => (bool) $pivot->can_pick_up,
                ];
            }),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
