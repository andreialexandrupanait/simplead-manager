# simplead-backup — PROJECT STATUS (sursă vie de adevăr)

> Actualizat la finalul fiecărei faze interne. Directiva: [`DIRECTIVE-simplead-backup.md`](DIRECTIVE-simplead-backup.md).
> Design: [`TARGET-ARCHITECTURE.md`](TARGET-ARCHITECTURE.md) · Roadmap: [`IMPLEMENTATION-ROADMAP.md`](IMPLEMENTATION-ROADMAP.md)
> Acceptanță: [`ACCEPTANCE-TESTS.md`](ACCEPTANCE-TESTS.md) · Decizii: [`DECISION-LOG.md`](DECISION-LOG.md)

**Verdict curent:** ✅ **READY FOR PILOT (lab/staging)** — motorul V2 complet și dovedit end-to-end în laborator;
audit independent de securitate + calitate APROBAT (0 critice, 0 majore). Precondiție înainte de un site CLIENT
real (nu blochează pilotul gated de owner): resolvere de credențiale/S3 de producție + wiring healthCheck/
preRestoreBackup — vezi `KNOWN-LIMITATIONS.md`. Pilotul real pornește DOAR la instrucțiunea owner `DA PILOT BACKUP V2`.
**Branch:** `feature/simplead-backup-production-ready` (din `feature/snapshot-parity-backup-engine` + merge spike).
**Producție:** neatinsă. V2 dezactivat implicit (toate flag-urile `config/backup_v2.php` = false).

## Stare pe faze

| Fază | Titlu | Stare | Dovezi |
|---|---|---|---|
| P0 | Setup branch / bază / laborator / flags | ✅ | branch + spike merge; `config/backup_v2.php`; lab Laravel (Laravel 12.64, migrări OK, teste rulează) |
| P1 | Fundația Laravel (FSM, migrări, observabilitate) | ✅ | 34 teste passed (450 assert); migrări reversibile; Pint PASS; `app/Backup/V2/*` |
| P2 | Plugin simplead-backup + backup de bază (nucleul) | ✅ | **backup FULL end-to-end** condus de FSM: DB consistent (0 orfani) + fișiere (oracle) + multipart întărit + manifest+`_COMPLETE`; restore-oracle 41/41 + DB 0 erori; resume-fără-dublare; 32 teste E2E |
| P3 | Incremental + chain-uri + retenție | ✅ | incremental fișiere (changed/new/tombstones) + chain (full+2inc restore-oracle 0/0, chain rupt detectat) + retenție chain-safe; suita 87 teste (784 aserțiuni) |
| P4 | Restore (full + selectiv + rollback) | ✅ | restore full (MIRROR+SAFE_MERGE) + din chain + selectiv + **kill-mid-restore→rollback (site byte-identic)** + swap atomic DB/fișiere; suita 98 teste (861 aserțiuni) |
| P5 | UI Manager + UI plugin + alerte + cote | ✅ | UI Livewire V2 (global/per-site/detail) gated de flag + UI plugin (diagnostice, support-package redactat) + cote + alerte; 122 teste (918 aserțiuni) |
| P6 | Verificare + proven restore + import legacy | ✅ | verificare la creare + deep-verify + **proven restore real (scrie rând `passed` — defectul 0-rânduri închis)** + import legacy read-only; suita 137 teste (989 aserțiuni) |
| P7 | Pregătire rollout + raport final | ✅ | 14 documente producție (incl. SECURITY-REVIEW/TEST-EVIDENCE/KNOWN-LIMITATIONS + runbook-uri rollout/rollback); ZIP plugin + sha256; audit final APROBAT |

Legendă: ✅ trecut poartă · 🟡 în lucru · ⬜ neînceput · ❌ blocat

## Definition of Done (30) — urmărire

Toate ⬜ până sunt demonstrate prin teste. Se completează pe măsură ce fazele trec porțile.
Vezi lista completă în [`DIRECTIVE-simplead-backup.md`](DIRECTIVE-simplead-backup.md) (secțiunea DEFINITION OF DONE).

## Jurnal de faze (cel mai recent sus)

### P6 — Verificare + proven restore + import legacy ✅ (poartă trecută)
- `Verification\BackupVerifier` (verificare la creare: manifest+`_COMPLETE`+obiecte size/sha256 → record `backup_verifications`),
  `Verification\DeepVerifyService` + `backup:v2-deep-verify` (sampled: deschide arhive + parse SQL + composite).
