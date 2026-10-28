<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\DoctorSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorSchedule>
 */
class DoctorScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => fn (): int => Clinic::factory()->create()->id,
            'doctor_id' => fn (array $attributes): int => User::factory()->doctor()->create([
                'clinic_id' => $attributes['clinic_id'],
            ])->id,
            'weekday' => fake()->numberBetween(1, 7),
            'opens_at' => '09:00:00',
            'closes_at' => '17:00:00',
            'slot_duration_minutes' => 30,
            'is_active' => true,
        ];
    }
}
