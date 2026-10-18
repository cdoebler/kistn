<?php

use App\Enums\DigestContentEnum;
use App\Enums\NotificationModeEnum;

test('NotificationMode has correct cases', function (): void {
    expect(NotificationModeEnum::Immediate->value)->toBe('immediate')
        ->and(NotificationModeEnum::DailyDigest->value)->toBe('daily_digest');
});

test('DigestContent has correct cases', function (): void {
    expect(DigestContentEnum::Delta->value)->toBe('delta')
        ->and(DigestContentEnum::FullState->value)->toBe('full_state');
});
