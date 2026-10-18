<?php

use App\Enums\EcosystemEnum;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AuditDiscrepancyNotification;
use Illuminate\Support\Facades\App;

test('via returns mail and database channels', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $notification = new AuditDiscrepancyNotification($project, EcosystemEnum::Composer, [], []);

    expect($notification->via($user))->toBe(['mail', 'database']);
});

test('mail subject contains project name and ecosystem', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'My Project']);

    $notification = new AuditDiscrepancyNotification($project, EcosystemEnum::Composer, [], []);
    $mail = $notification->toMail($user);

    expect($mail->subject)
        ->toContain('My Project')
        ->toContain('composer');
});

test('mail body includes advisory IDs found only in server', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $notification = new AuditDiscrepancyNotification($project, EcosystemEnum::Composer, ['GHSA-aaa-111', 'GHSA-bbb-222'], []);
    $mail = $notification->toMail($user);
    $body = (string) $mail->render();

    expect($body)->toContain('GHSA-aaa-111')
        ->and($body)->toContain('GHSA-bbb-222')
        ->and($body)->toContain('Found by server, not reported by client');
});

test('mail body includes advisory IDs found only in client', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $notification = new AuditDiscrepancyNotification($project, EcosystemEnum::Composer, [], ['GHSA-ccc-333', 'GHSA-ddd-444']);
    $mail = $notification->toMail($user);
    $body = (string) $mail->render();

    expect($body)->toContain('GHSA-ccc-333')
        ->and($body)->toContain('GHSA-ddd-444')
        ->and($body)->toContain('Reported by client, not found by server');
});

test('mail body omits server section when onlyInServer is empty', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $notification = new AuditDiscrepancyNotification($project, EcosystemEnum::Composer, [], ['GHSA-ccc-333']);
    $mail = $notification->toMail($user);
    $body = (string) $mail->render();

    expect($body)->not->toContain('Found by server, not reported by client');
});

test('mail body omits client section when onlyInClient is empty', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $notification = new AuditDiscrepancyNotification($project, EcosystemEnum::Composer, ['GHSA-aaa-111'], []);
    $mail = $notification->toMail($user);
    $body = (string) $mail->render();

    expect($body)->not->toContain('Reported by client, not found by server');
});

test('toArray returns correct keys with project uuid, ecosystem, and both lists', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Array Project']);

    $onlyInServer = ['GHSA-srv-001'];
    $onlyInClient = ['GHSA-cli-002'];

    $notification = new AuditDiscrepancyNotification($project, EcosystemEnum::Composer, $onlyInServer, $onlyInClient);
    $array = $notification->toArray($user);

    expect($array)->toBe([
        'project_uuid' => $project->uuid,
        'project_name' => 'Array Project',
        'ecosystem' => 'composer',
        'only_in_server' => $onlyInServer,
        'only_in_client' => $onlyInClient,
    ]);
});

test('mail renders in German when locale is de', function (): void {
    App::setLocale('de');
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'My Project']);

    $notification = new AuditDiscrepancyNotification($project, EcosystemEnum::Composer, ['GHSA-aaa-111'], ['GHSA-ccc-333']);
    $mail = $notification->toMail($user);
    $body = (string) $mail->render();

    expect($mail->subject)->toBe('Audit-Abweichung — My Project (composer)')
        ->and($body)->toContain('Audit-Abweichung')
        ->and($body)->toContain('Vom Server gefunden, nicht vom Client gemeldet')
        ->and($body)->toContain('Vom Client gemeldet, nicht vom Server gefunden');
});
