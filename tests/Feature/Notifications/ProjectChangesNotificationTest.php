<?php

use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectChangesNotification;
use App\ValueObjects\OutdatedSummaryValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;

test('ProjectChangesNotification is sent via mail and database channels', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [], patch: [], other: [],
            ),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    $user->notify(new ProjectChangesNotification($project, $payload));

    Notification::assertSentTo($user, ProjectChangesNotification::class, fn (ProjectChangesNotification $n): bool => $n->project->id === $project->id);
});

test('ProjectChangesNotification subject includes project name', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'My App']);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    $notification = new ProjectChangesNotification($project, $payload);
    $mail = $notification->toMail($user);

    expect($mail->subject)->toContain('My App');
});

test('via() returns mail and database channels', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    $notification = new ProjectChangesNotification($project, $payload);

    expect($notification->via($user))->toBe(['mail', 'database']);
});

test('toMail() with outdated packages contains package name and version details', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [], patch: [], other: [],
            ),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    $mail = (new ProjectChangesNotification($project, $payload))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('vendor/foo')
        ->toContain('1.0.0')
        ->toContain('2.0.0')
        ->toContain('composer');
});

test('toMail() skips section when outdated summary is empty', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [], minor: [], patch: [], other: [],
            ),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    $mail = (new ProjectChangesNotification($project, $payload))->toMail($user);
    $body = (string) $mail->render();

    expect($body)->not->toContain('Outdated packages');
});

test('toMail() with findings contains advisory_id, severity, and package name', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [
            'composer' => [
                ['package_name' => 'vendor/pkg', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-xxxx-yyyy-zzzz', 'severity' => 'high'],
            ],
        ],
        triggeredAt: CarbonImmutable::now(),
    );

    $mail = (new ProjectChangesNotification($project, $payload))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('vendor/pkg')
        ->toContain('GHSA-xxxx-yyyy-zzzz')
        ->toContain('High');
});

test('toMail() findings with GHSA advisory_id renders a link', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [
            'composer' => [
                ['package_name' => 'vendor/pkg', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-abcd-efgh-1234', 'severity' => 'critical'],
            ],
        ],
        triggeredAt: CarbonImmutable::now(),
    );

    $mail = (new ProjectChangesNotification($project, $payload))->toMail($user);
    $body = (string) $mail->render();

    expect($body)->toContain('https://github.com/advisories/GHSA-abcd-efgh-1234');
});

test('toMail() findings with non-GHSA advisory_id renders plain text without advisory link', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [
            'composer' => [
                ['package_name' => 'vendor/pkg', 'package_version' => '1.0.0', 'advisory_id' => 'WPSCAN-12345', 'severity' => 'medium'],
            ],
        ],
        triggeredAt: CarbonImmutable::now(),
    );

    $mail = (new ProjectChangesNotification($project, $payload))->toMail($user);
    $body = (string) $mail->render();

    // The advisory_id should appear as plain text, not wrapped in a markdown link
    expect($body)
        ->toContain('WPSCAN-12345')
        ->not->toContain('[WPSCAN-12345](');
});

test('toMail() with both outdated and findings renders both sections', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [], patch: [], other: [],
            ),
        ],
        findingsByEcosystem: [
            'composer' => [
                ['package_name' => 'vendor/bar', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-1111-2222-3333', 'severity' => 'high'],
            ],
        ],
        triggeredAt: CarbonImmutable::now(),
    );

    $mail = (new ProjectChangesNotification($project, $payload))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('Outdated packages')
        ->toContain('vendor/foo')
        ->toContain('Vulnerabilities')
        ->toContain('vendor/bar')
        ->toContain('GHSA-1111-2222-3333');
});

test('toMail() separates major, minor, patch, and other update sections', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/major', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [['name' => 'vendor/minor', 'current' => '1.0.0', 'latest' => '1.1.0']],
                patch: [['name' => 'vendor/patch', 'current' => '1.0.0', 'latest' => '1.0.1']],
                other: [['name' => 'vendor/other', 'current' => 'dev-main', 'latest' => 'dev-next']],
            ),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    $mail = (new ProjectChangesNotification($project, $payload))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('Major updates')
        ->toContain('vendor/major')
        ->toContain('Minor updates')
        ->toContain('vendor/minor')
        ->toContain('Patch updates')
        ->toContain('vendor/patch')
        ->toContain('Other changes')
        ->toContain('vendor/other');
});

test('toArray() returns project_uuid, outdated_by_ecosystem, and findings_by_ecosystem', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $summary = new OutdatedSummaryValueObject(
        major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
        minor: [], patch: [], other: [],
    );

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: ['composer' => $summary],
        findingsByEcosystem: [
            'npm' => [
                ['package_name' => 'lodash', 'package_version' => '4.17.20', 'advisory_id' => 'GHSA-test-0001-0001', 'severity' => 'low'],
            ],
        ],
        triggeredAt: CarbonImmutable::now(),
    );

    $data = (new ProjectChangesNotification($project, $payload))->toArray($user);

    expect($data)
        ->toHaveKey('project_uuid', $project->uuid)
        ->toHaveKey('outdated_by_ecosystem')
        ->toHaveKey('findings_by_ecosystem');

    expect($data['outdated_by_ecosystem'])->toHaveKey('composer');
    expect($data['outdated_by_ecosystem']['composer'])->toHaveKeys(['major', 'minor', 'patch', 'other']);
    expect($data['findings_by_ecosystem'])->toHaveKey('npm');
});

test('ProjectChangesNotification implements ShouldQueue', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    expect(new ProjectChangesNotification($project, $payload))->toBeInstanceOf(ShouldQueue::class);
});

test('mail renders in German when locale is de', function (): void {
    App::setLocale('de');
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'My Project']);

    $payload = new ProjectNotificationPayloadValueObject(
        outdatedByEcosystem: [
            'composer' => new OutdatedSummaryValueObject(
                major: [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
                minor: [], patch: [], other: [],
            ),
        ],
        findingsByEcosystem: [],
        triggeredAt: CarbonImmutable::now(),
    );

    $mail = (new ProjectChangesNotification($project, $payload))->toMail($user);
    $body = (string) $mail->render();

    expect($mail->subject)->toBe('Updates — My Project')
        ->and($body)->toContain('Veraltete Pakete')
        ->and($body)->toContain('Hauptversions-Updates');
});
