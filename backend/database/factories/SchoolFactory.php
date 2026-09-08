<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\School\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<School> */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    public function definition(): array
    {
        $name = 'Collège '.fake()->lastName();

        return [
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'name' => $name,
            'legal_name' => $name.' S.A.',
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+509 '.fake()->numerify('#### ####'),
            'city' => 'Port-au-Prince',
            'country' => 'HT',
            'locale' => 'fr',
            'timezone' => 'America/Port-au-Prince',
            'currency' => 'HTG',
            'status' => School::STATUS_ACTIVE,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => School::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => 'Non-payment',
        ]);
    }

    public function trial(): static
    {
        return $this->state(fn (): array => ['status' => School::STATUS_TRIAL]);
    }
}
