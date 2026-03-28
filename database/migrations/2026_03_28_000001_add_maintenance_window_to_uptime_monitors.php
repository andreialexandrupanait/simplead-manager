<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->timestamp('maintenance_starts_at')->nullable();
            $table->timestamp('maintenance_ends_at')->nullable();
            $table->string('maintenance_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('uptime_monitors', function (Blueprint $table) {
            $table->dropColumn(['maintenance_starts_at', 'maintenance_ends_at', 'maintenance_reason']);
        });
    }
};
