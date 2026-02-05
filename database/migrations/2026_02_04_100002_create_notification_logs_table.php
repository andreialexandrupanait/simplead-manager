<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('channel_type');
            $table->string('status'); // sent, failed
            $table->text('message')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->integer('response_code')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'event']);
            $table->index(['notification_channel_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
