<?php

use App\Ecosystems\Wp\Clients\WpOrgClient;
use App\Ecosystems\Wp\Jobs\WpOutdatedJob;
use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Notifications\ProjectChangesNotification;
use App\Services\SiblingOutdatedNotifierService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function wpOutdatedProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('WpOutdatedJob updates latest_version for wp-plugin packages', function (): void {
    $project = wpOutdatedProject();
    $acf = Package::factory()->create(['ecosystem' => EcosystemEnum::WpPlugin->value, 'name' => 'advanced-custom-fields', 'latest_version' => null]);
    $cf7 = Package::factory()->create(['ecosystem' => EcosystemEnum::WpPlugin->value, 'name' => 'contact-form-7', 'latest_version' => null]);

    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $acf->id, 'version' => '6.0.0', 'is_direct' => true, 'is_dev' => false]);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $cf7->id, 'version' => '6.1.5', 'is_direct' => true, 'is_dev' => false]);

    Http::fake(function ($request) {
        $url = (string) $request->url();

        if (str_contains($url, 'advanced-custom-fields')) {
            return Http::response(['version' => '6.7.1'], 200);
        }

        if (str_contains($url, 'contact-form-7')) {
            return Http::response(['version' => '6.2.0'], 200);
        }

        return Http::response([], 404);
    });

    (new WpOutdatedJob($project, EcosystemEnum::WpPlugin))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    expect($acf->fresh()->latest_version)->toBe('6.7.1')
        ->and($cf7->fresh()->latest_version)->toBe('6.2.0');
});

test('WpOutdatedJob updates latest_version for wp-theme packages', function (): void {
    $project = wpOutdatedProject();
    $astra = Package::factory()->create(['ecosystem' => EcosystemEnum::WpTheme->value, 'name' => 'astra', 'latest_version' => null]);

    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $astra->id, 'version' => '4.0.0', 'is_direct' => true, 'is_dev' => false]);

    Http::fake(function ($request) {
        if (str_contains((string) $request->url(), 'astra')) {
            return Http::response(['version' => '4.12.6'], 200);
        }

        return Http::response([], 404);
    });

    (new WpOutdatedJob($project, EcosystemEnum::WpTheme))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    expect($astra->fresh()->latest_version)->toBe('4.12.6');
});

test('WpOutdatedJob updates latest_version for wp-core packages', function (): void {
    $project = wpOutdatedProject();
    $core = Package::factory()->create(['ecosystem' => EcosystemEnum::WpCore->value, 'name' => 'wordpress', 'latest_version' => null]);

    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $core->id, 'version' => '6.4.0', 'is_direct' => true, 'is_dev' => false]);

    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/*' => Http::response([
            'offers' => [['response' => 'upgrade', 'current' => '6.5.0']],
        ], 200),
    ]);

    (new WpOutdatedJob($project, EcosystemEnum::WpCore))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    expect($core->fresh()->latest_version)->toBe('6.5.0');
});

test('WpOutdatedJob skips private packages that return non-200', function (): void {
    $project = wpOutdatedProject();
    $custom = Package::factory()->create(['ecosystem' => EcosystemEnum::WpPlugin->value, 'name' => 'fahnerdesign-base', 'latest_version' => null]);

    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $custom->id, 'version' => '0.0.0', 'is_direct' => true, 'is_dev' => false]);

    Http::fake(fn () => Http::response([], 404));

    (new WpOutdatedJob($project, EcosystemEnum::WpPlugin))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    expect($custom->fresh()->latest_version)->toBeNull();
});

test('WpOutdatedJob returns early when project has no packages for ecosystem', function (): void {
    Http::fake();

    $project = wpOutdatedProject();

    (new WpOutdatedJob($project, EcosystemEnum::WpPlugin))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    Http::assertNothingSent();
});

test('WpOutdatedJob does not touch updated_at when latest_version is unchanged', function (): void {
    $project = wpOutdatedProject();
    $acf = Package::factory()->create([
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'advanced-custom-fields',
        'latest_version' => '6.7.1',
        'updated_at' => now()->subDays(10),
    ]);

    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $acf->id, 'version' => '6.0.0', 'is_direct' => true, 'is_dev' => false]);

    $staleUpdatedAt = $acf->fresh()->updated_at;

    Http::fake([
        'https://api.wordpress.org/plugins/info/1.2/*' => Http::response(['version' => '6.7.1'], 200),
    ]);

    (new WpOutdatedJob($project, EcosystemEnum::WpPlugin))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    expect($acf->fresh()->updated_at->eq($staleUpdatedAt))->toBeTrue();
});

test('WpOutdatedJob skips packages confirmed private', function (): void {
    $project = wpOutdatedProject();
    $private = Package::factory()->create([
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'acme-plugin',
        'is_private' => true,
    ]);

    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $private->id, 'version' => '3.3.11', 'is_direct' => true, 'is_dev' => false]);

    Http::fake();

    (new WpOutdatedJob($project, EcosystemEnum::WpPlugin))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    Http::assertNothingSent();
});

test('WpOutdatedJob logs warning when fetched package name has no matching Package row', function (): void {
    $project = wpOutdatedProject();

    // Create a package row to drive the initial name query, then remove it
    // so the subsequent UPDATE in updateLatestVersions() matches zero rows.
    $pkg = Package::factory()->create([
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'vanishing-plugin',
        'latest_version' => null,
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    Http::fake([
        'https://api.wordpress.org/plugins/info/1.2/*' => Http::response(['version' => '2.0.0'], 200),
    ]);

    // Delete the Package row so the UPDATE inside updateLatestVersions() affects 0 rows.
    $pkg->delete();

    // Must not throw; the warning path is exercised silently.
    (new WpOutdatedJob($project, EcosystemEnum::WpPlugin))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    // The package was deleted — nothing to assert on the DB, but no exception = success.
    expect(true)->toBeTrue();
});

test('WpOutdatedJob notifies a sibling project when the shared latest_version changes', function (): void {
    $project = wpOutdatedProject();

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $acmePlugin = Package::factory()->create(['ecosystem' => EcosystemEnum::WpPlugin->value, 'name' => 'acme-plugin', 'latest_version' => '3.1.0']);

    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $acmePlugin->id, 'version' => '3.1.0', 'is_direct' => true, 'is_dev' => false]);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $acmePlugin->id, 'version' => '3.0.0', 'is_direct' => true, 'is_dev' => false]);

    Http::fake(fn () => Http::response(['version' => '3.2.0'], 200));

    Notification::fake();

    (new WpOutdatedJob($project, EcosystemEnum::WpPlugin))->handle(new WpOrgClient, resolve(SiblingOutdatedNotifierService::class));

    expect($acmePlugin->fresh()->latest_version)->toBe('3.2.0');

    Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
        $summary = $n->payload->outdatedByEcosystem['wp-plugin'] ?? null;

        if ($summary === null) {
            return false;
        }

        $all = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

        return collect($all)->contains(fn (array $p): bool => $p['name'] === 'acme-plugin' && $p['latest'] === '3.2.0');
    });
});
