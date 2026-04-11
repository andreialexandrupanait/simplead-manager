<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_keywords', function (Blueprint $table) {
            $table->boolean('is_brand')->default(false)->after('keyword');
            $table->text('landing_page_url')->nullable()->after('is_brand');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_keywords', function (Blueprint $table) {
            $table->dropColumn(['is_brand', 'landing_page_url']);
        });
    }
};
