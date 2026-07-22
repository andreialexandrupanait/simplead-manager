<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-08: proven restore. `sites.is_sandbox` marks the internal WordPress used as
 * the restore target; `sites.proven_restore_enabled` opts a site into the weekly
 * rotation (default off — enabled only for the pilot/test sites). `proven_restores`
 * records each run's outcome so the UI can show a per-site "last proven restore".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('is_sandbox')->default(false)->after('is_prospect');
            $table->boolean('proven_restore_enabled')->default(false)->after('is_sandbox');
        });

        Schema::create('proven_restores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status'); // passed | failed
            $table->jsonb('checks')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('ran_at');
            $table->timestamps();

            $table->index(['site_id', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proven_restores');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['is_sandbox', 'proven_restore_enabled']);
        });
    }
};
