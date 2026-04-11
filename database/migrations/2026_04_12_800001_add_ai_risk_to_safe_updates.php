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
            $table->smallInteger('ai_risk_score')->nullable();
            $table->jsonb('ai_risk_assessment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('safe_updates', function (Blueprint $table) {
            $table->dropColumn(['ai_risk_score', 'ai_risk_assessment']);
        });
    }
};
