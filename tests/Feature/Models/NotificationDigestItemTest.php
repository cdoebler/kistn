<?php

use App\Models\NotificationDigestItem;
use App\Models\Project;
use App\Models\User;

test('digest item can be created', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $item = NotificationDigestItem::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'type' => 'outdated',
        'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/foo', 'current' => '1.0', 'latest' => '2.0']],
    ]);

    expect($item->id)->not->toBeNull()
        ->and($item->type)->toBe('outdated')
        ->and($item->data)->toBeArray();
});

test('digest item belongs to user and project', function (): void {
    $item = NotificationDigestItem::factory()->create();

    expect($item->user)->not->toBeNull()
        ->and($item->project)->not->toBeNull();
});
