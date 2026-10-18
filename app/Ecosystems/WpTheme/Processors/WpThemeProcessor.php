<?php

namespace App\Ecosystems\WpTheme\Processors;

use App\Ecosystems\Wp\AbstractWpProcessor;
use App\Enums\EcosystemEnum;
use App\Models\Project;

class WpThemeProcessor extends AbstractWpProcessor
{
    public static function registryUrl(string $packageName): string
    {
        return "https://wordpress.org/themes/{$packageName}";
    }

    protected function ecosystem(): EcosystemEnum
    {
        return EcosystemEnum::WpTheme;
    }

    /**
     * @param  list<array{ecosystem: string, name: string, payload: array<int, mixed>}>  $advisories
     * @param  list<string>  $privatePackages
     * @param  list<string>  $publicPackages
     */
    #[\Override]
    protected function processSpecific(Project $project, array $advisories, array $privatePackages, array $publicPackages = []): void
    {
        $this->processPrivatePackages($project, $privatePackages);
        $this->processPublicPackages($project, $publicPackages);
    }
}
