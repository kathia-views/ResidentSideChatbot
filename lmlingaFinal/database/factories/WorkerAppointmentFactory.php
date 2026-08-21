<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\StaffRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerAppointment>
 */
class WorkerAppointmentFactory extends Factory
{
    protected $model = WorkerAppointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => fake()->randomElement(StaffRole::ALL),
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => fake()->randomElement(['Zone 1', 'Zone 2', 'Zone 3', 'Zone 4', 'Zone 5']),
            'date_appointed' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'end_of_appointment' => null,
            'is_current' => true,
        ];
    }

    public function current(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_current' => true,
            'end_of_appointment' => null,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_current' => false,
            'end_of_appointment' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
        ]);
    }

    public function role(string $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => StaffRole::normalize($role) ?? StaffRole::BHW,
        ]);
    }
}
