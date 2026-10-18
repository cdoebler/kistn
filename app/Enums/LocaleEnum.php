<?php

namespace App\Enums;

enum LocaleEnum: string
{
    case English = 'en';
    case German = 'de';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::German => 'Deutsch',
        };
    }
}
