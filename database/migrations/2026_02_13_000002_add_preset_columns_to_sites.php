<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('applied_preset_id')->nullable()->constrained('site_presets')->nullOnDelete();
            $table->boolean('is_preset_customized')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_preset_id');
            $table->dropColumn('is_preset_customized');
        });
    }
};
