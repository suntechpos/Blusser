<?php

$postgres = [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', 'db'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'blusser'),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
];

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'pgsql' => [...$postgres, 'username' => env('DB_USERNAME', 'blusser_app'), 'password' => env('DB_PASSWORD')],
        'migration' => [...$postgres, 'username' => env('MIGRATION_DB_USERNAME'), 'password' => env('MIGRATION_DB_PASSWORD')],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
