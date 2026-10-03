<?php

// One connection, to the shared schema (spec/README.md). DATABASE_URL takes
// the same form as tadmor's: postgres://user:pass@host:5432/db?sslmode=disable
return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            // The aging views and default dates follow the session's timezone.
            'timezone' => 'UTC',
        ],
    ],
];
