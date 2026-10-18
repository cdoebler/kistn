<?php

namespace Database\Factories;

use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use App\Models\InventoryEvent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryEvent>
 */
class InventoryEventFactory extends Factory
{
    public function definition(): array
    {
        $action = fake()->randomElement(InventoryActionEnum::cases());

        return [
            'project_id' => Project::factory(),
            'ecosystem' => fake()->randomElement(EcosystemEnum::cases())->value,
            'package_name' => fake()->slug(2),
            'action' => $action->value,
            'old_version' => $action !== InventoryActionEnum::Added ? fake()->semver() : null,
            'new_version' => $action !== InventoryActionEnum::Removed ? fake()->semver() : null,
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
