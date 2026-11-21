<?php

namespace App\Enums;

enum InventoryActionEnum: string
{
    case Added = 'added';
    case Updated = 'updated';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Added => (string) __('added'),
            self::Updated => (string) __('updated'),
            self::Removed => (string) __('removed'),
        };
    }
}
