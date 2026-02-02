<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('type')->default('wordpress')->after('url');
            $table->text('api_key')->nullable()->after('type');
            $table->text('api_secret')->nullable()->after('api_key');
            $table->string('api_endpoint')->nullable()->after('api_secret');
            $table->boolean('is_connected')->default(false)->after('api_endpoint');
            $table->timestamp('last_synced_at')->nullable()->after('is_connected');
            $table->decimal('db_size_mb', 10, 2)->nullable();
            $table->decimal('uploads_size_mb', 10, 2)->nullable();
            $table->string('core_update_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'api_key',
                'api_secret',
                'api_endpoint',
                'is_connected',
                'last_synced_at',
                'db_size_mb',
                'uploads_size_mb',
                'core_update_version',
            ]);
        });
    }
};
