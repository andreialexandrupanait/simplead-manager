<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_updates', function (Blueprint $table) {
            $table->string('screenshot_before_path')->nullable();
            $table->string('screenshot_after_path')->nullable();
            $table->jsonb('visual_regression_results')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('safe_updates', function (Blueprint $table) {
            $table->dropColumn(['screenshot_before_path', 'screenshot_after_path', 'visual_regression_results']);
        });
    }
};
