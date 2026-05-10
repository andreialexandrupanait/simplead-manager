<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Use raw DDL outside Laravel's transaction wrapper because PgBouncer transaction
     * pooling aborts the connection on the first ALTER TABLE inside a transaction,
     * making subsequent statements fail with "current transaction is aborted".
     * See deploy notes for fazes 0+1 where this exact failure mode occurred.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->jsonb('replicas')->default('[]')->after('file_path');
        });

        Schema::table('backup_configs', function (Blueprint $table) {
            $table->foreignId('secondary_storage_destination_id')
                ->nullable()
                ->after('storage_destination_id')
                ->constrained('storage_destinations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backup_configs', function (Blueprint $table) {
            $table->dropForeign(['secondary_storage_destination_id']);
            $table->dropColumn('secondary_storage_destination_id');
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('replicas');
        });
    }
};
