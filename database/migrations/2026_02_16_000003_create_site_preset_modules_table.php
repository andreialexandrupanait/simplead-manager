<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_preset_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_preset_id')->constrained('site_presets')->cascadeOnDelete();
            $table->string('module_key');
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('interval_minutes')->nullable();
            $table->timestamps();

            $table->unique(['site_preset_id', 'module_key']);
        });

        // Backfill from existing JSON column
        $presets = DB::table('site_presets')->whereNotNull('modules')->get();

        foreach ($presets as $preset) {
            $modules = json_decode($preset->modules, true);

            if (! is_array($modules)) {
                continue;
            }

            foreach ($modules as $moduleKey => $config) {
                DB::table('site_preset_modules')->insert([
                    'site_preset_id' => $preset->id,
                    'module_key' => $moduleKey,
                    'is_enabled' => $config['enabled'] ?? false,
                    'interval_minutes' => $config['interval'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Make modules column nullable for rollback safety
        Schema::table('site_presets', function (Blueprint $table) {
            $table->json('modules')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill JSON from normalized table before dropping
        $presets = DB::table('site_presets')->get();

        foreach ($presets as $preset) {
            $modules = DB::table('site_preset_modules')
                ->where('site_preset_id', $preset->id)
                ->get();

            $json = [];
            foreach ($modules as $mod) {
                $json[$mod->module_key] = array_filter([
                    'enabled' => (bool) $mod->is_enabled,
                    'interval' => $mod->interval_minutes,
                ]);
            }

            DB::table('site_presets')
                ->where('id', $preset->id)
                ->update(['modules' => json_encode($json)]);
        }

        Schema::dropIfExists('site_preset_modules');

        Schema::table('site_presets', function (Blueprint $table) {
            $table->json('modules')->nullable(false)->change();
        });
    }
};
