<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\MailConfigServiceProvider::class,
    App\Providers\IncidentResponseConfigServiceProvider::class,
    App\Providers\AuditConfigServiceProvider::class,
    App\Backup\V2\BackupV2ServiceProvider::class,
];
