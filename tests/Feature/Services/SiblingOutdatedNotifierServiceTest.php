<?php

use App\Enums\EcosystemEnum;
use App\Models\NotificationDigestItem;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Notifications\ProjectChangesNotification;
use App\Services\SiblingOutdatedNotifierService;
use Illuminate\Support\Facades\Notification;

test('it does nothing when there are no changed package names', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    resolve(SiblingOutdatedNotifierService::class)->notify($actingProject, EcosystemEnum::Composer, []);

    Notification::assertNothingSent();
});

test('it skips a changed name with no global catalog entry', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    // No global (project_id=0) Package row exists for this name — simulates a private
    // package, which resolves to an empty package-id list and must not crash the lookup.
    resolve(SiblingOutdatedNotifierService::class)->notify($actingProject, EcosystemEnum::WpPlugin, ['private-plugin']);

    Notification::assertNothingSent();
});

test('it notifies a sibling project whose installed version is behind the new latest_version', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'acme-plugin', 'latest_version' => '3.2.0']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '3.2.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '3.1.0']);

    resolve(SiblingOutdatedNotifierService::class)->notify($actingProject, EcosystemEnum::WpPlugin, ['acme-plugin']);

    Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
        $summary = $n->payload->outdatedByEcosystem['wp-plugin'] ?? null;

        if ($summary === null) {
            return false;
        }

        $all = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

        return collect($all)->contains(fn (array $p): bool => $p['name'] === 'acme-plugin' && $p['latest'] === '3.2.0');
    });
});

test('it does not notify a sibling whose installed version already matches latest_version', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'acme-plugin', 'latest_version' => '3.2.0']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '3.2.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '3.2.0']);

    resolve(SiblingOutdatedNotifierService::class)->notify($actingProject, EcosystemEnum::WpPlugin, ['acme-plugin']);

    Notification::assertNothingSent();
});

test('it does not notify the acting project itself', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $actingProject = Project::factory()->create(['user_id' => $user->id, 'settings' => ['notify_immediately' => true]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'acme-plugin', 'latest_version' => '3.2.0']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '3.1.0']);

    resolve(SiblingOutdatedNotifierService::class)->notify($actingProject, EcosystemEnum::WpPlugin, ['acme-plugin']);

    Notification::assertNothingSent();
});

test('it respects the sibling own notification preference, independent of the acting project', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'daily_digest']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => false]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'acme-plugin', 'latest_version' => '3.2.0']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '3.2.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '3.1.0']);

    resolve(SiblingOutdatedNotifierService::class)->notify($actingProject, EcosystemEnum::WpPlugin, ['acme-plugin']);

    // Digest mode — no immediate notification, but a digest item should be queued instead.
    Notification::assertNothingSent();
    expect(NotificationDigestItem::where('project_id', $sibling->id)->where('type', 'outdated')->exists())->toBeTrue();
});

test('it does not re-notify a sibling already notified of the current latest_version', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'acme-plugin', 'latest_version' => '3.2.0']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '3.2.0']);
    ProjectPackage::factory()->create([
        'project_id' => $sibling->id,
        'package_id' => $package->id,
        'version' => '3.1.0',
        'notified_latest_version' => '3.2.0',
    ]);

    resolve(SiblingOutdatedNotifierService::class)->notify($actingProject, EcosystemEnum::WpPlugin, ['acme-plugin']);

    Notification::assertNothingSent();
});
