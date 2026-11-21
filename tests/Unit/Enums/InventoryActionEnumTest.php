<?php

use App\Enums\InventoryActionEnum;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

// label() calls __(), which needs the app's translator bound.
uses(TestCase::class);

it('labels each action', function (): void {
    expect(InventoryActionEnum::Added->label())->toBe('added')
        ->and(InventoryActionEnum::Updated->label())->toBe('updated')
        ->and(InventoryActionEnum::Removed->label())->toBe('removed');
});

it('translates action labels to German', function (): void {
    App::setLocale('de');

    expect(InventoryActionEnum::Added->label())->toBe('hinzugefügt')
        ->and(InventoryActionEnum::Updated->label())->toBe('aktualisiert')
        ->and(InventoryActionEnum::Removed->label())->toBe('entfernt');
});
