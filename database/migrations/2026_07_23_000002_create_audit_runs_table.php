<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faza D (D2b): one row per crawl/ingest run of an audit — the JobTracker for the
 * SF crawl. Holds progress, a human log, the resolved-export manifest, and any
 * error. The UI reads this to show a crawl's state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('sf_headless'); // App\Enums\CrawlSource
            $table->string('status')->default('pending');      // App\Enums\AuditRunStatus
            $table->string('crawl_dir')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->jsonb('manifest')->nullable(); // {present, absent, unmatched, total}
            $table->jsonb('log')->nullable();      // list of human log lines
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['audit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_runs');
    }
};
