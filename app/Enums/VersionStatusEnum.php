<?php

namespace App\Enums;

/**
 * Directional relationship between an installed version and the latest public version.
 * Behind = update available; Ahead = running newer than the public registry (dev/pre-release).
 */
enum VersionStatusEnum: string
{
    case UpToDate = 'up-to-date';
    case Behind = 'behind';
    case Ahead = 'ahead';
    case Unknown = 'unknown';

    public static function fromVersions(?string $installed, ?string $latest): self
    {
        if ($installed === null || $installed === '' || $latest === null || $latest === '') {
            return self::Unknown;
        }

        // ponytail: version_compare covers composer/npm semver incl. pre-release; swap for a
        // real semver lib only if build-metadata edge cases mis-order.
        return match (version_compare($installed, $latest)) {
            -1 => self::Behind,
            1 => self::Ahead,
            default => self::UpToDate,
        };
    }

    public function textClasses(): string
    {
        return match ($this) {
            self::Behind => 'text-amber-600 dark:text-amber-400',
            self::Ahead => 'text-blue-600 dark:text-blue-400',
            default => '',
        };
    }

    public function arrow(): string
    {
        return match ($this) {
            self::Behind => '↑',
            self::Ahead => '↓',
            default => '',
        };
    }

    public function label(): ?string
    {
        return match ($this) {
            self::Behind => (string) __('Update available'),
            self::Ahead => (string) __('Newer than public registry'),
            default => null,
        };
    }
}
