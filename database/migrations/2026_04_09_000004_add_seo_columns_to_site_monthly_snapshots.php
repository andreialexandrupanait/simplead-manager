<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_monthly_snapshots', function (Blueprint $table): void {
            $table->smallInteger('seo_score')->nullable();
            $table->integer('seo_issues_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_monthly_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['seo_score', 'seo_issues_count']);
        });
    }
};
