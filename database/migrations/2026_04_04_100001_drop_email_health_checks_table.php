<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::dropIfExists('email_health_checks');

        // Clean up email tweaks from security_settings
        DB::table('security_settings')->where('category', 'email')->delete();
    }

    public function down(): void
    {
        Schema::create('email_health_checks', function ($table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->boolean('spf_exists')->default(false);
            $table->text('spf_record')->nullable();
            $table->string('spf_status')->default('unknown');
            $table->jsonb('spf_issues')->nullable();
            $table->boolean('dkim_exists')->default(false);
            $table->string('dkim_selector')->nullable();
            $table->string('dkim_status')->default('unknown');
            $table->boolean('dmarc_exists')->default(false);
            $table->text('dmarc_record')->nullable();
            $table->string('dmarc_policy')->nullable();
            $table->string('dmarc_status')->default('unknown');
            $table->jsonb('blacklists_checked')->nullable();
            $table->integer('blacklists_clean')->default(0);
            $table->integer('blacklists_listed')->default(0);
            $table->jsonb('mx_records')->nullable();
            $table->integer('score')->default(0);
            $table->string('status')->default('unknown');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }
};
