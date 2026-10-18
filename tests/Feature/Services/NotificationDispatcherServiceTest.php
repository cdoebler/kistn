<?php

use App\Enums\EcosystemEnum;
use App\Enums\NotificationModeEnum;
use App\Models\NotificationDigestItem;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Notifications\ProjectChangesNotification;
use App\Services\NotificationDispatcherService;
use App\ValueObjects\OutdatedSummaryValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function dispatcherProject(bool $notifyImmediately = false, NotificationModeEnum $mode = NotificationModeEnum::Immediate): array
{
    $user = User::factory()->create(['settings' => ['notification_mode' => $mode->value]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => $notifyImmediately],
    ]);

    return [$user, $project];
}

function outdatedPayload(): ProjectNotificationPayloadValueObject
{
    return new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [], patch: [], other: [],
            ),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );
}

test('sends immediate notification when project has notify_immediately=true', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: true, mode: NotificationModeEnum::DailyDigest);

    resolve(NotificationDispatcherService::class)->dispatch($project, outdatedPayload());

    Notification::assertSentTo($user, ProjectChangesNotification::class);
    expect(NotificationDigestItem::count())->toBe(0);
});

test('sends immediate notification when user mode is immediate', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: false, mode: NotificationModeEnum::Immediate);

    resolve(NotificationDispatcherService::class)->dispatch($project, outdatedPayload());

    Notification::assertSentTo($user, ProjectChangesNotification::class);
});

test('writes digest items when user mode is daily_digest and project does not override', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: false, mode: NotificationModeEnum::DailyDigest);

    resolve(NotificationDispatcherService::class)->dispatch($project, outdatedPayload());

    Notification::assertNothingSent();
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBeGreaterThan(0);
});

test('updates last_notified_at after sending immediate notification', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: true);

    expect($project->last_notified_at)->toBeNull();

    resolve(NotificationDispatcherService::class)->dispatch($project, outdatedPayload());

    expect($project->fresh()->last_notified_at)->not->toBeNull();
});

test('skips sending when payload is empty', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: true);

    $emptyPayload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    resolve(NotificationDispatcherService::class)->dispatch($project, $emptyPayload);

    Notification::assertNothingSent();
    expect(NotificationDigestItem::count())->toBe(0);
});

test('skips sending when payload contains only empty summaries', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: true);

    $emptyPayload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(major: [], minor: [], patch: [], other: []),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    resolve(NotificationDispatcherService::class)->dispatch($project, $emptyPayload);

    Notification::assertNothingSent();
    expect(NotificationDigestItem::count())->toBe(0);
});

test('skips sending when project has no user', function (): void {
    Notification::fake();

    // Create a project whose user has been deleted so user relation is null
    $user = User::factory()->create(['settings' => ['notification_mode' => NotificationModeEnum::Immediate->value]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => true],
    ]);

    $user->delete();
    $project->unsetRelation('user');

    resolve(NotificationDispatcherService::class)->dispatch($project, outdatedPayload());

    Notification::assertNothingSent();
    expect(NotificationDigestItem::count())->toBe(0);
});

test('digest mode writes outdated digest item with correct type and ecosystem', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: false, mode: NotificationModeEnum::DailyDigest);

    resolve(NotificationDispatcherService::class)->dispatch($project, outdatedPayload());

    expect(
        NotificationDigestItem::where('project_id', $project->id)
            ->where('type', 'outdated')
            ->where('ecosystem', 'composer')
            ->exists()
    )->toBeTrue();
});

test('digest outdated item labels each package row with its own bucket type', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: false, mode: NotificationModeEnum::DailyDigest);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/major', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [['name' => 'vendor/minor', 'current' => '1.0.0', 'latest' => '1.1.0']],
                patch: [['name' => 'vendor/patch', 'current' => '1.0.0', 'latest' => '1.0.1']],
                other: [['name' => 'vendor/other', 'current' => '1.0.0', 'latest' => 'foo']],
            ),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    resolve(NotificationDispatcherService::class)->dispatch($project, $payload);

    $rows = NotificationDigestItem::where('project_id', $project->id)->where('type', 'outdated')->sole()->data;

    expect($rows)->toBe([
        ['name' => 'vendor/major', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major'],
        ['name' => 'vendor/minor', 'current' => '1.0.0', 'latest' => '1.1.0', 'type' => 'minor'],
        ['name' => 'vendor/patch', 'current' => '1.0.0', 'latest' => '1.0.1', 'type' => 'patch'],
        ['name' => 'vendor/other', 'current' => '1.0.0', 'latest' => 'foo', 'type' => 'other'],
    ]);
});

