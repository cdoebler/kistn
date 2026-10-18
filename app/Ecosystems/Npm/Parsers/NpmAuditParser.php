<?php

namespace App\Ecosystems\Npm\Parsers;

/**
 * Parses `npm audit --json` output.
 *
 * @see https://docs.npmjs.com/cli/v10/commands/npm-audit
 */
final class NpmAuditParser
{
    /**
     * Severity mapping from npm to our standard severity levels.
     *
     * @var array<string, string>
     */
    private const array SEVERITY_MAP = [
        'critical' => 'critical',
        'high' => 'high',
        'moderate' => 'medium',
        'medium' => 'medium',
        'low' => 'low',
        'info' => 'low',
    ];

    /**
     * Parse npm audit JSON output into typed finding rows.
     *
     * @param  string  $json  Raw JSON output from npm audit
     * @param  array<string, string>  $installedVersions  Package name → actually-installed version, read from package-lock.json
     * @return list<array{package_name: string, package_version: string, advisory_id: string, severity: string}>
     */
    public static function parse(string $json, array $installedVersions = []): array
    {
        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($data) || ! isset($data['vulnerabilities']) || ! is_array($data['vulnerabilities'])) {
            return [];
        }

        $findings = [];

        foreach ($data['vulnerabilities'] as $packageName => $vulnerability) {
            if (! is_array($vulnerability)) {
                continue;
            }

            $severity = $vulnerability['severity'] ?? null;
            $via = $vulnerability['via'] ?? null;

            if (! is_string($severity) || ! is_array($via)) {
                continue;
            }

            $mappedSeverity = self::SEVERITY_MAP[$severity] ?? $severity;
            $name = (string) $packageName;

            foreach ($via as $advisoryItem) {
                if (! is_array($advisoryItem)) {
                    continue;
                }

                $url = $advisoryItem['url'] ?? null;
                if (! is_string($url)) {
                    continue;
                }

                $advisoryId = self::extractGhsaIdFromUrl($url);
                if (! $advisoryId) {
                    continue;
                }

                $findings[] = [
                    'package_name' => $name,
                    'package_version' => $installedVersions[$name] ?? '*',
                    'advisory_id' => $advisoryId,
                    'severity' => $mappedSeverity,
                ];
            }
        }

        return $findings;
    }

    /**
     * Extract GHSA ID from GitHub advisory URL.
     *
     * @param  string  $url  Advisory URL
     * @return string|null GHSA ID or null if not found
     */
    private static function extractGhsaIdFromUrl(string $url): ?string
    {
        if (preg_match('/GHSA-[\w-]+/', $url, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
