<?php

return [
    'base_url' => env('KISTN_BASE_URL', ''),
    'project_id' => env('KISTN_PROJECT_ID', ''),
    'token' => env('KISTN_TOKEN', ''),
    'cache_path' => storage_path('app/private/kistn/.inventory.hash'),
    'work_dir' => base_path(),
    'transmit_composer_files' => env('KISTN_TRANSMIT_COMPOSER_FILES', 'true'),
    'transmit_npm_files' => env('KISTN_TRANSMIT_NPM_FILES', 'true'),
];
