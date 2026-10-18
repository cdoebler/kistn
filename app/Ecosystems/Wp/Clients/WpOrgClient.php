<?php

namespace App\Ecosystems\Wp\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WpOrgClient
{
    private const string PLUGIN_API = 'https://api.wordpress.org/plugins/info/1.2/';

    private const string THEME_API = 'https://api.wordpress.org/themes/info/1.2/';

    private const string CORE_API = 'https://api.wordpress.org/core/version-check/1.7/';

    public function latestPluginVersion(string $slug): ?string
    {
        return $this->fetchField(self::PLUGIN_API, 'plugin_information', $slug, 'version');
    }

    public function latestThemeVersion(string $slug): ?string
    {
        return $this->fetchField(self::THEME_API, 'theme_information', $slug, 'version');
    }

    public function pluginAddedDate(string $slug): ?string
    {
        return $this->fetchField(self::PLUGIN_API, 'plugin_information', $slug, 'added');
    }

    public function themeAddedDate(string $slug): ?string
    {
        return $this->fetchField(self::THEME_API, 'theme_information', $slug, 'added');
    }

    public function latestCoreVersion(): ?string
    {
        try {
            $response = Http::get(self::CORE_API);

            if (! $response->successful()) {
                Log::debug('WpOrgClient: core version check failed', ['status' => $response->status()]); // @pest-mutate-ignore logging not asserted in this test suite

                return null;
            }

            $data = $response->json();

            if (! is_array($data)) {
                return null;
            }

            $offers = $data['offers'] ?? null;

            if (! is_array($offers) || $offers === []) {
                return null;
            }

            foreach ($offers as $offer) {
                if (! is_array($offer)) {
                    continue;
                }

                $response_type = $offer['response'] ?? null;

                if ($response_type === 'development') {
                    continue;
                }

                $version = $offer['current'] ?? $offer['version'] ?? null;

                return is_string($version) && $version !== '' ? $version : null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('WpOrgClient: core version request failed', ['error' => $e->getMessage()]); // @pest-mutate-ignore logging not asserted in this test suite

            return null;
        }
    }

    private function fetchField(string $baseUrl, string $action, string $slug, string $field): ?string
    {
        try {
            $response = Http::get($baseUrl, [
                'action' => $action,
                'request[slug]' => $slug,
                "request[fields][{$field}]" => 'true',
            ]);

            if (! $response->successful()) {
                Log::debug('WpOrgClient: non-successful response', ['action' => $action, 'slug' => $slug, 'field' => $field, 'status' => $response->status()]); // @pest-mutate-ignore logging not asserted in this test suite

                return null;
            }

            $data = $response->json();

            if (! is_array($data)) {
                return null;
            }

            $value = $data[$field] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            Log::warning('WpOrgClient: request failed', ['action' => $action, 'slug' => $slug, 'field' => $field, 'error' => $e->getMessage()]); // @pest-mutate-ignore logging not asserted in this test suite

            return null;
        }
    }
}
