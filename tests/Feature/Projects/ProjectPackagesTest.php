<?php

use App\Enums\EcosystemEnum;
use App\Enums\SeverityEnum;
use App\Enums\VulnSourceEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $project = Project::factory()->create();
    $this->get(route('projects.packages', $project))->assertRedirect(route('login'));
});

test('project owner can view packages page', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->get(route('projects.packages', $project))->assertOk();
});

test('non-owner cannot view packages page', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other);
    $this->get(route('projects.packages', $project))->assertForbidden();
});

test('empty state shown when project has no packages', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->get(route('projects.packages', $project))
        ->assertOk()
        ->assertSee('No packages yet');
});

test('packages for selected ecosystem are shown', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg1 = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/alpha']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkg1->id]);

    $pkg2 = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => '@scope/beta']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkg2->id]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertSee('vendor/alpha')
        ->assertDontSee('@scope/beta');
});

test('ecosystem tab defaults to first ecosystem with packages', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create();
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkg->id]);

    $this->actingAs($user);

    $component = Livewire::test('pages::projects.packages', ['project' => $project]);

    expect($component->get('ecosystem'))->toBe('npm');
});

test('direct packages show Direct label', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create();
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'is_direct' => true,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertSee('Direct');
});

test('latest version shown in packages table when available', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'name' => 'vendor/pkg',
        'latest_version' => '2.0.0',
    ]);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.0.0',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertSee('2.0.0');
});

test('dash shown when latest version is null', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'name' => 'vendor/nolat',
        'latest_version' => null,
    ]);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.0.0',
    ]);

    $this->actingAs($user);

    // Page renders without error (no exception for null latest_version)
    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertOk();
});

test('vulnerability badge shown for package with active finding', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/vulnerable']);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-test-high-0001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertSee('High');
});

test('a finding for a different installed version does not show a badge', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/patched']);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '2.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-test-high-0002',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertDontSee('High');
});

test('clicking a package row shows its detail modal', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'name' => 'vendor/detail-pkg',
        'latest_version' => '2.0.0',
    ]);
    $package = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.2.3',
        'is_direct' => true,
        'depth' => 0,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $package->uuid)
        ->assertSet('selectedPackageUuid', $package->uuid)
        ->assertSee('vendor/detail-pkg')
        ->assertSee('1.2.3')
        ->assertSee('2.0.0')
        ->assertSee('packagist.org/packages/vendor/detail-pkg');
});

test('package detail modal shows active vuln findings', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/detail-vuln']);
    $package = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-test-modal-0001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $package->uuid)
        ->assertSee('GHSA-test-modal-0001')
        ->assertSee('High');
});

test('closePackageDetail clears selected package', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/close-test']);
    $package = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.0.0',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $package->uuid)
        ->assertSet('selectedPackageUuid', $package->uuid)
        ->call('closePackageDetail')
        ->assertSet('selectedPackageUuid', null);
});

test('private badge shown inline with package name for wp-plugin ecosystem', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'name' => 'my-plugin',
        'is_private' => true,
    ]);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'wp-plugin')
        ->assertSee('my-plugin')
        ->assertSee('Private');
});

test('confirmed public package shows no private badge in table', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'name' => 'public-plugin',
        'is_private' => false,
    ]);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'wp-plugin')
        ->assertSee('public-plugin')
        ->assertDontSee('Private');
});

test('markPackagePrivate sets is_private and advisory_checked_at on per-project package', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'project_id' => $project->id,
        'name' => 'unknown-plugin',
        'is_private' => null,
        'advisory_checked_at' => null,
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'wp-plugin')
        ->call('openPackageDetail', $pp->uuid)
        ->call('markPackagePrivate');

    $pkg->refresh();
    expect($pkg->is_private)->toBeTrue();
    expect($pkg->advisory_checked_at)->not->toBeNull();
});

