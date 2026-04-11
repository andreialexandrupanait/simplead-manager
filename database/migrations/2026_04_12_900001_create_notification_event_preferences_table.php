<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_event_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'notification_channel_id', 'event'], 'notif_event_pref_unique');
            $table->index(['user_id', 'notification_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_event_preferences');
    }
};
