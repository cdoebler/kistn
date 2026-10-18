<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;

function outdatedPackagesProject(): Project
{
    $project = Project::factory()->create();

    $rows = [
        // name, ecosystem, installed, latest, notified
        ['vendor/fresh', EcosystemEnum::Composer, '1.0.0', '2.0.0', null],
        ['vendor/notified', EcosystemEnum::Composer, '1.0.0', '2.0.0', '2.0.0'],
        ['vendor/new-release', EcosystemEnum::Composer, '1.0.0', '2.1.0', '2.0.0'],
        ['vendor/current', EcosystemEnum::Composer, '2.0.0', '2.0.0', null],
        ['vendor/unknown-latest', EcosystemEnum::Composer, '1.0.0', null, null],
        ['other-ecosystem', EcosystemEnum::Npm, '1.0.0', '2.0.0', null],
    ];

    foreach ($rows as [$name, $ecosystem, $installed, $latest, $notified]) {
        $package = Package::factory()->create(['ecosystem' => $ecosystem->value, 'name' => $name, 'latest_version' => $latest]);
        ProjectPackage::factory()->create([
            'project_id' => $project->id,
            'package_id' => $package->id,
            'version' => $installed,
            'notified_latest_version' => $notified,
        ]);
    }

    $foreign = Package::factory()->create(['ecosystem' => EcosystemEnum::Composer->value, 'name' => 'vendor/foreign', 'latest_version' => '2.0.0']);
    ProjectPackage::factory()->create(['package_id' => $foreign->id, 'version' => '1.0.0']);

    return $project;
}

test('outdatedPackages returns every outdated package of the ecosystem keyed by name, sorted', function (): void {
    expect(outdatedPackagesProject()->outdatedPackages(EcosystemEnum::Composer))->toBe([
        'vendor/fresh' => ['version' => '1.0.0', 'latest_version' => '2.0.0'],
        'vendor/new-release' => ['version' => '1.0.0', 'latest_version' => '2.1.0'],
        'vendor/notified' => ['version' => '1.0.0', 'latest_version' => '2.0.0'],
    ]);
});

test('outdatedPackages with onlyUnnotified skips packages already notified at their current latest_version', function (): void {
    expect(outdatedPackagesProject()->outdatedPackages(EcosystemEnum::Composer, onlyUnnotified: true))->toBe([
        'vendor/fresh' => ['version' => '1.0.0', 'latest_version' => '2.0.0'],
        'vendor/new-release' => ['version' => '1.0.0', 'latest_version' => '2.1.0'],
    ]);
});

test('wantsDeltaDigest follows the owner digest_content setting, defaulting to delta', function (?string $digestContent, bool $expected): void {
    $user = User::factory()->create(['settings' => $digestContent === null ? [] : ['digest_content' => $digestContent]]);

    expect(Project::factory()->create(['user_id' => $user->id])->wantsDeltaDigest())->toBe($expected);
})->with([
    'default' => [null, true],
    'delta' => ['delta', true],
    'full state' => ['full_state', false],
]);
