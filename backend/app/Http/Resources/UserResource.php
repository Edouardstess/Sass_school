<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
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
            'avatar_url' => $this->avatar_path,
            'status' => $this->status,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'email_verified' => $this->email_verified_at !== null,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'school_id' => $this->school_id,
            'is_platform_admin' => $this->isPlatformAdmin(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn (Role $role): array => [
                'name' => $role->name,
                'label' => $role->label,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
