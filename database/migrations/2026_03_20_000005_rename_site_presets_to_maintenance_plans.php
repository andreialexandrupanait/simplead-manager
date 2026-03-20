<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop FK constraints
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['applied_preset_id']);
        });

        Schema::table('site_preset_modules', function (Blueprint $table) {
            $table->dropForeign(['site_preset_id']);
            $table->dropUnique(['site_preset_id', 'module_key']);
        });

        // Rename tables
        Schema::rename('site_presets', 'maintenance_plans');
        Schema::rename('site_preset_modules', 'maintenance_plan_modules');

        // Rename columns on sites
        Schema::table('sites', function (Blueprint $table) {
            $table->renameColumn('applied_preset_id', 'maintenance_plan_id');
            $table->renameColumn('is_preset_customized', 'is_plan_customized');
        });

        // Rename FK column on maintenance_plan_modules
        Schema::table('maintenance_plan_modules', function (Blueprint $table) {
            $table->renameColumn('site_preset_id', 'maintenance_plan_id');
        });

        // Recreate FK constraints and unique index
        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('maintenance_plan_id')
                ->references('id')->on('maintenance_plans')
                ->nullOnDelete();
        });

        Schema::table('maintenance_plan_modules', function (Blueprint $table) {
            $table->foreign('maintenance_plan_id')
                ->references('id')->on('maintenance_plans')
                ->cascadeOnDelete();
            $table->unique(['maintenance_plan_id', 'module_key']);
        });
    }

    public function down(): void
    {
        // Drop new FK constraints
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['maintenance_plan_id']);
        });

        Schema::table('maintenance_plan_modules', function (Blueprint $table) {
            $table->dropForeign(['maintenance_plan_id']);
            $table->dropUnique(['maintenance_plan_id', 'module_key']);
        });

        // Rename columns back
        Schema::table('sites', function (Blueprint $table) {
            $table->renameColumn('maintenance_plan_id', 'applied_preset_id');
            $table->renameColumn('is_plan_customized', 'is_preset_customized');
        });

        Schema::table('maintenance_plan_modules', function (Blueprint $table) {
            $table->renameColumn('maintenance_plan_id', 'site_preset_id');
        });

        // Rename tables back
        Schema::rename('maintenance_plans', 'site_presets');
        Schema::rename('maintenance_plan_modules', 'site_preset_modules');

        // Recreate original FK constraints and unique index
        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('applied_preset_id')
                ->references('id')->on('site_presets')
                ->nullOnDelete();
        });

        Schema::table('site_preset_modules', function (Blueprint $table) {
            $table->foreign('site_preset_id')
                ->references('id')->on('site_presets')
                ->cascadeOnDelete();
            $table->unique(['site_preset_id', 'module_key']);
        });
    }
};
