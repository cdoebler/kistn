<?php

namespace App\Enums;

enum InventoryActionEnum: string
{
    case Added = 'added';
    case Updated = 'updated';
    case Removed = 'removed';
}