- `ProvenRestore\ProvenRestoreService` + `backup:v2-proven-restore`: restore în sandbox + health-check → **scrie rând
  real `ProvenRestore`** (închide defectul „proven_restores gol / 0 rânduri").
- `Legacy\LegacyImportService` + `backup:v2-import-legacy`: clasifică backup-urile legacy (A–F) + index read-only
  (`legacy_backup_index`), FĂRĂ mutare/ștergere; restore legacy gated de `legacy_restore_enabled`.
- Migrări aditive `000004_backup_verifications` + `000005_legacy_backup_index`. Comenzi inerte fără flag.
- **Rerulat de mine:** proven restore backup bun → rând `passed`, backup corupt → rând `failed`; verificarea prinde
  corupția; import legacy read-only. **137 teste (989 aserțiuni), Pint PASS.** (24 eșecuri inițiale = izolare DB între
  teste, reparate; connector neatins.)

### P5 — UI + alerte + cote ✅ (poartă trecută)
- Livewire V2 izolat: `BackupV2Overview` (health/sesiuni/destinații/storage+cote/orfani/proven-restore/alerte),
  `SiteBackupV2` (acțiuni backup/restore/retry/resume/pause/cancel/protect/delete + scope/excluderi/preview),
  `BackupV2Detail` (state/stage/progress/chain/manifest/objects/checksums/verification). Views Tailwind în stilul existent.
- Gating dublu: middleware `EnsureBackupV2Ui` (404 = invizibil când flag off) + `mount()` per-componentă (admin-only).
  Rute `/backup-v2` + alias middleware = strict aditive; UI/rutele V1 neatinse.
- `SessionActions` (seam de acțiuni), `QuotaService` (enforce pe `used_bytes` reconciliat, gated), `BackupV2Notifier`
  + mail-uri (succes + limită storage), toate default off.
- Plugin: pagină admin (diagnostice read-only + support-package cu **redactare secrete**).
- **Rerulat de mine (izolat):** 122 teste (918 aserțiuni), gating dovedit (flag off → ascuns, non-admin → ascuns),
  cote (depășire → blocat), notificări (Mail::fake). Pint + PHPStan curat. V2 invizibil în producție cu flag-urile false.

### P4 — Restore ✅ (poartă trecută)
- Plugin v0.4.0: `class-restore-engine.php` (staging + swap ATOMIC fișiere journaled + DB `sambk_stg_*`/`sambk_old_*`
  RENAME multi-tabel, rollback garantat, tombstones, moduri, scope), endpoint-uri `restore/prepare|stage-chunk|apply|commit|rollback|status` (HMAC+nonce). Fără shell_exec.
- Laravel: `Restore\RestoreRunner` (FSM complet: validating_backup→pre_restore_backup→downloading→verifying→
  maintenance→db/file restore→post_restore_validation→completed / rollback→failed), `RestorePlan`, `RestoreMode`
  (safe_merge/mirror), job inert `RunRestoreSessionJob` (dublă poartă enabled+restore_enabled).
- Maintenance DOAR pe fereastra de apply; verify-before-apply; pre-restore safety backup obligatoriu la MIRROR;
  transfer autentificat (stage-chunk = body RAW semnat HMAC).
- **Rerulat de mine:** restore full round-trip (MIRROR reproduce exact, SAFE_MERGE păstrează adăugările), din chain
  (full+2inc, tombstones aplicate), selectiv (scope respectat), **kill-mid-restore → site byte-identic cu pre-apply
  prin reverse-journal**, DB oracle real (round-trip/selectiv/rollback). **98 teste (861 aserțiuni), Pint + PHPStan curat.**

### P3 — Incremental + chain + retenție ✅ (poartă trecută)
- Plugin v0.3.0: `class-file-diff.php` (changed/new/tombstones; fast-path unchanged pe size+mtime, sha256 autoritativ);
  `files/inventory` acceptă `base_manifest` → plan doar changed+new + tombstones.
- Laravel: `Chain\ChainResolver` (resolveChain ordonat + `materialize` full→inc→inc cu latest-wins + tombstones;
  `BrokenChainException` la chain rupt), `Retention\ChainRetentionService` (nu șterge bază cu incrementale dependente,
  ultimul full valid, `protected`, ultimul verificat; dry-run implicit + dublă poartă force+enabled).
- `BackupRunner` cale incrementală (`baseManifestProvider`); migrare aditivă `000003_add_retention_fields` (redenumită
  de mine din 000002 ca să evit coliziunea cu create_restore_sessions; migrate:fresh + ordinea verificate).
- DB = full dump la fiecare backup (nu incremental DB).
- **Rerulat de mine:** restore-din-chain (full+2inc) **0 lipsă/0 nepotriviri + tombstones aplicate**, chain rupt
  detectat, retenția păstrează bazele dependente. **87 teste (784 aserțiuni), Pint + PHPStan L5 curat.**

### P2 — Nucleul ✅ (poartă trecută — BACKUP FULL end-to-end dovedit restaurabil)
**Bucata 4 — orchestrare FSM end-to-end (LARAVEL-ORCHESTRATOR) — REVIEW independent PASS (32 teste, 285 aserțiuni):**
- `App\Backup\V2\Orchestration\BackupRunner` conduce `BackupSession` prin tot graful FSM (fiecare tranziție prin
  `transitionTo`): capability_check→inventory→database_export→uploading→upload_verifying→finalizing→completed.
- Client HMAC `SimpleadBackupClient` (nonce obligatoriu), `S3ClientFactory` (MinIO lab; prod=StorageDestination TODO),
  endpoint plugin NOU `database/chunk-download` (v0.2.0), job inert `RunBackupSessionJob`.
- Verify-before-complete: orice obiect lipsă/nepotrivit sau dump inconsistent → `corrupt` (nu `completed`); `_COMPLETE` ultimul.
- **Rerulat de mine:** backup FULL `completed` (obiecte size+sha256 verificate), restore-oracle fișiere **41/41 (0/0)**,
  DB import real **0 erori / 59 tabele** (Woo/HPOS), **empty-chunk skip**, **resume după crash — fiecare chunk o dată,
  0 re-upload**. Pint + PHPStan (level 5) 0 erori pe `app/Backup/V2`.

**Rămâne (hardening, se poate face în P3+):** resolvers de producție (`S3ClientFactory::forDestination` decrypt +
`SimpleadBackupClient::forSite`); fallback non-InnoDB; `DiskSpaceGuard` pre-dump; verificare sha256 la `upload_verifying`
prin S3 checksum în loc de re-download (obiecte mari); shim connector.


**Bucata 3 — upload S3 multipart întărit (STORAGE) — REVIEW independent PASS (13 teste, 69 aserțiuni pe MinIO):**
- `App\Backup\V2\Storage\HardenedMultipartUploader` + `ObjectLayout` + progress stores (in-memory + `confirmed_parts` jsonb).
- Model pull server-side; părți 16 MiB (D-005); retry/backoff/jitter per parte; checksum per parte; resume din jsonb
  (același UploadId, fără reîncărcare); reaper fără dangling; abort curat; TTL presigned scurt. D-005 închis.
- **Rerulat de mine:** resume-fără-reîncărcare ✓, reaper-fără-dangling ✓, checksum mismatch ✓, failure-injection ✓, Pint PASS.
- Notă lab: `lab-php` conectat la `sam_spike_net` (`docker network connect`) ca să vadă `spike-minio` — efemer (re-conectează după recreate; alternativ serviciu `lab-minio` în compose). `.env` minimal de lab adăugat (gitignored) → rulări curate.


**Livrat & dovedit (bucata 1 — WORDPRESS-ENGINE):**
- Plugin NOU `wordpress-plugin/simplead-backup/` independent: header + `SAM_BACKUP_VERSION`, REST namespace
  propriu `simplead-backup/v1`, opțiuni proprii `sam_backup_*`, temp propriu, log + diagnostic proprii,
  auth HMAC-SHA256 + timestamp + **nonce OBLIGATORIU** + anti-replay. Connectorul NU e atins.
- Endpoint `capabilities` (PHP/WP/DB/extensii/disk/multisite/snapshot-supported; `shell_exec` doar raportat, niciodată folosit).
- **Dump DB consistent** (`class-consistent-dumper.php`): o conexiune, `START TRANSACTION WITH CONSISTENT
  SNAPSHOT`, ORDER BY pe PK, binar→hex, output segmentat gzip. **REVIEW independent (rerulat de mine):
  0 orfani pe MySQL 8.0.46 ȘI MariaDB 11.8** (contrast paged 60/62), CRC32 binar identic, Woo/HPOS + Multisite.
- Sintaxă PHP OK pe toate fișierele; connectorul neatins.

**Bucata 2 — inventar + excluderi + chunking fișiere (WORDPRESS-ENGINE) — REVIEW independent PASS:**
- `class-exclusions.php` (folder/file/glob/ext/prefix/size/age + include-only; `exclusion_policy_hash` stabil;
  path-traversal guard), `class-inventory.php` (walk cu excludere-înainte-de-citire; determinist),
  `class-file-chunker.php` (grupare pe director, fișier mare = chunk propriu, ZipArchive STORE streaming,
  empty-chunk skip, pull-and-free), endpoint `files/inventory|chunk-exec|chunk-download`.
- Benchmark: STORE vs DEFLATE pe incompresibil → **STORE** (13× mai rapid, output mai mic). D-004 închis.
- **Rerulat de mine:** excluderi 6/6 (0 scurgeri), inventar hash stabil pe 2 rulări, temp peak 315 MB ≈ cel
  mai mare chunk (nu 1.02 GB total), restore-oracle **5001/5001 (0 missing, 0 mismatch)**.

**Rămâne în P2 (bucata 3+):** upload S3 multipart întărit (part mic, retry/backoff/jitter/checksum/resume per
parte + reaper abandonate; TTL presigned scurt — D-005); agregare `manifest.json`+`metadata.json`+`checksums.json`
+ `_COMPLETE` (verify-before-complete); orchestrare FSM în Laravel (jobs capability→inventory→db_export→chunking
→uploading→upload_verifying→finalizing→completed) cu skip idempotent `chunk_size==0` + validare arhivă;
agregare reguli excludere pe niveluri (manager-side); shim connector; full/db-only/files-only end-to-end;
fallback non-InnoDB; DiskSpaceGuard înainte de dump.

### P1 — Fundația Laravel ✅ (poartă trecută)
- Namespace izolat `App\Backup\V2\*` (Enums, StateMachine, Models, Support, Console, Jobs, Legacy).
- FSM: `BackupSessionState` (17 stări) + `RestoreSessionState` (14 stări) cu tranziții legale explicite,
  `IllegalStateTransition` pe muchii ilegale, no-op idempotent la self-transition.
- Migrări aditive/reversibile `backup_sessions` + `restore_sessions` (jsonb, idempotency_key unic,
  self-ref chain). Verificat: migrate → rollback(step=2) → re-migrate curat pe Postgres.
- Modele cu casts jsonb/enum + `transitionTo()`/`heartbeat()`/`recordError()`; logging structurat pe
  canal `backup_v2`; `BackupErrorCode` (8 coduri). Provider înregistrează DOAR comanda (fără scheduler/queue).
- Comanda read-only `backup:reconcile-storage` (nu scrie fără `reconciliation_writes_enabled`) +
  `ReconcileUsedBytesJob` (inert fără flag) + `LegacyBackupReader` (v2/v3 read-only).
- **REVIEW independent (rulat de mine):** 34 teste passed (450 assert), 0 failed; Pint PASS; migrări
  reversibile. Doar cod aditiv — niciun fișier V1 modificat (excepții aditive: `config/logging.php`
  canal nou, `bootstrap/providers.php` provider V2).
- Warning cosmetic `.env` lipsă în lab (afectează testele Feature din tot repo-ul, nu codul V2).

### P0 — Setup (✅)
- Branch `feature/simplead-backup-production-ready` creat din `snapshot-parity` (docs) + merge `spike/...` (cod validat).
- `config/backup_v2.php`: feature flags izolate, toate `false` (V2 inert în producție); profile de resurse seed; contract manifest/completion.
- Directiva copiată în repo: `docs/backup/DIRECTIVE-simplead-backup.md`.
- Urmează: laborator Docker (WP/Woo/Multisite/MySQL8/MariaDB11/MinIO) + mediu PHP/Composer de dev + CI.

## Blocaje / limitări curente
- Niciun blocaj extern. Mediul PHP/Composer de dev rulează prin container efemer (host-ul nu are php).
- S3 în laborator = MinIO; Hetzner real doar la pilot (gated de owner).
