<?php

// Override Docker's system-level env vars for testing.
// PHPUnit's <env force="true"> sets $_ENV but Docker populates
// $_SERVER with production values. We must sync all three sources
// ($_ENV, $_SERVER, putenv) so Laravel's env() helper reads test values.
//
// CI detection: if GITHUB_ACTIONS is set, override DB_HOST to 127.0.0.1
// because CI runs Postgres as a service container, not via Docker hostname.
if (getenv('GITHUB_ACTIONS')) {
    $_ENV['DB_HOST'] = '127.0.0.1';
    $_ENV['DB_USERNAME'] = $_ENV['DB_USERNAME'] ?? 'simplead';
    $_ENV['DB_PASSWORD'] = $_ENV['DB_PASSWORD'] ?? 'password';
    $_ENV['REDIS_HOST'] = '127.0.0.1';
}

foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
    putenv("$key=$value");
}

// Hide the schema dump so RefreshDatabase runs all migrations
// instead of loading the dump (which can conflict with migration files).
$schemaPath = __DIR__.'/../database/schema/pgsql-schema.sql';
$backupPath = $schemaPath.'.testing-bak';

if (file_exists($schemaPath)) {
    copy($schemaPath, $backupPath);
    unlink($schemaPath);

    register_shutdown_function(function () use ($schemaPath, $backupPath) {
        if (file_exists($backupPath)) {
            copy($backupPath, $schemaPath);
            unlink($backupPath);
        }
    });
}

require __DIR__.'/../vendor/autoload.php';
