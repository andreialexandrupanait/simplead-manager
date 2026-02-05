<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_records_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->json('a_records')->nullable();
            $table->json('aaaa_records')->nullable();
            $table->json('cname_records')->nullable();
            $table->json('mx_records')->nullable();
            $table->json('txt_records')->nullable();
            $table->json('ns_records')->nullable();
            $table->json('soa_record')->nullable();
            $table->boolean('has_www')->default(false);
            $table->boolean('uses_cloudflare')->default(false);
            $table->boolean('has_spf')->default(false);
            $table->boolean('has_dmarc')->default(false);
            $table->boolean('has_dkim')->default(false);
            $table->string('mail_provider')->nullable();
            $table->integer('email_security_score')->default(0);
            $table->integer('total_records')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records_cache');
    }
};
