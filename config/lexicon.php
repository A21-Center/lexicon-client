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
    ],

    'http' => [
        'timeout' => 30,
        'import_timeout' => 180,
        'retry_times' => 2,
        'retry_sleep_ms' => 200,
    ],
];
