<?php

return [
    'advisory_snapshot_ttl' => [
        'wp-core' => 60 * 60 * 72, // 72h — core releases are infrequent
        'wp-plugin' => 60 * 60 * 24, // 24h
        'wp-theme' => 60 * 60 * 24, // 24h
    ],

    'rate_limits' => [
        'inventory' => 30,
        'files' => 30,
        'preflight' => 60,
    ],

    'payload_limits' => [
        'packages' => 5000,
        'findings' => 5000,
        'private_packages' => 5000,
        'public_packages' => 5000,
        'advisories' => 500,
    ],

    'churn_threshold' => 100,
];
