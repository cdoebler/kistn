<?php

namespace Database\Factories;

use App\Enums\EcosystemEnum;
use App\Models\AdvisorySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvisorySnapshot>
 */
class AdvisorySnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ecosystem' => fake()->randomElement([EcosystemEnum::WpPlugin, EcosystemEnum::WpTheme, EcosystemEnum::WpCore])->value,
            'name' => fake()->slug(2),
            'advisories' => [],
            'checked_at' => now()->subHours(fake()->numberBetween(1, 12)),
            'expires_at' => now()->addHours(fake()->numberBetween(12, 48)),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function forEcosystem(EcosystemEnum $ecosystem, string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'ecosystem' => $ecosystem->value,
            'name' => $name,
        ]);
    }

    public function withAdvisories(): static
    {
        return $this->state(fn (array $attributes) => [
            'advisories' => [
                [
                    'title' => fake()->sentence(),
                    'fixed_in' => '2.0.0',
                    'cvss' => ['score' => 7.5],
                    'references' => ['wpvulndb' => [fake()->uuid()]],
                ],
            ],
        ]);
    }
}
