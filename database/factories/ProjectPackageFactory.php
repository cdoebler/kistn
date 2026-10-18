<?php

namespace Database\Factories;

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectPackage>
 */
class ProjectPackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'package_id' => Package::factory(),
            'version' => fake()->semver(),
            'is_direct' => fake()->boolean(60),
            'is_dev' => fake()->boolean(30),
            'is_active' => null,
            'depth' => fake()->optional(0.7)->numberBetween(0, 5),
        ];
    }

    public function forEcosystem(EcosystemEnum $ecosystem): static
    {
        return $this->state(fn (array $attributes) => [
            'package_id' => Package::factory()->forEcosystem($ecosystem),
        ]);
    }

    public function direct(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_direct' => true,
            'depth' => 0,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
