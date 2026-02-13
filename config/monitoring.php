<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plugin Abandonment
    |--------------------------------------------------------------------------
    */
    'plugin_abandonment_years' => env('PLUGIN_ABANDONMENT_YEARS', 2),

    /*
    |--------------------------------------------------------------------------
    | Database Health Thresholds
    |--------------------------------------------------------------------------
    */
    'db_total_size_warning' => env('DB_SIZE_WARNING_BYTES', 1_073_741_824),     // 1 GB
    'db_autoload_size_warning' => env('DB_AUTOLOAD_WARNING_BYTES', 1_048_576),  // 1 MB
    'db_overhead_warning' => env('DB_OVERHEAD_WARNING_BYTES', 104_857_600),     // 100 MB
    'db_table_size_warning' => env('DB_TABLE_SIZE_WARNING_BYTES', 524_288_000), // 500 MB

    /*
    |--------------------------------------------------------------------------
    | Security Scan
    |--------------------------------------------------------------------------
    */
    'security_score_critical_threshold' => env('SECURITY_CRITICAL_THRESHOLD', 50),

    /*
    |--------------------------------------------------------------------------
    | Backup Retention
    |--------------------------------------------------------------------------
    */
    'max_import_records' => env('MAX_IMPORT_RECORDS', 500),
];
