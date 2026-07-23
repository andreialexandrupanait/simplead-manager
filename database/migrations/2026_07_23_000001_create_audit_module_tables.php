<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faza D (D1): the unified SEO/Audit module schema. An audit runs the 82-check
 * methodology against a connected site OR a sales prospect (exactly one). Checks
 * are seeded from methodology-v2/checks.js. No scores/weights anywhere — the only
 * aggregation is "X of Y implemented".
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sales prospects — a site we don't manage yet, audited as a sales tool.
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('profile')->nullable(); // App\Enums\ProspectProfile
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // The catalogue of 82 binary checks (seeded from checks.js).
        Schema::create('audit_checks', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // "2.1.1"
            $table->string('section_key');    // "seo-onsite"
            $table->string('section_nr');     // "02"
            $table->string('section_name');
            $table->string('subsection_id')->nullable(); // "2.1"
            $table->string('subsection_name')->nullable();
            $table->text('question');
            $table->jsonb('sources');   // one or more {type,tab,filters,columns,...}
            $table->string('team')->nullable(); // App\Enums\AuditTeam
            $table->jsonb('lenses')->nullable();
            $table->text('recommendation_template')->nullable();
            $table->string('applicability')->nullable(); // e.g. "ecommerce"
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('section_key');
        });

        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('CONFIGURAT'); // App\Enums\AuditStatus
            $table->string('url');
            $table->text('context_notes')->nullable();
            $table->string('methodology_version')->default('2.0');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['prospect_id', 'created_at']);
        });

        // Exactly one target: a connected site XOR a prospect.
        DB::statement(
            'ALTER TABLE audits ADD CONSTRAINT audits_one_target_chk CHECK '
            .'((site_id IS NOT NULL AND prospect_id IS NULL) OR (site_id IS NULL AND prospect_id IS NOT NULL))'
        );

        Schema::create('audit_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_check_id')->constrained()->cascadeOnDelete();
            $table->string('state')->nullable(); // App\Enums\CheckState; null = unevaluated
            $table->jsonb('evidence')->nullable();
            $table->string('state_set_by')->nullable(); // auto | ai | manual
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->unique(['audit_id', 'audit_check_id']);
        });

        Schema::create('audit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('team')->nullable();
            $table->string('impact')->nullable(); // mare | mediu | mic
            $table->string('effort')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('evidence_text')->nullable();
            $table->jsonb('check_ids')->nullable();
            $table->jsonb('payload')->nullable(); // {table, codeBlocks, callouts, mockup}
            $table->string('validation')->default('DRAFT_AI'); // DRAFT_AI|APROBAT|EDITAT|RESPINS
            $table->string('implementation')->default('NEIMPLEMENTAT'); // IMPLEMENTAT|NEIMPLEMENTAT
            $table->boolean('needs_verification')->default(false);
            $table->boolean('auto_approved')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('audit_id');
        });

        Schema::create('audit_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('access_token')->nullable();
            $table->boolean('token_required')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->longText('html')->nullable();
            $table->jsonb('implemented_state')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Seed the 82 methodology checks (idempotent upsert — DML only, safe on
        // either connection; the tables above are already committed).
        (new \Database\Seeders\AuditChecksSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_reports');
        Schema::dropIfExists('audit_cards');
        Schema::dropIfExists('audit_check_results');
        DB::statement('ALTER TABLE audits DROP CONSTRAINT IF EXISTS audits_one_target_chk');
        Schema::dropIfExists('audits');
        Schema::dropIfExists('audit_checks');
        Schema::dropIfExists('prospects');
    }
};
