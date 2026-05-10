<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('completed_at');
            $table->string('verification_status', 20)->default('never_tested')->after('verified_at');
            $table->text('verification_message')->nullable()->after('verification_status');
            $table->index(['verification_status', 'verified_at'], 'backups_verification_idx');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropIndex('backups_verification_idx');
            $table->dropColumn(['verified_at', 'verification_status', 'verification_message']);
        });
    }
};
