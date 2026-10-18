<?php

namespace App\Ecosystems\Npm\Parsers;

/**
 * Parses `npm outdated --json` output.
 *
 * @see https://docs.npmjs.com/cli/v10/commands/npm-outdated
 */
final class NpmOutdatedParser
{
    /**
     * Parse npm outdated JSON output into name => latest version map.
     *
     * @param  string  $json  Raw JSON output from npm outdated
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

        $outdated = [];

        foreach ($data as $packageName => $package) {
            if (! is_array($package)) {
                continue;
            }

            $latest = $package['latest'] ?? null;

            if (! is_string($latest) || ! is_string($packageName)) {
                continue;
            }

            $outdated[$packageName] = $latest;
        }

        return $outdated;
    }
}
