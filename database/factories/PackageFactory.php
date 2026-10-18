<?php

namespace Database\Factories;

use App\Enums\EcosystemEnum;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    public function definition(): array
    {
        $ecosystem = fake()->randomElement(EcosystemEnum::cases());

        return [
            'project_id' => 0,
            'ecosystem' => $ecosystem->value,
            'name' => $this->packageNameForEcosystem($ecosystem),
            'latest_version' => null,
        ];
    }

    public function forEcosystem(EcosystemEnum $ecosystem): static
    {
        return $this->state(fn (array $attributes) => [
            'ecosystem' => $ecosystem->value,
            'name' => $this->packageNameForEcosystem($ecosystem),
        ]);
    }

    public function withLatestVersion(string $version): static
    {
        return $this->state(fn (array $attributes) => [
            'latest_version' => $version,
        ]);
    }

    private function packageNameForEcosystem(EcosystemEnum $ecosystem): string
    {
        $uid = substr(bin2hex(random_bytes(4)), 0, 6);

        return match ($ecosystem) {
            EcosystemEnum::Composer => fake()->randomElement(['vendor', 'laravel', 'symfony']).'/'
                .fake()->word().'-'.$uid,
            EcosystemEnum::Npm => '@'.fake()->word().'/'.fake()->word().'-'.$uid,
            EcosystemEnum::WpPlugin, EcosystemEnum::WpTheme => fake()->slug(2).'-'.$uid,
            default => fake()->word().'/'.fake()->word().'-'.$uid,
        };
    }
}