test('markPackagePrivate migrates global package to per-project scope', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $globalPkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'project_id' => 0,
        'name' => 'global-private-plugin',
        'is_private' => null,
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $globalPkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'wp-plugin')
        ->call('openPackageDetail', $pp->uuid)
        ->call('markPackagePrivate');

    // Global package deleted (no other references)
    expect(Package::find($globalPkg->id))->toBeNull();

    // Per-project package created and marked private
    $perProject = Package::where('project_id', $project->id)
        ->where('name', 'global-private-plugin')
        ->first();
    expect($perProject)->not->toBeNull();
    expect($perProject->is_private)->toBeTrue();

    // ProjectPackage now points to per-project package
    $pp->refresh();
    expect($pp->package_id)->toBe($perProject->id);
});

test('Mark as private button not shown for confirmed public package', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'name' => 'confirmed-public-plugin',
        'is_private' => false,
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'wp-plugin')
        ->call('openPackageDetail', $pp->uuid)
        ->assertDontSee('Mark as private')
        ->assertSee('Reset registry status');
});

test('resetAdvisoryStatus confirms an already-global package as public in place', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'project_id' => 0,
        'name' => 'private-plugin',
        'is_private' => true,
        'advisory_checked_at' => now()->subDay(),
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'wp-plugin')
        ->call('openPackageDetail', $pp->uuid)
        ->call('resetAdvisoryStatus');

    $pkg->refresh();
    expect($pkg->is_private)->toBeFalse();
    expect($pkg->advisory_checked_at)->not->toBeNull();
});

test('resetAdvisoryStatus migrates a per-project private package back to the global row and refreshes stale source_url', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Global row exists with stale/missing registry metadata (the reported bug).
    $globalPkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'name' => 'vendor/php-client',
        'project_id' => 0,
        'source_url' => null,
        'is_private' => true,
    ]);

    $privatePkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'name' => 'vendor/php-client',
        'project_id' => $project->id,
        'source_url' => 'https://github.com/example/php-client',
        'is_private' => true,
        'advisory_checked_at' => now(),
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $privatePkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $pp->uuid)
        ->call('resetAdvisoryStatus');

    // Per-project private row is gone; ProjectPackage now points at the global row.
    expect(Package::find($privatePkg->id))->toBeNull();

    $global = $globalPkg->fresh();
    expect($global->is_private)->toBeFalse();
    expect($global->source_url)->toBe('https://github.com/example/php-client');

    $pp->refresh();
    expect($pp->package_id)->toBe($global->id);
});

test('Mark as private button shown for a composer package without registry status', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'name' => 'vendor/php-client',
        'is_private' => null,
        'source_url' => null,
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $pp->uuid)
        ->assertSee('Mark as private');
});

test('markPackagePrivate does nothing when no package selected', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->call('markPackagePrivate')
        ->assertOk();
});

test('modal shows Mark as private button for unknown wp-plugin without source url', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'name' => 'modal-plugin',
        'is_private' => null,
        'source_url' => null,
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'wp-plugin')
        ->call('openPackageDetail', $pp->uuid)
        ->assertSee('Mark as private');
});

test('modal shows Reset registry status button when package has known registry status', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'name' => 'reset-plugin',
        'is_private' => true,
        'advisory_checked_at' => now(),
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'wp-plugin')
        ->call('openPackageDetail', $pp->uuid)
        ->assertSee('Reset registry status')
        ->assertDontSee('Mark as private');
});

test('highest severity shown when multiple findings for one package', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/multi']);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-low-vuln-0001',
        'severity' => SeverityEnum::Low->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-crit-vuln-0001',
        'severity' => SeverityEnum::Critical->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertSee('Critical')
        ->assertDontSee('Low');
});

test('vulnerability badge shown when finding version matches installed version', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/matchvuln']);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '3.2.1',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '3.2.1',
        'advisory_id' => 'GHSA-match-vuln-0001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertSee('High');
});

test('vulnerability badge not shown when finding is for a different version than installed', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/oldvuln']);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '2.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-old-vuln-0001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->assertDontSee('High');
});

test('modal shows old-version findings as part of vuln history', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/history-pkg']);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '2.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-history-resolved-001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $pp->uuid)
        ->assertSee('GHSA-history-resolved-001');
});

