<?php

namespace App\Enums;

use App\Ecosystems\BaseEcosystemProcessor;

enum EcosystemEnum: string
{
    case Composer = 'composer';
    case Npm = 'npm';
    case WpCore = 'wp-core';
    case WpPlugin = 'wp-plugin';
    case WpTheme = 'wp-theme';

    public function registryUrl(string $packageName): string
    {
        return ($this->processorClass())::registryUrl($packageName);
    }

    /** @return class-string<BaseEcosystemProcessor> */
    public function processorClass(): string
    {
        /** @var class-string<BaseEcosystemProcessor> $class */
        $class = "App\\Ecosystems\\{$this->name}\\Processors\\{$this->name}Processor";

        return $class;
    }

    public function auditJobClass(): ?string
    {
        $class = "App\\Ecosystems\\{$this->name}\\Jobs\\{$this->name}AuditJob";

        return class_exists($class) ? $class : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Composer => 'Composer',
            self::Npm => 'npm',
            self::WpCore => 'WP Core',
            self::WpPlugin => 'WP Plugin',
            self::WpTheme => 'WP Theme',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Composer => 'indigo',
            self::Npm => 'red',
            self::WpCore => 'blue',
            self::WpPlugin => 'teal',
            self::WpTheme => 'purple',
        };
    }
}
