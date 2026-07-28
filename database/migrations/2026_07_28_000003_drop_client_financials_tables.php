<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 1.3: drop the client financial tables. Money/profitability is owned by
 * SAD Hub, not the Manager (SPEC-SAD-MANAGER §1). The models ClientCost and
 * ClientRevenue, the ClientProfitability Livewire screen and the ClientForm
 * (clients are read-only now) were removed in the same change, along with the
 * Client::costs()/revenues() relations.
 *
 * Non-transactional to match the other DDL migrations (direct, non-PgBouncer
 * connection at deploy). CASCADE covers the FKs from these child tables to
 * `clients`/`sites`; no kept table holds a foreign key INTO this set. Rollback
 * is a no-op — recovery is the mandatory pre-deploy pg_dump.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const FINANCIAL_TABLES = [
        'client_costs',
        'client_revenues',
    ];

    public function up(): void
    {
        foreach (self::FINANCIAL_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    public function down(): void
    {
        // Intentional no-op: client financials left the Manager in Faza 1.3
        // (owned by SAD Hub). See the class docblock for the recovery path.
    }
};
