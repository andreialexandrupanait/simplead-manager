<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain-registration expiry monitoring (distinct from the TLS certificate
 * expiry already captured per uptime check). Clients lose domains they forget
 * to renew; this tracks the registry expiration via RDAP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('domain_expires_at')->nullable()->after('url');
            $table->string('domain_registrar')->nullable()->after('domain_expires_at');
            $table->string('domain_status', 32)->nullable()->after('domain_registrar');
            $table->timestamp('domain_checked_at')->nullable()->after('domain_status');
            $table->string('domain_last_error')->nullable()->after('domain_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'domain_expires_at', 'domain_registrar', 'domain_status',
                'domain_checked_at', 'domain_last_error',
            ]);
        });
    }
};
