<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$site = App\Models\Site::find(12);
App\Jobs\CreateBackup::dispatch($site, 'full', 'manual', null, 61);
echo "Job dispatched\n";
