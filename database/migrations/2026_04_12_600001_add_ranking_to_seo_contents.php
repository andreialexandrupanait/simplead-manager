<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_contents', function (Blueprint $table) {
            $table->float('ranking_position')->nullable()->after('keyword_density');
            $table->date('ranking_date')->nullable()->after('ranking_position');
        });
    }

    public function down(): void
    {
        Schema::table('seo_contents', function (Blueprint $table) {
            $table->dropColumn(['ranking_position', 'ranking_date']);
        });
    }
};
