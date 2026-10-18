<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Rector\FuncCall\RemoveDumpDataDeadCodeRector;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()->withPaths([
    __DIR__.'/app',
    __DIR__.'/bootstrap',
    __DIR__.'/database',
    __DIR__.'/resources/views',
    __DIR__.'/routes',
    __DIR__.'/tests',
])->withSkip([
    __DIR__.'/bootstrap/cache',
])->withPhpSets(php83: true)->withSets([
    LaravelLevelSetList::UP_TO_LARAVEL_130,
    LaravelSetList::LARAVEL_CODE_QUALITY,
    LaravelSetList::LARAVEL_COLLECTION,
])->withConfiguredRule(
    rectorClass: RemoveDumpDataDeadCodeRector::class,
    configuration: [
        'dd', 'dump', 'ds', 'ray', 'var_dump',
    ]
);
