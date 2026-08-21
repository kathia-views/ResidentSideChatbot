<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resident>
 */
class ResidentFactory extends Factory
{
    protected $model = Resident::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $seq = fake()->unique()->numberBetween(1, 999);

        return [
            'household_id' => Household::factory(),
            'member_no' => 'MB-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'relation' => fake()->randomElement(['Head', 'Spouse', 'Son', 'Daughter']),
            'birthday' => fake()->date(),
            'sex' => fake()->randomElement(['Male', 'Female']),
            'relationship_status' => fake()->randomElement(['Single', 'Married', 'Widowed', 'Separated', 'Live-in']),
            'occupation' => fake()->randomElement(['Farmer', 'Nurse', 'None / N/A', 'Student']),
            'monthly_income' => fake()->randomElement(['None / N/A', 'Below 5,000', '30,000 – 49,999']),
            'religion' => fake()->randomElement(['Roman Catholic', 'Islam', 'None']),
            'education' => fake()->randomElement(['College Graduate', 'High School Graduate', 'N/A']),
            'fp_user' => fake()->randomElement(['Yes', 'No', 'N/A']),
            'philhealth' => null,
            'disability' => null,
            'disability_others' => null,
            'medical_history' => null,
            'medical_others' => null,
        ];
    }
}
