<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PackagePublishedNotification;
use Illuminate\Support\Facades\App;

test('mail layout renders with German lang attribute and translated footer/tagline', function (): void {
    App::setLocale('de');

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $package = Package::factory()->create();

    $mail = (new PackagePublishedNotification($project, EcosystemEnum::WpPlugin, $package))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('<html lang="de">')
        ->toContain('Überwachung von Software-Paketbeständen &amp; Schwachstellen')
        ->toContain('Dies ist eine automatisch generierte Nachricht von');
});
