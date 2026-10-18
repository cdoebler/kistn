<?php

namespace App\Enums;

enum SeverityEnum: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function priority(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Medium => 'yellow',
            self::High => 'orange',
            self::Critical => 'red',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => (string) __('Low'),
            self::Medium => (string) __('Medium'),
            self::High => (string) __('High'),
            self::Critical => (string) __('Critical'),
        };
    }
}
