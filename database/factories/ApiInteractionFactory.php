<?php

namespace Database\Factories;

use App\Models\ApiInteraction;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiInteraction>
 */
class ApiInteractionFactory extends Factory
{
    public function definition(): array
    {
        $project = Project::factory()->create();
        $token = $project->createToken('test-token');

        return [
            'project_id' => $project->id,
            'token_id' => $token->accessToken->id,
            'endpoint' => fake()->randomElement([
                'GET /api/projects/{id}/ecosystems/{eco}/hash',
                'POST /api/projects/{id}/ecosystems/{eco}/inventory',
                'GET /api/projects/{id}/packages',
                'GET /api/projects/{id}/events',
                'GET /api/projects/{id}/findings',
            ]),
            'ecosystem' => fake()->optional()->randomElement(['composer', 'npm', 'wp-plugin']),
            'hash_matched' => fake()->optional()->boolean(),
            'status' => fake()->randomElement(['200', '202', '401', '404']),
            'occurred_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ];
    }
}
