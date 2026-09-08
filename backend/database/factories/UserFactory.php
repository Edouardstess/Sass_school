<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\School\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+509 '.fake()->numerify('#### ####'),
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'status' => User::STATUS_ACTIVE,
            'remember_token' => Str::random(10),
            'permissions_version' => 1,
        ];
    }

    /** A platform super admin has no school of their own. */
    public function platformAdmin(): static
    {
        return $this->state(fn (): array => ['school_id' => null])
            ->afterCreating(fn (User $user) => $this->attachRole($user, Role::PLATFORM_SUPER_ADMIN));
    }

    /** Attach one of the seeded system roles by name. */
    public function withRole(string $roleName): static
    {
        return $this->afterCreating(fn (User $user) => $this->attachRole($user, $roleName));
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => User::STATUS_DISABLED]);
    }

    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['AAAAA-BBBBB', 'CCCCC-DDDDD'],
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function attachRole(User $user, string $roleName): void
    {
        $role = Role::query()
            ->where('name', $roleName)
            ->availableTo($user->school_id)
            ->first();

        if ($role !== null) {
            $user->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);
            $user->bumpPermissionsVersion();
        }
    }
}
