<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_plugins', function (Blueprint $table) {
            $table->text('license_key')->nullable();
            $table->timestamp('license_expires_at')->nullable();
            $table->string('license_status', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_plugins', function (Blueprint $table) {
            $table->dropColumn(['license_key', 'license_expires_at', 'license_status']);
        });
    }
};
