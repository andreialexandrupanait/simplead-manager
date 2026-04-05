<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('county', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->boolean('vat_payer')->default(false);
            $table->string('company_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['county', 'postal_code', 'vat_payer', 'company_status']);
        });
    }
};
