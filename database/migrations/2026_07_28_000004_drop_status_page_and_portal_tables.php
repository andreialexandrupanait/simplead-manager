<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Faza 1.4: drop the public status-page system and the user-invitation table.
 * Status pages (public uptime pages) and user invitations leave the Manager —
 * it is a single-operator tool with no team invitations and no public status
 * portal (SPEC-SAD-MANAGER §1, §3.2). Removed in the same change: the StatusPage*
 * models + Livewire screens + StatusPageController + StatusPageService + the two
 * incident jobs and their event listeners; the Invitation model, UserManagement
 * Livewire, AcceptInvitationController and UserInvitationMail; plus the public
 * ClientPortalController and its /portal routes.
 *
 * NOT dropped here: the `clients.portal_token` / `clients.portal_enabled` columns
 * stay on the kept `clients` table (dropping columns off a live kept table is out
 * of scope); the report public-view (`/r/{report}/{token}` → ReportViewController,
 * reusing the client-portal.report blade) is kept — it is report delivery, not the
 * client portal. Auth and 2FA are untouched.
 *
 * Non-transactional to match the other DDL migrations. CASCADE covers the
 * intra-set FKs (status_page_incident_updates → status_page_incidents,
 * status_page_incidents/templates/sites → status_pages, and → sites); no kept
 * table holds a foreign key INTO this set. Rollback is a no-op — recovery is the
 * mandatory pre-deploy pg_dump.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLES = [
        'status_page_incident_updates',
        'status_page_incidents',
        'status_page_incident_templates',
        'status_page_sites',
        'status_pages',
        'invitations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
    }

    public function down(): void
    {
        // Intentional no-op: status pages and invitations left the Manager in
        // Faza 1.4. See the class docblock for the recovery path.
    }
};
