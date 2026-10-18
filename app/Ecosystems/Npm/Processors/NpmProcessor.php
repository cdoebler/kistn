<?php

namespace App\Ecosystems\Npm\Processors;

use App\Ecosystems\BaseEcosystemProcessor;
use App\Enums\EcosystemEnum;

class NpmProcessor extends BaseEcosystemProcessor
{
    public static function registryUrl(string $packageName): string
    {
        return "https://www.npmjs.com/package/{$packageName}";
    }

    protected function ecosystem(): EcosystemEnum
    {
        return EcosystemEnum::Npm;
    }
}
