<?php

namespace Database\Factories;

use App\Models\NotificationDigestItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDigestItem>
 */
class NotificationDigestItemFactory extends Factory
{
    public function definition(): array
    {
        $user = User::factory()->create();

        return [
            'user_id' => $user->id,
            'project_id' => Project::factory()->create(['user_id' => $user->id])->id,
            'type' => fake()->randomElement(['outdated', 'vuln']),
            'ecosystem' => fake()->randomElement(['composer', 'npm', 'wp-plugin']),
            'data' => [],
            'created_at' => now(),
        ];
    }
}
