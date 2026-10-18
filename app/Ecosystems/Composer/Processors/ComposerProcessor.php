<?php

namespace App\Ecosystems\Composer\Processors;

use App\Ecosystems\BaseEcosystemProcessor;
use App\Enums\EcosystemEnum;

class ComposerProcessor extends BaseEcosystemProcessor
{
    public static function registryUrl(string $packageName): string
    {
        return "https://packagist.org/packages/{$packageName}";
    }

    protected function ecosystem(): EcosystemEnum
    {
        return EcosystemEnum::Composer;
    }
}