test('digest mode writes vuln digest item for findings', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: false, mode: NotificationModeEnum::DailyDigest);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [
            'composer' => [
                ['package_name' => 'vendor/pkg', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-xxxx-yyyy-zzzz', 'severity' => 'high'],
            ],
        ],
        triggeredAt: CarbonImmutable::now(),
    );

    resolve(NotificationDispatcherService::class)->dispatch($project, $payload);

    Notification::assertNothingSent();

    expect(
        NotificationDigestItem::where('project_id', $project->id)
            ->where('type', 'vuln')
            ->where('ecosystem', 'composer')
            ->exists()
    )->toBeTrue();
});

test('digest mode skips empty outdated summary without creating digest item', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: false, mode: NotificationModeEnum::DailyDigest);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(major: [], minor: [], patch: [], other: []),
        ],
        findingsByEcosystem: [
            'npm' => [
                ['package_name' => 'lodash', 'package_version' => '4.17.20', 'advisory_id' => 'GHSA-test-0001-aaaa', 'severity' => 'low'],
            ],
        ],
        triggeredAt: CarbonImmutable::now(),
    );

    resolve(NotificationDispatcherService::class)->dispatch($project, $payload);

    expect(
        NotificationDigestItem::where('project_id', $project->id)
            ->where('type', 'outdated')
            ->exists()
    )->toBeFalse();

    expect(
        NotificationDigestItem::where('project_id', $project->id)
            ->where('type', 'vuln')
            ->exists()
    )->toBeTrue();
});

test('digest mode sets project last_notified_at', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: false, mode: NotificationModeEnum::DailyDigest);

    expect($project->last_notified_at)->toBeNull();

    resolve(NotificationDispatcherService::class)->dispatch($project, outdatedPayload());

    expect($project->fresh()->last_notified_at)->not->toBeNull();
});

test('digest mode skips empty findings without creating vuln digest item', function (): void {
    Notification::fake();

    [$user, $project] = dispatcherProject(notifyImmediately: false, mode: NotificationModeEnum::DailyDigest);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [], patch: [], other: [],
            ),
        ],
        findingsByEcosystem: [
            'npm' => [],
        ],
        triggeredAt: CarbonImmutable::now(),
    );

    resolve(NotificationDispatcherService::class)->dispatch($project, $payload);

    expect(
        NotificationDigestItem::where('project_id', $project->id)
            ->where('type', 'vuln')
            ->exists()
    )->toBeFalse();

    expect(
        NotificationDigestItem::where('project_id', $project->id)
            ->where('type', 'outdated')
            ->exists()
    )->toBeTrue();
});

test('marks reported outdated packages as notified without touching project_packages.updated_at', function (NotificationModeEnum $mode, bool $notifyImmediately): void {
    Notification::fake();

    [, $project] = dispatcherProject(notifyImmediately: $notifyImmediately, mode: $mode);
    $package = Package::factory()->create(['ecosystem' => EcosystemEnum::Composer->value, 'name' => 'vendor/foo', 'latest_version' => '2.0.0']);
    $otherEcosystem = Package::factory()->create(['ecosystem' => EcosystemEnum::Npm->value, 'name' => 'vendor/foo', 'latest_version' => '2.0.0']);
    $projectPackage = ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => '1.0.0']);
    $otherProjectPackage = ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $otherEcosystem->id, 'version' => '1.0.0']);
    $foreignProjectPackage = ProjectPackage::factory()->create(['package_id' => $package->id, 'version' => '1.0.0']);

    $bucketPackages = [];
    foreach (['vendor/minor' => '1.1.0', 'vendor/patch' => '1.0.1', 'vendor/other' => 'dev-main'] as $name => $latest) {
        $bucketPackage = Package::factory()->create(['ecosystem' => EcosystemEnum::Composer->value, 'name' => $name, 'latest_version' => $latest]);
        $bucketPackages[$latest] = ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $bucketPackage->id, 'version' => '1.0.0']);
    }
    DB::table('project_packages')->update(['updated_at' => now()->subDay()]);

    resolve(NotificationDispatcherService::class)->dispatch($project, new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [['name' => 'vendor/minor', 'current' => '1.0.0', 'latest' => '1.1.0']],
                patch: [['name' => 'vendor/patch', 'current' => '1.0.0', 'latest' => '1.0.1']],
                other: [['name' => 'vendor/other', 'current' => '1.0.0', 'latest' => 'dev-main']],
            ),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    ));

    foreach ($bucketPackages as $latest => $bucketProjectPackage) {
        expect($bucketProjectPackage->fresh()?->notified_latest_version)->toBe($latest);
    }

    $projectPackage->refresh();
    expect($projectPackage->notified_latest_version)->toBe('2.0.0')
        ->and($projectPackage->updated_at->lt(now()->subHours(23)))->toBeTrue()
        ->and($otherProjectPackage->fresh()?->notified_latest_version)->toBeNull()
        ->and($foreignProjectPackage->fresh()?->notified_latest_version)->toBeNull();
})->with([
    'daily digest' => [NotificationModeEnum::DailyDigest, false],
    'immediate' => [NotificationModeEnum::Immediate, true],
]);
