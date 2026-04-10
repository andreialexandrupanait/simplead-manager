<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop FK, make nullable, re-add FK with nullOnDelete
        Schema::table('site_crawls', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
        });

        DB::statement('ALTER TABLE site_crawls ALTER COLUMN site_id DROP NOT NULL');

        Schema::table('site_crawls', function (Blueprint $table) {
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->string('start_url', 2048)->nullable()->after('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('site_crawls', function (Blueprint $table) {
            $table->dropColumn('start_url');
            $table->dropForeign(['site_id']);
        });

        DB::statement('ALTER TABLE site_crawls ALTER COLUMN site_id SET NOT NULL');

        Schema::table('site_crawls', function (Blueprint $table) {
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });
    }
};
