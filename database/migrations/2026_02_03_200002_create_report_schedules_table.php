<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_template_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('frequency'); // weekly, monthly
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sunday
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('time', 5)->default('08:00');
            $table->string('timezone')->default('Europe/Bucharest');
            $table->string('period'); // last_7_days, last_30_days, last_month, custom
            $table->json('recipient_emails')->nullable();
            $table->boolean('send_copy_to_admin')->default(true);
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_logo_path')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
