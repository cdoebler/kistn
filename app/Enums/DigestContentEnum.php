<?php

namespace App\Enums;

enum DigestContentEnum: string
{
    case Delta = 'delta';
    case FullState = 'full_state';
}
