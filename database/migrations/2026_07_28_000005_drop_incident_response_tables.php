<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 1.5: drop the automated incident-response (auto-remediation) tables.
 * Nothing runs on a client site without approval (SPEC-SAD-MANAGER §2.4), so the
 * AI auto-remediation engine leaves the Manager. Removed in the same change: the
 * IncidentResponse/IncidentResponseAction models, App\Services\IncidentResponse\*
 * (responder, playbooks, action executor, AI agent), the IncidentResponseStatus /
 * IncidentTriggerType enums, IncidentResponseDispatcher, the TriggerIncidentResponse
 * / RecordIncidentRecovery listeners, the RunIncidentResponse job, the
 * AiIncidentResponseSettings screen, factories and tests.
 *
 * KEPT ON PURPOSE (do not confuse with this module):
 *   - Uptime incidents (UptimeIncident model, NotifyIncident job) — monitoring.
 *   - config/incident-response.php + IncidentResponseConfigServiceProvider — they
 *     still hydrate the shared Anthropic AI key/model read by the protected
 *     PluginRiskAssessmentService (config('incident-response.ai.*')). Deleting them
 *     would break that kept service, so they stay as AI-config plumbing.
 *   - The ActivityType::IncidentResponse enum case (historical activity_log rows).
 *
 * Non-transactional to match the other DDL migrations. CASCADE covers the FK from
 * incident_response_actions → incident_responses (and → sites); no kept table
 * holds a foreign key INTO this set. Rollback is a no-op — recovery is the
 * mandatory pre-deploy pg_dump.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLES = [
        'incident_response_actions',
        'incident_responses',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    public function down(): void
    {
        // Intentional no-op: automated incident response left the Manager in
        // Faza 1.5. See the class docblock for the recovery path.
    }
};
