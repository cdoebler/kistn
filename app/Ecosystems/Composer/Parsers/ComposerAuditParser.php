<?php

namespace App\Ecosystems\Composer\Parsers;

/**
 * Parses `composer audit --locked --format=json` output.
 *
 * @see https://getcomposer.org/doc/03-cli.md#audit
 */
final class ComposerAuditParser
{
    /**
     * Parse composer audit JSON output into typed finding rows.
     *
     * @param  string  $json  Raw JSON output from composer audit
     * @param  array<string, string>  $installedVersions  Package name → actually-installed version, read from composer.lock
     * @return list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>
     */
    public static function parse(string $json, array $installedVersions = []): array
    {
        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($data) || ! isset($data['advisories']) || ! is_array($data['advisories'])) {
            return [];
        }

        $findings = [];

        foreach ($data['advisories'] as $packageAdvisories) {
            if (! is_array($packageAdvisories)) {
                continue;
            }

            foreach ($packageAdvisories as $advisory) {
                if (! is_array($advisory)) {
                    continue;
                }

                $advisoryId = $advisory['advisoryId'] ?? null;
                $packageName = $advisory['packageName'] ?? null;
                $severity = $advisory['severity'] ?? null;

                if (! is_string($advisoryId) || ! is_string($packageName) || ! is_string($severity)) {
                    continue;
                }

                $findings[] = [
                    'package_name' => $packageName,
                    'package_version' => $installedVersions[$packageName] ?? 'unknown',
                    'advisory_id' => $advisoryId,
                    'severity' => $severity,
                ];
            }
        }

        return $findings;
    }
}
