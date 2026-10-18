<?php

namespace Database\Factories;

use App\Enums\SeverityEnum;
use App\Enums\VulnSourceEnum;
use App\Models\Package;
use App\Models\VulnFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VulnFinding>
 */
class VulnFindingFactory extends Factory
{
    public function definition(): array
    {
        $firstSeen = fake()->dateTimeBetween('-60 days', '-7 days');

        return [
            'package_id' => Package::factory(),
            'package_version' => fake()->semver(),
            'advisory_id' => 'GHSA-'.fake()->bothify('????-????-????'),
            'severity' => fake()->randomElement(SeverityEnum::cases())->value,
            'source' => fake()->randomElement(VulnSourceEnum::cases())->value,
            'first_seen_at' => $firstSeen,
            'last_seen_at' => fake()->dateTimeBetween($firstSeen, 'now'),
        ];
    }

    public function clientReported(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => VulnSourceEnum::ClientAudit->value,
        ]);
    }
}
