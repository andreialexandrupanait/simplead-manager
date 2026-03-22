<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('maintenance_windows');
    }

    public function down(): void
    {
        // Table was unused dormant feature — re-run original migrations to restore
    }
};
