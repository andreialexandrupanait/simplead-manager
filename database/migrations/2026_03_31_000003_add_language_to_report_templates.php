<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_templates', function (Blueprint $table) {
            $table->string('language', 5)->default('ro')->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('report_templates', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
