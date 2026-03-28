<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_escalation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_channel_id')->constrained('notification_channels')->onDelete('cascade');
            $table->foreignId('escalation_channel_id')->constrained('notification_channels')->onDelete('cascade');
            $table->integer('delay_minutes')->default(15);
            $table->string('severity')->default('critical');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->string('severity')->nullable()->after('event');
            $table->string('ack_token', 64)->nullable()->unique()->after('response_code');
            $table->timestamp('acknowledged_at')->nullable()->after('ack_token');
            $table->boolean('escalated')->default(false)->after('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_escalation_rules');

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn(['severity', 'ack_token', 'acknowledged_at', 'escalated']);
        });
    }
};
