<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_monitors', function (Blueprint $table) {
            $table->jsonb('competitor_urls')->nullable()->after('budgets');
        });

        Schema::table('performance_tests', function (Blueprint $table) {
            $table->boolean('is_competitor')->default(false)->after('device');
            $table->string('competitor_url')->nullable()->after('is_competitor');
        });
    }

    public function down(): void
    {
        Schema::table('performance_monitors', function (Blueprint $table) {
            $table->dropColumn('competitor_urls');
        });

        Schema::table('performance_tests', function (Blueprint $table) {
            $table->dropColumn(['is_competitor', 'competitor_url']);
        });
    }
};
