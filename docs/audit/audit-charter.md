# SimpleAD Manager — Full-Application Audit, Roadmap & Action Plan
**A prompt for Claude Code · read-only analysis pass**

---

## 0. How to use this prompt

You are auditing a **live production SaaS with real, paying clients already onboarded**. Your job in this pass is **analysis and planning only**. You will produce **one single comprehensive document**. **Do not modify any code, migration, configuration, or data.** Read this entire prompt before you start, then work through the methodology in order.

---

## 1. Mission

SimpleAD Manager is a centralized WordPress management and maintenance platform sold as a service. The long-term goal is for it to **fully replace WPMUDEV Hub, ManageWP, and WP Umbrella** for the sites it manages — not just match them, but be clearly better.

This audit has three sequential objectives, delivered as one document:

1. **Audit** — Verify that the application is functionally complete and that its logic is *correct*. A large part of it already works; the point is to confirm the logic is sound, find where it is wrong, incomplete, fragile, or unsafe, and surface anything that could harm existing clients.
2. **Roadmap** — Turn the findings into a phased, dependency-aware plan that accounts for the **current state** of the codebase and never puts live clients at risk.
3. **To-do backlog** — Break the roadmap into concrete, prioritized, actionable items an engineer (or Claude Code) can execute one by one.

Two distinct tracks run through the whole document and must be **kept clearly separated**:
- **Track 1 — Correctness & Stability** (make what exists *right* and *solid*).
- **Track 2 — "Wow" / Competitive** (what it takes to beat the incumbents).

---

## 2. Absolute constraints — production safety (non-negotiable)

- **Read-only.** No code edits, no new migrations, no `artisan migrate`, no DB writes, no destructive shell commands, no deploys. If you need to run anything, restrict yourself to read-only inspection (reading files, `SELECT`-only queries against a *non-production* copy if one exists, static analysis).
- **Assume every table already has live client rows.** For every problem you find and every change you propose, evaluate the impact on existing data and existing clients explicitly.
- **Every proposed change must carry a "safe-for-live" note**: is it backwards-compatible? Does it need a phased/expand-contract migration? Can it be feature-flagged? What is the rollback path? Flag any change that cannot be shipped without downtime or data risk.
- **The managed sites are also production.** The WordPress agent can execute actions on client sites. Treat any change to the agent, the command queue, or update/backup/security flows as capable of breaking a client's live website. Scrutinize accordingly.
- **Verify against the actual code.** The codebase is the single source of truth for current state. Do not trust assumptions, old specs, or the "known context" below without confirming them in the code. Clearly distinguish **verified facts** (seen in code) from **inferences** (suspected but unconfirmed).

---

## 3. What SimpleAD Manager is (context)

- **Purpose:** Monitoring, backups, security, reporting, updates, and client management across a portfolio of WordPress sites. Currently in the tens of sites; must scale cleanly to **100+**.
- **Stack:** Laravel 11, Livewire 3, Alpine.js, Tailwind CSS, PostgreSQL 16, Redis, Horizon, deployed on AWS EC2 with Docker (multi-container: app, PostgreSQL, Redis, Nginx, Horizon, scheduler).
- **Agent:** A custom WordPress plugin on each managed site communicates back to the platform via a **pull-based command queue** (agent polls, executes, reports back; rollback expected for breaking changes).
- **Rough size:** ~95 tables, ~79 models, ~47 services, ~44 Livewire components, ~41 jobs. Previously used Filament; fully migrated to a custom Livewire interface (watch for leftover Filament remnants).
- **UI language is Romanian; this document and all findings are in English.**

### 3.1 Known context — validate against the codebase, do not assume

These are believed to be true. **Confirm each in the actual code**; note where reality differs. Use them to focus the audit, not to skip verification.

- **Backups** are known to fail on large sites and run too slowly; an overhaul is intended toward **WP-CLI as the primary path with a chunked PHP REST fallback for shared hosting, streaming directly to S3.** Third-party plugin APIs (UpdraftPlus etc.) were rejected due to per-site licensing cost at scale. — **Backups are the highest-risk data path; audit them hardest, and explicitly check whether restore actually works, not just backup.**
- **Security hardening module** reportedly has an implementation brief covering six categories (General Hardening, .htaccess, Login Protection, Captcha, Activity Logging, IP Management), a pull-based command-queue agent, ~7 DB tables, and a 0–100 security score.
- **PDF reporting** uses Browsershot/Puppeteer (Chromium), not DomPDF; a live HTML report page is the primary surface with PDF export, plus a recommendations approval workflow.
- **Docker / production** has known gaps: missing Nginx security headers, default credentials, missing health checks, and manual `docker cp` deployment across containers.
- **Horizon reportedly runs only ~2 supervisors**, risking heavy backup jobs starving time-sensitive notifications. Verify supervisor/queue topology and priorities.

