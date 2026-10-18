<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Produces a sanitized working copy of an uploaded, gzip-compressed package file.
 *
 * Manifests (composer.json, package.json) carry lifecycle-hook keys (`scripts`) that a
 * package manager executes when it runs commands like `audit` or `outdated`. Those keys are
 * stripped from the working copy so no client-supplied code runs server-side. Lock and
 * installed files hold only package data — they pass through byte-for-byte.
 */
class PackageFileSanitizerService
{
    /**
     * Storage filename → top-level JSON keys to strip from its working copy.
     *
     * @var array<string, list<string>>
     */
    private const array MANIFEST_STRIP_KEYS = [
        'composer.json.gz' => ['scripts'],
        'package.json.gz' => ['scripts'],
    ];

    /**
     * @param  string  $filename  Storage filename (e.g. `package.json.gz`).
     * @param  string  $original  Raw gzip bytes as received from the client.
     * @return string Gzip bytes of the sanitized working copy (original bytes when nothing to strip or on any failure).
     */
    public function sanitize(string $filename, string $original): string
    {
        $stripKeys = self::MANIFEST_STRIP_KEYS[$filename] ?? null;

        if ($stripKeys === null) {
            return $original;
        }

        $json = @gzdecode($original);

        if ($json === false) {
            Log::warning('PackageFileSanitizerService: gzdecode failed, using original as working copy', ['filename' => $filename]);

            return $original;
        }

        $data = json_decode($json, associative: true);

        if (! is_array($data)) {
            Log::warning('PackageFileSanitizerService: JSON decode failed, using original as working copy', ['filename' => $filename]);

            return $original;
        }

        $stripped = array_values(array_intersect($stripKeys, array_keys($data)));

        foreach ($stripKeys as $key) {
            unset($data[$key]);
        }

        if ($stripped === []) {
            return $original;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $recompressed = $encoded !== false ? gzencode($encoded) : false;

        if ($recompressed === false) {
            Log::warning('PackageFileSanitizerService: re-encode failed, using original as working copy', ['filename' => $filename]);

            return $original;
        }

        Log::info('PackageFileSanitizerService: stripped hook keys from working copy', [
            'filename' => $filename,
            'stripped' => $stripped,
        ]);

        return $recompressed;
    }
}
