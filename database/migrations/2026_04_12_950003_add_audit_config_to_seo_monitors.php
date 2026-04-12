<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('seo_monitors', function (Blueprint $table) {
            $table->integer('max_pages')->default(200);
            $table->integer('max_external_link_checks')->default(50);
            $table->string('sitemap_url', 2048)->nullable();
            $table->jsonb('audit_config')->nullable();
        });
    }
    public function down(): void {
        Schema::table('seo_monitors', function (Blueprint $table) { $table->dropColumn(['max_pages','max_external_link_checks','sitemap_url','audit_config']); });
    }
};