---

## 4. Audit methodology

### 4.1 Phase 0 — Inventory & current-state map (do this first)

Before auditing anything, build a map from the real codebase:
- Enumerate the **modules/features** actually present (routes, Livewire components, services, jobs, models, tables).
- For each module, list the files, tables, models, services, components, and jobs that make it up.
- Reconcile against the seed list in §5 — add anything missing, remove anything that doesn't exist.
- Identify **cross-cutting infrastructure**: auth/multi-tenancy, agent protocol, queue/Horizon, scheduler, external integrations, config/secrets, deployment.
- Produce a one-line status guess per module (Complete / Partial / Stubbed / Broken / Dead) to prioritize depth — then verify during the deep audit.

### 4.2 Per-module deep audit — apply this checklist to every module

For **each** module, work through these dimensions and report findings with evidence:

1. **Purpose & footprint** — what it does; the files/tables/models/services/components/jobs involved.
2. **Implementation status** — Complete / Partial / Stubbed / Broken / Dead, with evidence (don't guess from names — read the code paths).
3. **Logic correctness** — trace the primary flows end-to-end. Look for: wrong assumptions, incorrect state transitions, missing/weak validation, off-by-one and boundary errors, incorrect conditionals, silent failure, non-idempotent operations, and race conditions.
4. **Data & multi-tenancy** — schema soundness, foreign keys, cascade/orphan risks, and **tenant isolation** (can one client's data ever leak into another client's view or action?). Assess migration safety for existing rows.
5. **Failure modes & resilience** — behavior on timeouts, partial failures, retries, and when an external dependency (Google, Cloudflare, S3, the agent, the managed site) is down or slow. Is failure isolated or does it cascade?
6. **Async/queue correctness** — job idempotency, retry/backoff, ordering, deduplication, poison-job handling, and which Horizon queue/supervisor the job runs on.
7. **Performance & scale (target 100+ sites)** — N+1 queries, unbounded queries, missing indexes, memory usage on large sites, and whether batch/chunk behavior holds up as the portfolio grows.
8. **Security** — authorization on every sensitive action, secret handling, injection, and SSRF risk from the platform fetching content from managed sites or third parties.
9. **Consistency & tech debt** — duplicated logic, leftover Filament remnants, dead code, and naming/pattern drift.
10. **Test coverage** — are the critical paths tested at all?
11. **Competitive note (feeds Track 2 / Part C)** — what WPMUDEV / ManageWP / WP Umbrella do in this area that SimpleAD doesn't. Mark as a WOW candidate; keep the detail in Part C, not here.

**For every finding, record:** Severity (`Critical` / `High` / `Medium` / `Low`) · Evidence (`path/to/file.php:line` or component/table) · Impact (call out impact on live clients specifically) · Recommendation · **Safe-for-live note** (how to fix without breaking existing clients).

### 4.3 Cross-cutting / architecture audit

Audit these system-wide concerns separately from the per-module work:

- **Auth & multi-tenancy backbone** — the isolation model itself. This is the single most important thing to get right on a multi-client platform; scrutinize hard for any cross-tenant leakage.
- **WP agent ↔ platform protocol** — authentication, message integrity, **command authorization** (can a compromised platform or spoofed agent run arbitrary actions on a client site?), the pull-based queue mechanics, and the **rollback mechanism** (does it actually recover a broken client site?).
- **Queue & Horizon architecture** — supervisor/queue topology, queue separation and priorities (validate the suspected backups-vs-notifications starvation risk), failed-job handling, and scheduler reliability.
- **External integrations** — Google Analytics, Google Search Console, Cloudflare: token storage and refresh, quota/rate-limit handling, and failure isolation.
- **Backups subsystem** (cross-cutting, highest data risk) — correctness, **backup integrity/verifiability**, the **restore path and whether it is tested**, S3 streaming, and large-site handling.
- **Notifications** — delivery guarantees, deduplication, and channel fallback (Slack/Telegram/email).
- **Reporting / PDF** — data accuracy, the recommendations approval workflow, and rendering reliability.
- **Config, secrets & environment** — hardcoded or default credentials, secret exposure, environment separation.
- **Docker, deployment & observability** — health checks, the manual `docker cp` deployment risk, Nginx security headers, logging, monitoring of the *platform itself*, and whether the platform backs *itself* up.
- **Error/log aggregation** — correctness and signal-to-noise.

### 4.4 Competitive "wow" analysis (Track 2 — kept separate)

Benchmark SimpleAD against **WPMUDEV Hub, ManageWP, and WP Umbrella** across the capability categories below. Where helpful, verify current competitor capabilities before asserting them. For each category, rate SimpleAD's coverage (`None` / `Partial` / `Full`), state the gap, and describe the "wow" version worth building. **Keep all of this in Part C** — do not mix it into the correctness audit.

Categories to benchmark:
- Bulk operations at scale (one-click updates across all sites; bulk plugin/theme management)
- **Safe updates** (auto pre-update backup + visual-regression / auto-rollback on failure)
- Uptime & health monitoring depth (and SLA reporting)
- Backup UX (incremental, one-click restore, off-site, retention policies, restore testing)
- Security (malware/vulnerability scanning, firewall, hardening, audit log)
- Performance (Lighthouse trends, actionable recommendations)
- Client-facing (white-label reports, client dashboards, **client billing/subscriptions**, status pages)
- Team & collaboration (roles, granular permissions, 2FA, activity log)
- SEO / broken-link checking / analytics surfacing
- Automation, scheduling & alerting integrations
- Onboarding speed and agent-install UX

---

## 5. Modules to cover (seed list — reconcile with the actual code)

At minimum, audit each of the following. **Add any module found in Phase 0 that isn't listed; drop any that doesn't exist.**

- Uptime monitoring
- SSL / domain-expiry monitoring
- Performance monitoring (Lighthouse)
- Automated backups *(known problem area — audit hardest)*
- WordPress core/plugin/theme updates management
- Security hardening *(brief reportedly exists)*
- Google Analytics integration
- Google Search Console integration
- Cloudflare management
- PDF / live HTML maintenance reports
- Multi-channel notifications (Slack / Telegram / email)
- Client management
- Public status pages
- Error log aggregation
- The WordPress agent plugin & command-queue protocol *(cross-cutting — §4.3)*
- Billing / subscriptions *(if present — confirm)*

---

## 6. Prioritization framework

Score and tag every finding and every proposed item so the roadmap and backlog are triageable:

- **Type:** `Fix` (correctness/bug) · `Harden` (stability, scale, security) · `Wow` (competitive/new capability)
- **Priority:** `P0` (breaks clients or corrupts/leaks data — act now) · `P1` (important, near-term) · `P2` (valuable, not urgent) · `P3` (nice-to-have)
- **Effort:** `S` / `M` / `L` / `XL`
- **Risk to live clients:** `Low` / `Medium` / `High`
- **Dependencies:** what must land first

Anything touching **data integrity, tenant isolation, backups/restore, or the agent's ability to alter a client site** defaults to elevated priority.

---

## 7. Required deliverable — ONE document, this exact structure

Produce a single Markdown document, in English, with a linked table of contents and the following parts:

**Executive Summary**
- Overall application health verdict in a few sentences.
- A **health scorecard table**: per module, rate `Correctness`, `Stability`, `Scale-readiness`, `Security` (1–5 each) plus an overall app health score.
- Top 5 risks to existing clients (P0/P1), and top 5 "wow" opportunities.

**Part A — Per-module correctness & stability audit** *(Track 1)*
- One subsection per module, each following the §4.2 checklist, with evidence-backed findings.

**Part B — Cross-cutting / architecture audit** *(Track 1)*
- The §4.3 concerns, each with findings, severity, and safe-for-live notes.

**Part C — Competitive gap analysis & "wow" opportunities** *(Track 2, kept separate)*
- The §4.4 benchmark, per category, with coverage rating, gap, and the proposed "wow" build.

**Part D — Roadmap**
- Phased and dependency-ordered (suggested arc: **Phase 1 — Fix/Stabilize**, **Phase 2 — Harden & Scale**, **Phase 3 — Wow/Differentiate** — but sequence by real dependency and risk, not dogmatically). For each phase: goal, included items, why-now, dependencies, client-safety considerations, rough effort.

**Part E — To-do backlog**
- A flat, prioritized list (P0 first). Each item: `ID` · title · Type · Module · Priority · Effort · Risk-to-live · Dependencies · acceptance criteria · **safe-rollout note**.

**Appendix**
- Anything you could not verify, open questions, and assumptions made.

---

## 8. Report quality bar

- **Evidence-based.** Cite concrete `file:line` / component / table references. No hand-waving.
- **No large verbatim code dumps.** Reference locations; include only short snippets where essential to make a point.
- **Fact vs inference.** Explicitly mark what you verified in code versus what you suspect but couldn't confirm.
- **Specific, not generic.** "Add caching" is useless; "`SiteMetricsService::collect()` runs one query per site in a loop (N+1) at `path:line`; batch it" is useful.
- **Client-safety first.** Whenever a finding or fix could affect existing clients or their managed sites, say so plainly and describe the safe path.
- **Flag blockers.** If something is unverifiable read-only (e.g., needs a real backup/restore run), note it as a follow-up rather than guessing.
