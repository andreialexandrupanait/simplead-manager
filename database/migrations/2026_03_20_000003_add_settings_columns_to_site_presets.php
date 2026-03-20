<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_presets', function (Blueprint $table) {
            $table->jsonb('security_settings')->nullable();
            $table->jsonb('tweak_settings')->nullable();
            $table->boolean('include_modules')->default(true);
            $table->boolean('include_security')->default(false);
            $table->boolean('include_tweaks')->default(false);
            $table->foreignId('source_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_presets', function (Blueprint $table) {
            $table->dropForeign(['source_site_id']);
            $table->dropForeign(['created_by']);
            $table->dropColumn([
                'security_settings',
                'tweak_settings',
                'include_modules',
                'include_security',
                'include_tweaks',
                'source_site_id',
                'created_by',
            ]);
        });
    }
};
