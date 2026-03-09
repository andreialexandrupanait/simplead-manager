<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->boolean('spf_exists')->default(false);
            $table->text('spf_record')->nullable();
            $table->string('spf_status')->nullable();
            $table->json('spf_issues')->nullable();
            $table->boolean('dkim_exists')->default(false);
            $table->string('dkim_selector')->nullable();
            $table->string('dkim_status')->nullable();
            $table->boolean('dmarc_exists')->default(false);
            $table->text('dmarc_record')->nullable();
            $table->string('dmarc_policy')->nullable();
            $table->string('dmarc_status')->nullable();
            $table->json('blacklists_checked')->nullable();
            $table->unsignedInteger('blacklists_clean')->default(0);
            $table->unsignedInteger('blacklists_listed')->default(0);
            $table->json('mx_records')->nullable();
            $table->unsignedInteger('score')->default(0);
            $table->string('status')->default('unknown');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_health_checks');
    }
};
