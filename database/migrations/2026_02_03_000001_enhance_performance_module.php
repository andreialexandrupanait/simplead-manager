<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // New table: performance_pages
        Schema::create('performance_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_monitor_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('url');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // New columns on performance_tests
        Schema::table('performance_tests', function (Blueprint $table) {
            $table->foreignId('performance_page_id')->nullable()->after('performance_monitor_id')
                ->constrained('performance_pages')->nullOnDelete();
            $table->json('third_party_scripts')->nullable()->after('diagnostics');
            $table->integer('dom_elements')->nullable()->after('third_party_scripts');
            $table->integer('dom_max_depth')->nullable()->after('dom_elements');
            $table->integer('dom_max_children')->nullable()->after('dom_max_depth');
            $table->integer('unused_js_bytes')->nullable()->after('dom_max_children');
            $table->integer('unused_css_bytes')->nullable()->after('unused_js_bytes');
            $table->json('unused_js_details')->nullable()->after('unused_css_bytes');
            $table->json('unused_css_details')->nullable()->after('unused_js_details');
            $table->json('image_audit')->nullable()->after('unused_css_details');
            $table->json('wp_health_checks')->nullable()->after('image_audit');
            $table->text('screenshot_final')->nullable()->after('wp_health_checks');
            $table->json('filmstrip')->nullable()->after('screenshot_final');
        });

        // New column on performance_monitors
        Schema::table('performance_monitors', function (Blueprint $table) {
            $table->json('budgets')->nullable()->after('alert_on_poor_vitals');
        });
    }

    public function down(): void
    {
        Schema::table('performance_tests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performance_page_id');
            $table->dropColumn([
                'third_party_scripts',
                'dom_elements',
                'dom_max_depth',
                'dom_max_children',
                'unused_js_bytes',
                'unused_css_bytes',
                'unused_js_details',
                'unused_css_details',
                'image_audit',
                'wp_health_checks',
                'screenshot_final',
                'filmstrip',
            ]);
        });

        Schema::table('performance_monitors', function (Blueprint $table) {
            $table->dropColumn('budgets');
        });

        Schema::dropIfExists('performance_pages');
    }
};
