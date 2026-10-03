<?php

return [
    'name' => 'tadmor',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    // "Today" is the UTC date (spec/api.md §1.2).
    'timezone' => 'UTC',
];
