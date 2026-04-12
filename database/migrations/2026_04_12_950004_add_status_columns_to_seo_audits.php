<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->string('status', 30)->default('pending');
            $table->text('error_message')->nullable();
            $table->jsonb('category_scores')->nullable();
            $table->integer('sitemap_urls_count')->nullable();
            $table->jsonb('security_headers')->nullable();
            $table->jsonb('ssl_info')->nullable();
            $table->jsonb('redirect_info')->nullable();
            $table->jsonb('robots_txt_data')->nullable();
        });
    }
    public function down(): void {
        Schema::table('seo_audits', function (Blueprint $table) { $table->dropColumn(['status','error_message','category_scores','sitemap_urls_count','security_headers','ssl_info','redirect_info','robots_txt_data']); });
    }
};
