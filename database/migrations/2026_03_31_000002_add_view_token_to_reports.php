<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('view_token', 32)->nullable()->unique()->after('data_snapshot');
        });

        // Backfill existing reports with random tokens
        $reports = DB::table('reports')->whereNull('view_token')->pluck('id');
        foreach ($reports as $id) {
            DB::table('reports')
                ->where('id', $id)
                ->update(['view_token' => Str::random(32)]);
        }
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('view_token');
        });
    }
};
