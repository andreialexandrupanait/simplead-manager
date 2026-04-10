<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_contents', function (Blueprint $table) {
            $table->string('ai_provider', 30)->nullable()->after('persona');
            $table->string('ai_model', 80)->nullable()->after('ai_provider');
        });
    }

    public function down(): void
    {
        Schema::table('seo_contents', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_model']);
        });
    }
};
