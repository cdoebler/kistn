<?php

namespace Database\Seeders;

use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use App\Enums\SeverityEnum;
use App\Enums\VulnSourceEnum;
use App\Models\InventoryEvent;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\ProjectPackage;
use App\Models\VulnFinding;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProjectSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Project::all()->each(function (Project $project): void {
            $project->createToken('default');

            $this->seedEcosystem($project, EcosystemEnum::Composer);
            $this->seedEcosystem($project, EcosystemEnum::Npm);
        });
    }

    private function seedEcosystem(Project $project, EcosystemEnum $ecosystem): void
    {
        $hash = Str::random(64);

        $ecosystemState = ProjectEcosystem::create([
            'project_id' => $project->id,
            'ecosystem' => $ecosystem->value,
            'current_hash' => $hash,
            'last_checked_at' => now()->subMinutes(30),
            'last_pushed_at' => now()->subHours(2),
            'package_count' => 0,
            'is_stale' => false,
        ]);

        $packages = Package::factory(fake()->numberBetween(15, 40))
            ->forEcosystem($ecosystem)
            ->create();

        $installedVersions = $packages->mapWithKeys(fn (Package $pkg) => [$pkg->id => fake()->semver()]);

        $projectPackages = $packages->map(fn (Package $pkg) => ProjectPackage::create([
            'project_id' => $project->id,
            'package_id' => $pkg->id,
            'version' => $installedVersions[$pkg->id],
            'is_direct' => fake()->boolean(60),
            'is_dev' => fake()->boolean(30),
            'is_active' => null,
            'depth' => fake()->optional(0.7)->numberBetween(0, 5),
        ]));

        $ecosystemState->update(['package_count' => $projectPackages->count()]);

        InventoryEvent::insert(
            $packages->take(5)->map(fn (Package $pkg) => [
                'uuid' => (string) Str::uuid(),
                'project_id' => $project->id,
                'ecosystem' => $ecosystem->value,
                'package_name' => $pkg->name,
                'action' => InventoryActionEnum::Added->value,
                'old_version' => null,
                'new_version' => $installedVersions[$pkg->id],
                'occurred_at' => now()->subDays(fake()->numberBetween(1, 30)),
            ])->all()
        );

        $vulnerable = $packages->random(min(3, $packages->count()));

        foreach ($vulnerable as $pkg) {
            VulnFinding::create([
                'package_id' => $pkg->id,
                'package_version' => $installedVersions[$pkg->id],
                'advisory_id' => 'GHSA-'.fake()->bothify('????-????-????'),
                'severity' => fake()->randomElement(SeverityEnum::cases())->value,
                'source' => VulnSourceEnum::ClientAudit->value,
                'first_seen_at' => now()->subDays(7),
                'last_seen_at' => now()->subHours(2),
            ]);
        }
    }
}
