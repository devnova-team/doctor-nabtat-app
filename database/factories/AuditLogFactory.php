<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'clinic_id' => Clinic::factory(),
            'action' => fake()->word(),
            'severity' => 'info',
            'metadata' => [],
            'ip_address' => fake()->ipv4(),
        ];
    }
}
