<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-29: the daily health digest previously force-emailed every user. Give each
 * user an explicit per-user opt-out flag so SendDailyDigest can respect it.
 * Defaults to true to preserve the current behaviour for existing users (nobody
 * is silently unsubscribed on deploy); a user who opts out is then never mailed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('digest_enabled')->default(true)->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('digest_enabled');
        });
    }
};
