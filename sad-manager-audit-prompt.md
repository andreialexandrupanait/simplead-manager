# SAD Manager — Full Application Audit (Claude Code Prompt)

You are auditing **SAD Manager** (`sad-manager`), an internal WordPress site-operations platform for a digital agency. It is **never client-facing**. It manages client WordPress sites through a connector plugin and performs **destructive, high-risk operations**: backup/restore, plugin pushes to live production sites, security remediation.

**Stack:** Laravel 11 / PHP 8.3, Livewire 4 + Blade + Tailwind, PostgreSQL + PgBouncer, Redis, Horizon (queues), Laravel scheduler, Docker (app, horizon, scheduler, nginx, postgres, pgbouncer, redis), deployed on a Hetzner VPS.

**Known context (verify, don't assume):**
- ~29 controllers, ~91 models, ~121 services, but only ~8 test files. Test coverage is the known critical weakness.
- A WordPress connector plugin is installed on client sites and communicates with manager.simplead.ro.
- The host previously had PostgreSQL exposed publicly via Docker port mapping bypassing ufw (since remediated). Treat Docker networking as a sensitive audit area.
- Integrations: Cloudflare, Google Analytics, Google Search Console, PageSpeed.

**The audit has a dual purpose:**
1. **Technical audit** — code quality, security, reliability (Phases 0–2).
2. **Product audit** — for every module, identify improvements to existing features and propose new features, benchmarked against mature competitors in the same category (SpinupWP, WPMU DEV, ManageWP, MainWP). See Phase 3.

Produce an honest, evidence-based audit. Every finding must cite file paths and line references. No praise padding. Score conservatively.

---

## Phase 0 — Recon & Module Map (main agent, sequential)

1. Read `CLAUDE.md`, `README`, `composer.json`, `docker-compose*.yml`, `.env.example`, `routes/*`, `app/` structure, `config/horizon.php`, scheduler definitions (`routes/console.php` or Kernel).
2. Build a **module inventory**: map every controller, Livewire component, service, model, job, and command to one of the modules in Phase 1. Flag anything that fits no module (orphan code).
3. Output `docs/audit/00-module-map.md` with: module → files, entry points (routes/Livewire/commands/jobs), external integrations touched, and whether the module performs destructive operations (Y/N).
4. Adjust the Phase 1 module list if reality differs from the list below — the code is the source of truth.

## Phase 1 — Per-Module Audits (parallel subagents)

Launch one subagent per module. Each writes `docs/audit/1X-<module>.md`.

**Modules (expected — reconcile with Phase 0 map):**

1. **Sites & Connector Plugin communication** — site registry, plugin API endpoints, authentication of site↔manager traffic (API keys/HMAC/tokens), key rotation, replay protection, what happens if a client site is compromised (blast radius).
2. **Backups & Restore** — scheduling, storage targets, retention, encryption at rest, restore flow, verification of backup integrity, partial-failure handling. **Restore is destructive: audit confirmation flows, dry-run capability, idempotency, rollback.**
3. **Plugin Management** — inventory sync, updates/pushes to live sites, conflict detection, risk assessment logic, staged rollout or all-at-once, failure recovery when a push breaks a live site.
4. **Security Scanning & Incident Response** — scan types, malware/file-integrity checks, vulnerability feeds, incident workflow, alerting, false-positive handling.
5. **Uptime & DNS Monitoring** — check frequency, alert thresholds, flapping protection, notification channels, DNS change detection.
6. **Performance (PageSpeed)** — API quota handling, result storage, trend tracking.
7. **SEO Audits** — audit criteria, scheduling, report generation.
8. **Reports (client maintenance reports)** — data aggregation, PDF/export generation, accuracy of claims made to clients, PII in reports.
9. **Integrations (Cloudflare, GA, GSC)** — credential storage, OAuth token refresh, scope minimization, API error handling.
10. **Leads ingestion** (if present) — ingest endpoint, validation, tenant/site mapping, forwarding to SAD Hub.

**Each subagent must evaluate, per module:**
- **Correctness & completeness** — does it do what it claims? Dead code, half-built features, TODOs.
- **Destructive-operation safety** (where applicable) — confirmations, dry-runs, idempotency, locking (two jobs restoring the same site?), audit logging of who did what to which site.
- **Security** — authz on every entry point (routes, Livewire actions, jobs triggered from UI), mass assignment, SSRF on any URL the manager fetches, injection, secrets in logs.
- **Queue/job hygiene** — retries, timeouts, `failed()` handlers, backoff, uniqueness, what a stuck Horizon queue does to this module.
- **Error handling & observability** — are failures visible or silent? Alerting on failure of critical jobs (backups that silently stop running are worse than no backups).
- **Test coverage** — list what tests exist for this module (likely near zero) and specify the **minimum viable test set**: the 3–7 tests that would catch the most dangerous regressions.
- **Data model** — indexes on hot queries, N+1 in Livewire components, soft-delete consistency, orphaned records.
- **Product improvements** — end each module report with a section `## Improvement opportunities`: (a) 3–5 concrete improvements to *existing* features (UX friction, missing detail, missing automation), and (b) 2–3 *new* feature proposals for this module, each with a one-line rationale and rough effort (S/M/L). Ground proposals in what the code actually supports today.

Severity scale per finding: **P0** (data loss / breaks a live client site / security breach), **P1** (serious defect or exposure), **P2** (quality/debt), **P3** (nice-to-have).

## Phase 2 — Cross-Cutting Audits (parallel subagents)

1. **Security (application-wide)** — auth stack, session config, 2FA presence, authorization policy coverage (every route/Livewire action mapped to a policy or explicitly public), CSRF on Livewire, rate limiting on plugin-facing API endpoints, dependency audit (`composer audit`), secrets handling (`.env` usage, credentials for client sites — where are WP admin credentials / API keys stored, encrypted with what, who can read them).
2. **Infrastructure & Docker** — docker-compose port mappings (verify nothing binds `0.0.0.0` unnecessarily — this host has history here), network segmentation between containers, Postgres/PgBouncer exposure, Redis auth, resource limits, backup of the manager's own database, log rotation.
3. **Queues & Scheduler** — full inventory of scheduled tasks and Horizon queues, overlap protection (`withoutOverlapping`), monitoring that the scheduler itself runs, dead job accumulation, priority separation (a slow SEO audit must not delay a restore).
4. **Testing & CI** — current coverage reality, phpstan level, Pint, CI pipeline gaps, and a **testing remediation plan**: ordered by risk, starting from destructive operations (restore, plugin push) and connector auth. Estimate effort per block.
5. **Consistency & architecture** — service layer discipline across 121 services, duplicated logic, naming conventions, Livewire component size, fat models/controllers.

Each writes `docs/audit/2X-<topic>.md`.

## Phase 3 — Risk-Weighted Synthesis (main agent)

1. Read all Phase 1–2 reports. Deduplicate and reconcile conflicting findings.
2. Build a **risk matrix**: likelihood × impact, where impact is weighted for *client-facing blast radius* (anything that can take down or corrupt a client's live site outranks internal inconvenience).
3. Identify the **top 10 findings overall** with one-line justifications.
4. Explicit **gap analysis vs. stated purpose**: SAD Manager is a WPMU DEV replacement — which WPMU DEV core capabilities are missing, partial, or unreliable?
5. **Competitive benchmark & feature roadmap.** Consolidate the per-module `Improvement opportunities` sections and evaluate SAD Manager against the following capability benchmark (drawn from mature products in the category, esp. SpinupWP). For each item mark: ✅ exists / 🟡 partial / ❌ missing, and whether it's worth building:
   - **Transparent updates** — before applying plugin/theme updates, show the exact list: each plugin/theme, current version → target version (not just a count). Update history per site.
   - **Assistant / Todo system** — an actionable todo feed per site/server with calibrated priorities: *Critical* reserved for down-or-about-to-go-down (disk full, site down, SSL expiring), *High* for security-relevant items. One-click execution of safe remediations from the todo itself.
   - **Dashboard at scale** — table views for sites with type-to-filter, column sorting, show/hide/reorder columns. Must stay usable at 50+ sites.
   - **Bulk actions** — select multiple sites and run an action (update plugin X everywhere, trigger backups, run security scan).
   - **Tags/labels on sites** — production/staging, client name, plan tier; filterable.
   - **Remote configuration management** — change site-level settings (e.g. PHP-related where the connector allows, wp-config constants, maintenance mode) from the dashboard without SSH/WP-admin.
   - **REST API for site management** — programmatic endpoints so SAD Hub / SAD Tasks can trigger and read Manager operations (this is the SAD-ecosystem integration angle).
   - **Guided safety flows** — confirmation modals for risky actions that recommend/trigger a backup first (e.g. "backup before update" as a built-in step, not a habit).
6. Merge everything into a **feature roadmap** (separate from the technical remediation roadmap): Quick wins (S effort, high value) / Strategic (M–L effort) / Not worth it (with reasoning).

### Owner's wishlist (verbatim requirements — treat as mandatory inputs to the roadmap)

<!-- Andrei: adaugă aici cerințele tale. Fiecare linie = o cerință. -->
- [TODO — to be filled by the owner before running the audit]

## Phase 4 — Final Deliverables (main agent)

Write `AUDIT.md` at repo root:
- **Overall score /10** with sub-scores: Security, Reliability of destructive ops, Test coverage, Code quality, Observability, Architecture. Justify each in 2–3 sentences. Score conservatively — 8 test files against 121 services cannot yield a high reliability score regardless of code elegance.
- Top 10 findings table (severity, module, file refs, fix summary).
- **Remediation roadmap in 3 tiers:** (1) *Stop-the-bleeding* — P0s, fixable in days; (2) *Stabilize* — testing of destructive paths + observability, 2–4 weeks; (3) *Harden* — architecture and debt, ongoing.
- A `docs/audit/` index linking all module reports.

Also write `ROADMAP.md` at repo root — the product roadmap from Phase 3.5–3.6: Quick wins / Strategic / Not worth it, each item with module, effort (S/M/L), value rationale, and any technical prerequisites from the audit findings (e.g. "bulk plugin updates require the update-push path to be tested first — see AUDIT.md P0-3"). The owner's wishlist items must all appear in the roadmap, either scheduled or explicitly argued against.

**Rules for all agents:**
- Cite `path/to/file.php:line` for every claim.
- If you cannot verify something, say "unverified" — do not guess.
- Do not fix anything during the audit. Report only.
- Romanian or English is fine for report prose; keep code references verbatim.
