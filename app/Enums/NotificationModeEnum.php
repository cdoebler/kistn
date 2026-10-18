<?php

namespace App\Enums;

enum NotificationModeEnum: string
{
    case Immediate = 'immediate';
    case DailyDigest = 'daily_digest';
}
