<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_windows', function (Blueprint $table) {
            $table->boolean('update_status_page')->default(false)->after('notify_on_end');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_windows', function (Blueprint $table) {
            $table->dropColumn('update_status_page');
        });
    }
};
