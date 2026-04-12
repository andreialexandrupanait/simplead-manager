<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('seo_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_page_id')->constrained('seo_pages')->cascadeOnDelete();
            $table->string('target_url', 2048); $table->string('target_url_hash', 64);
            $table->string('type', 20); $table->string('rel', 50)->nullable(); $table->string('anchor_text', 500)->nullable();
            $table->smallInteger('status_code')->nullable(); $table->boolean('is_broken')->default(false); $table->timestamps();
            $table->index(['seo_audit_id', 'type', 'is_broken']); $table->index(['seo_audit_id', 'target_url_hash']);
        });
    }
    public function down(): void { Schema::dropIfExists('seo_links'); }
};
