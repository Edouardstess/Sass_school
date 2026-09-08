<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\School\Models\School;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'matricule' => 'STU-'.now()->year.'-'.Str::upper(Str::random(6)),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(['male', 'female']),
            'birth_date' => fake()->dateTimeBetween('-18 years', '-6 years')->format('Y-m-d'),
            'birth_place' => 'Port-au-Prince',
            'nationality' => 'Haïtienne',
            'address' => fake()->streetAddress(),
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => '+509 '.fake()->numerify('#### ####'),
            'emergency_contact_relation' => 'Parent',
            'status' => Student::STATUS_ACTIVE,
            'enrolled_on' => now()->subMonths(2)->toDateString(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => Student::STATUS_ARCHIVED]);
    }
}
