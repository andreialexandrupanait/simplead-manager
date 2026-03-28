<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table) {
            $table->decimal('sla_target', 5, 2)->nullable()->after('auto_incidents');
            $table->boolean('show_sla')->default(false)->after('sla_target');
        });
    }

    public function down(): void
    {
        Schema::table('status_pages', function (Blueprint $table) {
            $table->dropColumn(['sla_target', 'show_sla']);
        });
    }
};
