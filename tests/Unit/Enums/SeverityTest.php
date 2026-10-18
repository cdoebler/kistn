<?php

use App\Enums\SeverityEnum;
use Tests\TestCase;

// label() calls __(), which needs the app's translator bound.
uses(TestCase::class);

it('returns correct priority order', function (): void {
    expect(SeverityEnum::Critical->priority())->toBeGreaterThan(SeverityEnum::High->priority())
        ->and(SeverityEnum::High->priority())->toBeGreaterThan(SeverityEnum::Medium->priority())
        ->and(SeverityEnum::Medium->priority())->toBeGreaterThan(SeverityEnum::Low->priority());
});

it('maps each severity to a Flux badge color string', function (): void {
    expect(SeverityEnum::Critical->badgeColor())->toBe('red')
        ->and(SeverityEnum::High->badgeColor())->toBe('orange')
        ->and(SeverityEnum::Medium->badgeColor())->toBe('yellow')
        ->and(SeverityEnum::Low->badgeColor())->toBe('zinc');
});

it('labels each severity, translated', function (): void {
    expect(SeverityEnum::Low->label())->toBe('Low')
        ->and(SeverityEnum::Medium->label())->toBe('Medium')
        ->and(SeverityEnum::High->label())->toBe('High')
        ->and(SeverityEnum::Critical->label())->toBe('Critical');
});
