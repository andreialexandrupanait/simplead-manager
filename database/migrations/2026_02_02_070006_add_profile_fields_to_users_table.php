<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->default('UTC')->after('password');
            $table->string('date_format')->default('M d, Y')->after('timezone');
            $table->string('language')->default('en')->after('date_format');
            $table->boolean('two_factor_enabled')->default(false)->after('language');
            $table->text('two_factor_secret')->nullable()->after('two_factor_enabled');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->string('avatar_path')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'timezone',
                'date_format',
                'language',
                'two_factor_enabled',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'avatar_path',
            ]);
        });
    }
};
