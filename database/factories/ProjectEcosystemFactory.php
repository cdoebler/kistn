<?php

namespace Database\Factories;

use App\Enums\EcosystemEnum;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectEcosystem>
 */
class ProjectEcosystemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'ecosystem' => fake()->randomElement(EcosystemEnum::cases())->value,
            'current_hash' => Str::random(64),
            'last_checked_at' => now()->subMinutes(fake()->numberBetween(10, 120)),
            'last_pushed_at' => now()->subHours(fake()->numberBetween(1, 48)),
            'package_count' => fake()->numberBetween(5, 200),
            'is_stale' => false,
        ];
    }

    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_stale' => true,
            'last_checked_at' => now()->subDays(fake()->numberBetween(3, 14)),
        ]);
    }

    public function forEcosystem(EcosystemEnum $ecosystem): static
    {
        return $this->state(fn (array $attributes) => [
            'ecosystem' => $ecosystem->value,
        ]);
    }
}
