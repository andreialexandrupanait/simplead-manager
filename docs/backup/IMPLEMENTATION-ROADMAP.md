# Implementation Roadmap

> Phased delivery. Nothing merges to production without passing the phase gate. Complexity is
> relative T-shirt sizing (S/M/L/XL) with rough effort.

## Phase 0 — Audit & baseline ✅ (this deliverable)

Feature matrix, current audit, existing-backups inventory + A–F, legacy decision, target
architecture, migration plan, acceptance tests, read-only characterization tests. **No prod
change.** — **Size: M (done).**

## Phase 1 — Foundations (state machine, schema, observability)

- Additive migrations (new tables, nullable cols) + rollback tested in CI.
- `BackupSession`/`RestoreSession` FSM classes with explicit states + transitions.
- Structured per-backup logging + error codes; remove `@`/silent `catch{}` in the create path.
- Read-only `backup:reconcile-storage` command (formalizes this audit) + `used_bytes` truth job.
- Compatibility layer: `LegacyBackupReader` (v2/v3 read).
- **Gate:** migrations reversible; reconcile matches this audit; zero behavior change in prod.
- **Size: L.** Risk: low (additive).

## Phase 2 — New plugin backup engine (full backups)

- New `simplead-backup` WP plugin; port paged DB dump + chunk sessions + capabilities.
- Manager drives FSM: chunked full backup, **hardened S3 multipart** (per-part retry/resume/
  checksum, smaller parts, abandoned-upload reaper), completion-marker + mandatory manifest.
- Connector shim endpoints proxy to new plugin.
- **Gate:** on pilot/sandbox sites, full backup success ≥ current, no sync endpoints used,
  every backup has manifest + marker; multipart failure-injection resumes cleanly.
- **Size: XL.** Risk: medium (this is the core; transport is the historical pain point).

## Phase 3 — Incremental files, chains, retention

- File-diff incremental (changed/new/tombstones vs manifest); DB stays full-dump.
- Chain resolution + restore-from-chain; chain-safe retention (verify dry-run→enable).
- **Gate:** restore from full+N increments reproduces latest state byte-for-byte on fixtures;
  retention never deletes a required base.
- **Size: L.** Risk: medium.

## Phase 4 — Restore (full + selective + rollback)

- Chunked/async restore only (remove 1800s sync path); component presets (db/files/plugins/
  themes/uploads/core); selective folders/tables; maintenance mode; pre-restore safety backup;
  rollback on validation failure; **authenticated** transfer.
- **Gate:** kill-mid-restore rolls back; selective restore touches only selected scope;
  post-restore health checks pass.
- **Size: L.** Risk: medium-high (touches live sites — sandbox first).

## Phase 5 — UI + alerts + quotas

- Manager: fleet overview (health, failures, destination health, running/stale, retention),
  per-site actions (retry/resume/cancel/protect/verify), backup detail (stages/chain/objects/
  logs/verification). Plugin: minimal local status/diagnostics screen.
- Success + storage-limit notifications; quota enforcement on reconciled `used_bytes`.
- **Gate:** operator can drive full lifecycle from UI; alerts fire on both success and failure.
- **Size: L.** Risk: low.

## Phase 6 — Verification & proven restore & legacy import

- Enforce creation verification; scheduled deep-verify (sampled archive open + DB parse);
  make weekly proven-restore actually populate `proven_restores` (0 today); import Category-A/B
  into new index.
- **Gate:** every completed backup has a verification record; ≥1 proven restore per pilot site.
- **Size: M.** Risk: low.

## Phase 7 — Controlled fleet rollout & old-engine retirement

- Per-site feature-flag rollout; compare success/duration/storage/restore vs old engine; no
  duplicate schedules/retention; legacy backups read-only.
- Retire old engine + disable old jobs **only after** all gates below.
- **Size: M.** Risk: medium (fleet-wide).

## Retirement gates (all required, your approval last)

1. New engine stable on pilots ≥ N weeks. 2. Success rate ≥ old (target >97%).
3. Backups verified (creation + sampled deep + proven restore demonstrated).
4. Restore demonstrated on real (sandbox) data. 5. All eligible sites migrated.
6. Legacy backups classified (done) + dispositioned. 7. **Explicit owner approval.**

## Recommended first implementation phase

**Phase 1** — highest leverage, lowest risk: it makes the system *observable and reconcilable*
(directly addressing the phantom-rows/manifest/accounting defects) and lays the schema + FSM +
compatibility foundation without any production behavior change, so Phases 2–4 build on solid
ground.
