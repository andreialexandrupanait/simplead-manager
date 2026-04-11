<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->smallInteger('h4_count')->default(0)->after('h3_count');
            $table->smallInteger('h5_count')->default(0)->after('h4_count');
            $table->smallInteger('h6_count')->default(0)->after('h5_count');
        });
    }

    public function down(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->dropColumn(['h4_count', 'h5_count', 'h6_count']);
        });
    }
};
