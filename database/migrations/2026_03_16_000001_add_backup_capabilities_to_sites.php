<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('backup_capabilities')->nullable()->after('uploads_size_mb');
            $table->timestamp('backup_capabilities_checked_at')->nullable()->after('backup_capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['backup_capabilities', 'backup_capabilities_checked_at']);
        });
    }
};
