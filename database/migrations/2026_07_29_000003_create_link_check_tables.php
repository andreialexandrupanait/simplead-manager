<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 5.3 — broken-link detection.
 *
 * Each monthly run produces one `link_check_runs` row plus one `link_check_results`
 * row per distinct URL scanned. A full per-run snapshot is required so the next run
 * can diff against it (which broken links are NEW vs which were RESOLVED) — that diff
 * is what the report and the (SC-gated) alert are built from.
 *
 * `sites.check_external_links` is an opt-in, per-site flag: internal links are always
 * checked; external links only when this is on (external hosts are noisy / rate-limit).
 *
 * Expand-only + idempotent, non-transactional to match the other DDL migrations that
 * run on the direct (non-PgBouncer) connection at deploy.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('link_check_runs')) {
            Schema::create('link_check_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('total_urls')->default(0);
                $table->unsignedInteger('broken_count')->default(0);
                $table->unsignedInteger('chains_count')->default(0);
                $table->unsignedInteger('new_broken_count')->default(0);
                $table->unsignedInteger('resolved_count')->default(0);
                $table->timestamps();

                $table->index(['site_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('link_check_results')) {
            Schema::create('link_check_results', function (Blueprint $table) {
                $table->id();
                $table->foreignId('run_id')->constrained('link_check_runs')->cascadeOnDelete();
                $table->text('url');
                $table->text('source_page')->nullable();
                $table->unsignedSmallInteger('status_code')->nullable();
                $table->boolean('is_internal')->default(true);
                $table->boolean('is_broken')->default(false);
                $table->jsonb('redirect_chain')->nullable(); // [{url, status_code}, ...] when redirected
                $table->timestamps();

                $table->index(['run_id', 'is_broken']);
            });
        }

        DB::statement('ALTER TABLE sites ADD COLUMN IF NOT EXISTS check_external_links boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        Schema::dropIfExists('link_check_results');
        Schema::dropIfExists('link_check_runs');
        DB::statement('ALTER TABLE sites DROP COLUMN IF EXISTS check_external_links');
    }
};
