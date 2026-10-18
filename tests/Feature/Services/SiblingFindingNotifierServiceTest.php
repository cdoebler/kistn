<?php

use App\Enums\EcosystemEnum;
use App\Models\NotificationDigestItem;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Notifications\ProjectChangesNotification;
use App\Services\SiblingFindingNotifierService;
use Illuminate\Support\Facades\Notification;

test('it does nothing when there are no new findings', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    resolve(SiblingFindingNotifierService::class)->notify($actingProject, EcosystemEnum::Composer, []);

    Notification::assertNothingSent();
});

test('it skips a finding for a private package with no global catalog entry', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    // No global (project_id=0) Package row exists for this name — simulates a private package,
    // which resolves to null in $packagesByName and must not crash the sibling lookup.
    resolve(SiblingFindingNotifierService::class)->notify($actingProject, EcosystemEnum::WpPlugin, [
        ['package_name' => 'private-plugin', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-private', 'severity' => 'high'],
    ]);

    Notification::assertNothingSent();
});

test('it notifies a sibling project holding the same package at the same version', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/shared']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '1.0.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '1.0.0']);

    resolve(SiblingFindingNotifierService::class)->notify($actingProject, EcosystemEnum::Composer, [
        ['package_name' => 'vendor/shared', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-shared', 'severity' => 'high'],
    ]);

    Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
        $findings = $n->payload->findingsByEcosystem['composer'] ?? [];

        return collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-shared');
    });
});

test('it does not notify a sibling on a different installed version', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/shared']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '1.0.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '2.0.0']);

    resolve(SiblingFindingNotifierService::class)->notify($actingProject, EcosystemEnum::Composer, [
        ['package_name' => 'vendor/shared', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-shared', 'severity' => 'high'],
    ]);

    Notification::assertNothingSent();
});

test('it does not notify the acting project itself', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $actingProject = Project::factory()->create(['user_id' => $user->id, 'settings' => ['notify_immediately' => true]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/shared']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '1.0.0']);

    resolve(SiblingFindingNotifierService::class)->notify($actingProject, EcosystemEnum::Composer, [
        ['package_name' => 'vendor/shared', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-shared', 'severity' => 'high'],
    ]);

    Notification::assertNothingSent();
});

test('it notifies a sibling on any version for a wildcard finding', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'wildcard-pkg']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '1.0.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '9.9.9']);

    resolve(SiblingFindingNotifierService::class)->notify($actingProject, EcosystemEnum::Npm, [
        ['package_name' => 'wildcard-pkg', 'package_version' => '*', 'advisory_id' => 'GHSA-wild', 'severity' => 'medium'],
    ]);

    Notification::assertSentTo($siblingUser, ProjectChangesNotification::class);
});

test('it respects the sibling own notification preference, independent of the acting project', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'daily_digest']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => false]]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/shared']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $package->id, 'version' => '1.0.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '1.0.0']);

    resolve(SiblingFindingNotifierService::class)->notify($actingProject, EcosystemEnum::Composer, [
        ['package_name' => 'vendor/shared', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-shared', 'severity' => 'high'],
    ]);

    // Digest mode — no immediate notification, but a digest item should be queued instead.
    Notification::assertNothingSent();
    expect(NotificationDigestItem::where('project_id', $sibling->id)->where('type', 'vuln')->exists())->toBeTrue();
});

test('it batches multiple findings for the same sibling into a single notification', function (): void {
    Notification::fake();

    $actingProject = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $packageA = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/a']);
    $packageB = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/b']);

    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $packageA->id, 'version' => '1.0.0']);
    ProjectPackage::factory()->create(['project_id' => $actingProject->id, 'package_id' => $packageB->id, 'version' => '2.0.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $packageA->id, 'version' => '1.0.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $packageB->id, 'version' => '2.0.0']);

    resolve(SiblingFindingNotifierService::class)->notify($actingProject, EcosystemEnum::Composer, [
        ['package_name' => 'vendor/a', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-a', 'severity' => 'high'],
        ['package_name' => 'vendor/b', 'package_version' => '2.0.0', 'advisory_id' => 'GHSA-b', 'severity' => 'medium'],
    ]);

    Notification::assertSentToTimes($siblingUser, ProjectChangesNotification::class, 1);
    Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
        $findings = $n->payload->findingsByEcosystem['composer'] ?? [];
        $advisoryIds = collect($findings)->pluck('advisory_id')->all();

        return in_array('GHSA-a', $advisoryIds, true) && in_array('GHSA-b', $advisoryIds, true);
    });
});
