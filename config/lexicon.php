<?php

return [
    'api_url' => env('LEXICON_API_URL'),
    'client_code' => env('LEXICON_CLIENT_CODE'),
    'project_code' => env('LEXICON_PROJECT_CODE'),
    'secret' => env('LEXICON_CLIENT_SECRET'),
    'environment' => env('LEXICON_ENVIRONMENT', env('APP_ENV', 'local')),

    'manifest' => base_path('lexicon.json'),

    'output' => [
        'base_path' => env('LEXICON_OUTPUT_BASE_PATH', 'lang'),
        'pattern' => env('LEXICON_OUTPUT_PATTERN', '{locale}/{relative_path}'),
        'format' => env('LEXICON_OUTPUT_FORMAT', 'php'),
        // php default: add missing keys only (preserves comments/formatting).
        // Use merge=replace or lexicon:pull --replace to overwrite existing values.
        'merge' => env('LEXICON_OUTPUT_MERGE', 'add_missing'),
    ],

    'http' => [
        'timeout' => 30,
        'import_timeout' => 180,
        'retry_times' => 2,
        'retry_sleep_ms' => 200,
    ],
];
