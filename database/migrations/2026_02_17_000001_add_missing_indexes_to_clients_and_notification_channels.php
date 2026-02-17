<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! $this->indexExists('clients', 'clients_status_index')) {
                $table->index('status');
            }
        });

        Schema::table('notification_channels', function (Blueprint $table) {
            if (! $this->indexExists('notification_channels', 'notification_channels_is_active_index')) {
                $table->index('is_active');
            }
            if (! $this->indexExists('notification_channels', 'notification_channels_type_index')) {
                $table->index('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if ($this->indexExists('clients', 'clients_status_index')) {
                $table->dropIndex(['status']);
            }
        });

        Schema::table('notification_channels', function (Blueprint $table) {
            if ($this->indexExists('notification_channels', 'notification_channels_is_active_index')) {
                $table->dropIndex(['is_active']);
            }
            if ($this->indexExists('notification_channels', 'notification_channels_type_index')) {
                $table->dropIndex(['type']);
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return DB::selectOne(
                "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
                [$table, $index]
            ) !== null;
        }

        if ($driver === 'sqlite') {
            return DB::selectOne(
                "SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $index]
            ) !== null;
        }

        // MySQL/MariaDB
        $result = DB::selectOne(
            "SHOW INDEX FROM {$table} WHERE Key_name = ?",
            [$index]
        );

        return $result !== null;
    }
};