test('modal places current-version findings before other-version findings', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/sort-pkg']);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '2.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0', // old version
        'advisory_id' => 'GHSA-sort-old-0001',
        'severity' => SeverityEnum::Critical->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '2.0.0', // current version
        'advisory_id' => 'GHSA-sort-current-0001',
        'severity' => SeverityEnum::Low->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $pp->uuid);

    $findings = $component->get('selectedPackageFindings');

    // Current-version finding (low severity) must come before old-version finding (critical)
    expect($findings->first()->advisory_id)->toBe('GHSA-sort-current-0001');
    expect($findings->last()->advisory_id)->toBe('GHSA-sort-old-0001');
});

test('modal shows Current badge for finding matching installed version', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/current-badge']);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.5.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.5.0',
        'advisory_id' => 'GHSA-current-badge-001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $pp->uuid)
        ->assertSee('Current');
});

test('modal does not show Current badge for finding on a different version', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/no-current-badge']);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '2.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.0.0', // not the installed version
        'advisory_id' => 'GHSA-no-current-001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $pp->uuid)
        ->assertDontSee('Current');
});

test('packages default sorted direct-first then name, unaffected by new sort state', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkgZ = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/zeta']);
    $pkgA = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/alpha']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkgZ->id, 'is_direct' => false]);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkgA->id, 'is_direct' => true]);

    $this->actingAs($user);

    $names = Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->get('packages')
        ->pluck('name')
        ->all();

    expect($names)->toBe(['vendor/alpha', 'vendor/zeta']); // direct (alpha) before non-direct (zeta)
});

test('clicking version header sorts packages by version', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkgHigh = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/high']);
    $pkgLow = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/low']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkgHigh->id, 'version' => '9.0.0']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkgLow->id, 'version' => '1.0.0']);

    $this->actingAs($user);

    $component = Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('sort', 'version');

    expect($component->get('packages')->pluck('version')->all())->toBe(['1.0.0', '9.0.0']);

    $component->call('sort', 'version');
    expect($component->get('packages')->pluck('version')->all())->toBe(['9.0.0', '1.0.0']);
});

test('depth column and sort only shown for non-WP ecosystems', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create();
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkg->id]);

    $this->actingAs($user);

    $response = Livewire::test('pages::projects.packages', ['project' => $project])->set('ecosystem', 'wp-plugin');

    $response->assertDontSeeHtml('sort(\'depth\')');
});

test('ecosystem tab shows vuln count even when a different ecosystem tab is selected', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Default tab (wp-core, alphabetically first) has no vulnerabilities.
    $corePkg = Package::factory()->forEcosystem(EcosystemEnum::WpCore)->create(['name' => 'wordpress']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $corePkg->id]);

    // All vulnerabilities live in wp-plugin, a different tab.
    $pluginPkg = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'vuln-plugin']);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pluginPkg->id,
        'version' => '1.0.0',
    ]);
    VulnFinding::factory()->create([
        'package_id' => $pluginPkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'CVE-tab-badge-0001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    // Lands on wp-core by default, yet the wp-plugin tab must still surface its vuln count.
    Livewire::test('pages::projects.packages', ['project' => $project])
        ->assertSet('ecosystem', 'wp-core')
        ->assertSee('1 vuln');
});

test('only vulnerable filter shows just packages with an active finding', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $safePkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/safe']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $safePkg->id, 'version' => '1.0.0']);

    $vulnPkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/unsafe']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $vulnPkg->id, 'version' => '1.0.0']);
    VulnFinding::factory()->create([
        'package_id' => $vulnPkg->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'CVE-filter-0001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->set('onlyVulnerable', true)
        ->assertSee('vendor/unsafe')
        ->assertDontSee('vendor/safe');
});

test('only vulnerable filter shows empty message when no vulnerable packages in tab', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $safePkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/safe']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $safePkg->id]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->set('onlyVulnerable', true)
        ->assertSee('No vulnerable packages in this ecosystem.');
});

test('modal shows affected package version on each finding row', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/version-display']);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '2.0.0', // installed version — will not appear in finding row
    ]);

    VulnFinding::factory()->create([
        'package_id' => $pkg->id,
        'package_version' => '1.2.3', // old version — only visible if finding row renders it
        'advisory_id' => 'GHSA-version-display-001',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->set('ecosystem', 'composer')
        ->call('openPackageDetail', $pp->uuid)
        ->assertSee('1.2.3'); // only present in the finding row, not in installed-version grid cell
});
