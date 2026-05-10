<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('format', 20)->default('v2-zip')->after('checksum');
        });

        Schema::table('backup_configs', function (Blueprint $table) {
            $table->boolean('use_streaming')->default(false)->after('secondary_storage_destination_id');
        });
    }

    public function down(): void
    {
        Schema::table('backup_configs', function (Blueprint $table) {
            $table->dropColumn('use_streaming');
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('format');
        });
    }
};
