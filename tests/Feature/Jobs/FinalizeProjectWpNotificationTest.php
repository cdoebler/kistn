<?php

use App\Enums\EcosystemEnum;
use App\Enums\VulnSourceEnum;
use App\Jobs\FinalizeProjectWpNotificationJob;
use App\Models\NotificationDigestItem;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;
use App\Notifications\ProjectChangesNotification;
use App\Services\NotificationDispatcherService;
use Illuminate\Support\Facades\Notification;

test('FinalizeProjectWpNotification sends notification when outdated packages exist', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => true],
    ]);

    $package = Package::factory()->create([
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'akismet',
        'latest_version' => '5.5.0',
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '5.0.0',
    ]);

    (new FinalizeProjectWpNotificationJob($project, [EcosystemEnum::WpPlugin]))->handle(
        resolve(NotificationDispatcherService::class),
    );

    Notification::assertSentTo($user, ProjectChangesNotification::class);
});

test('FinalizeProjectWpNotification skips when nothing outdated', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => true],
    ]);

    (new FinalizeProjectWpNotificationJob($project, [EcosystemEnum::WpPlugin]))->handle(
        resolve(NotificationDispatcherService::class),
    );

    Notification::assertNothingSent();
});

test('FinalizeProjectWpNotification notifies when findings are newer than last_notified_at', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => true],
        'last_notified_at' => now()->subHours(2),
    ]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create();
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => '1.0.0']);

    VulnFinding::factory()->create([
        'package_id' => $package->id,
        'package_version' => '1.0.0',
        'source' => VulnSourceEnum::ClientAudit->value,
        'first_seen_at' => now()->subMinutes(30),
        'last_seen_at' => now()->subMinutes(30),
    ]);

    (new FinalizeProjectWpNotificationJob($project, [EcosystemEnum::WpPlugin]))->handle(
        resolve(NotificationDispatcherService::class),
    );

    Notification::assertSentTo($user, ProjectChangesNotification::class);
});

test('FinalizeProjectWpNotification does not notify when findings are older than last_notified_at', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => true],
        'last_notified_at' => now()->subMinutes(30),
    ]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create();
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => '1.0.0']);

    VulnFinding::factory()->create([
        'package_id' => $package->id,
        'package_version' => '1.0.0',
        'source' => VulnSourceEnum::ClientAudit->value,
        'first_seen_at' => now()->subHours(2),
        'last_seen_at' => now()->subHours(2),
    ]);

    (new FinalizeProjectWpNotificationJob($project, [EcosystemEnum::WpPlugin]))->handle(
        resolve(NotificationDispatcherService::class),
    );

    Notification::assertNothingSent();
});

test('FinalizeProjectWpNotification skips outdated packages already notified in delta mode', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => [
        'notification_mode' => 'immediate',
        'digest_content' => 'delta',
    ]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => true],
        'last_notified_at' => now()->subHour(),
    ]);

    $package = Package::factory()->create([
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'stale-plugin',
        'latest_version' => '5.0.0',
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '4.0.0',
        'notified_latest_version' => '5.0.0',
    ]);

    (new FinalizeProjectWpNotificationJob($project, [EcosystemEnum::WpPlugin]))->handle(
        resolve(NotificationDispatcherService::class),
    );

    Notification::assertNothingSent();
});

test('FinalizeProjectWpNotification includes outdated packages not yet notified in delta mode', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => [
        'notification_mode' => 'immediate',
        'digest_content' => 'delta',
    ]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => true],
        'last_notified_at' => now()->subHour(),
    ]);

    $package = Package::factory()->create([
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'fresh-plugin',
        'latest_version' => '5.0.0',
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '4.0.0',
    ]);

    (new FinalizeProjectWpNotificationJob($project, [EcosystemEnum::WpPlugin]))->handle(
        resolve(NotificationDispatcherService::class),
    );

    Notification::assertSentTo($user, ProjectChangesNotification::class);
});

/**
 * A still-outdated package must not re-enter the daily digest just because something
 * bumped the shared global packages.updated_at (another project's push, WpOutdatedJob,
 * a privacy migration).
 *
 * @return array{0: User, 1: Project, 2: Package, 3: ProjectPackage}
 */
function wpDeltaDigestFixture(?string $notifiedLatestVersion): array
{
    $user = User::factory()->create(['settings' => [
        'notification_mode' => 'daily_digest',
        'digest_content' => 'delta',
    ]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'last_notified_at' => now()->subDay(),
    ]);
    $package = Package::factory()->create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'acme-plugin',
        'latest_version' => '4.1.0',
    ]);
    $projectPackage = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '3.3.11',
        'notified_latest_version' => $notifiedLatestVersion,
    ]);

    return [$user, $project, $package, $projectPackage];
}

function runWpFinalize(Project $project): void
{
    (new FinalizeProjectWpNotificationJob($project->fresh() ?? $project, [EcosystemEnum::WpPlugin]))->handle(
        resolve(NotificationDispatcherService::class),
    );
}

test('FinalizeProjectWpNotification does not re-enter an already-notified outdated package when packages.updated_at is bumped by an unrelated write', function (): void {
    [, $project] = wpDeltaDigestFixture(notifiedLatestVersion: '4.1.0');
    // package updated_at is now() — newer than last_notified_at, as after another project's push

    runWpFinalize($project);

    expect(NotificationDigestItem::where('project_id', $project->id)->exists())->toBeFalse();
});

test('FinalizeProjectWpNotification notifies an outdated package once, then not again after later updated_at bumps', function (): void {
    [, $project, $package, $projectPackage] = wpDeltaDigestFixture(notifiedLatestVersion: null);

    runWpFinalize($project);

    expect(NotificationDigestItem::where('project_id', $project->id)->count())->toBe(1)
        ->and($projectPackage->fresh()?->notified_latest_version)->toBe('4.1.0');

    NotificationDigestItem::query()->delete();
    $project->update(['last_notified_at' => now()->subDay()]);
    $package->touch();

    runWpFinalize($project);

    expect(NotificationDigestItem::where('project_id', $project->id)->exists())->toBeFalse();
});

test('FinalizeProjectWpNotification notifies again when latest_version moves past the notified one', function (): void {
    [, $project, $package, $projectPackage] = wpDeltaDigestFixture(notifiedLatestVersion: '4.1.0');
    $package->update(['latest_version' => '4.1.1']);

    runWpFinalize($project);

    $item = NotificationDigestItem::where('project_id', $project->id)->sole();
    expect($item->data)->toBe([['name' => 'acme-plugin', 'current' => '3.3.11', 'latest' => '4.1.1', 'type' => 'major']])
        ->and($projectPackage->fresh()?->notified_latest_version)->toBe('4.1.1');
});
