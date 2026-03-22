<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->integer('health_score')->nullable();
            $table->string('wp_version')->nullable();
            $table->string('php_version')->nullable();
            $table->string('server_software')->nullable();
            $table->boolean('is_multisite')->default(false);
            $table->decimal('uptime_percentage', 5, 2)->nullable();
            $table->boolean('is_up')->default(true);
            $table->boolean('ssl_ok')->default(true);
            $table->date('ssl_expiry')->nullable();
            $table->integer('pending_updates_count')->default(0);
            $table->boolean('backup_ok')->default(false);
            $table->timestamp('last_backup_at')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
