<?php

namespace Database\Factories;

use App\Enums\EcosystemEnum;
use App\Models\AdvisoryCache;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvisoryCache>
 */
class AdvisoryCacheFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ecosystem' => fake()->randomElement(EcosystemEnum::cases())->value,
            'name' => fake()->slug(2),
            'version' => fake()->semver(),
            'advisories' => [],
            'checked_at' => now()->subHours(fake()->numberBetween(1, 12)),
            'ttl_expires_at' => now()->addHours(fake()->numberBetween(12, 48)),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'ttl_expires_at' => now()->subHours(fake()->numberBetween(1, 24)),
        ]);
    }

    public function withAdvisories(): static
    {
        return $this->state(fn (array $attributes) => [
            'advisories' => [
                [
                    'id' => 'GHSA-'.fake()->bothify('????-????-????'),
                    'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
                    'summary' => fake()->sentence(),
                ],
            ],
        ]);
    }
}
