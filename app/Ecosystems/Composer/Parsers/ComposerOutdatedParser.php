<?php

namespace App\Ecosystems\Composer\Parsers;

/**
 * Parses `composer outdated --locked --format=json` output.
 *
 * @see https://getcomposer.org/doc/03-cli.md#outdated
 */
final class ComposerOutdatedParser
{
    /**
     * Parse composer outdated JSON output into name => latest version map.
     *
     * @param  string  $json  Raw JSON output from composer outdated
     * @return array<string, string> Package name => latest version
     */
    public static function parse(string $json): array
    {
        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        // `--all` flag changes the key from 'installed' to 'locked'
        $packages = $data['installed'] ?? $data['locked'] ?? null;

        if (! is_array($packages)) {
            return [];
        }

        $outdated = [];

        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            $latest = $package['latest'] ?? null;

            if (! is_string($name) || ! is_string($latest)) {
                continue;
            }

            $outdated[$name] = $latest;
        }

        return $outdated;
    }
}
