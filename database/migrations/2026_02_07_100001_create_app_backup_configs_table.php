<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_backup_configs', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('frequency')->default('daily'); // daily, weekly, monthly
            $table->string('time')->default('02:00');
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0=Sunday..6=Saturday
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1..28
            $table->string('timezone')->default('Europe/Bucharest');
            $table->string('type')->default('full'); // full, database, config, storage
            $table->json('components')->nullable(); // e.g. ["database","env","storage","logs","codebase"]
            $table->foreignId('storage_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->string('retention_type')->default('count'); // count, days
            $table->unsignedInteger('retention_value')->default(7);
            $table->boolean('encrypt_backup')->default(false);
            $table->text('encryption_password')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->timestamp('next_backup_at')->nullable();
            $table->string('last_backup_status')->nullable();
            $table->timestamps();
        });

        // Seed default disabled row
        DB::table('app_backup_configs')->insert([
            'is_enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00',
            'timezone' => 'Europe/Bucharest',
            'type' => 'full',
            'components' => json_encode(['database', 'env', 'storage']),
            'retention_type' => 'count',
            'retention_value' => 7,
            'encrypt_backup' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_backup_configs');
    }
};
