<?php

// Override Docker's system-level env vars for testing.
// PHPUnit's <env force="true"> sets $_ENV but Docker populates
// $_SERVER with production values. We must sync all three sources
// ($_ENV, $_SERVER, putenv) so Laravel's env() helper reads test values.
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
