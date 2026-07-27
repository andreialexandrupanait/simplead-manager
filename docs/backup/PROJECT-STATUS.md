# simplead-backup — PROJECT STATUS (sursă vie de adevăr)

> Actualizat la finalul fiecărei faze interne. Directiva: [`DIRECTIVE-simplead-backup.md`](DIRECTIVE-simplead-backup.md).
> Design: [`TARGET-ARCHITECTURE.md`](TARGET-ARCHITECTURE.md) · Roadmap: [`IMPLEMENTATION-ROADMAP.md`](IMPLEMENTATION-ROADMAP.md)
> Acceptanță: [`ACCEPTANCE-TESTS.md`](ACCEPTANCE-TESTS.md) · Decizii: [`DECISION-LOG.md`](DECISION-LOG.md)

**Verdict curent:** IN PROGRESS (nu READY FOR PILOT).
**Branch:** `feature/simplead-backup-production-ready` (din `feature/snapshot-parity-backup-engine` + merge spike).
**Producție:** neatinsă. V2 dezactivat implicit (toate flag-urile `config/backup_v2.php` = false).

## Stare pe faze

| Fază | Titlu | Stare | Dovezi |
|---|---|---|---|
| P0 | Setup branch / bază / laborator / flags | ✅ | branch + spike merge; `config/backup_v2.php`; lab Laravel (Laravel 12.64, migrări OK, teste rulează) |
| P1 | Fundația Laravel (FSM, migrări, observabilitate) | ✅ | 34 teste passed (450 assert); migrări reversibile; Pint PASS; `app/Backup/V2/*` |
| P2 | Plugin simplead-backup + backup de bază (nucleul) | 🟡 în lucru | scaffold + capabilities + **DB dump consistent (0 orfani)** + **inventar/excluderi/chunking fișiere (oracle 5001/5001, temp bounded)** |
| P3 | Incremental + chain-uri + retenție | ⬜ | — |
| P4 | Restore (full + selectiv + rollback) | ⬜ | — |
| P5 | UI Manager + UI plugin + alerte + cote | ⬜ | — |
| P6 | Verificare + proven restore + import legacy | ⬜ | — |
| P7 | Pregătire rollout + raport final | ⬜ | — |

Legendă: ✅ trecut poartă · 🟡 în lucru · ⬜ neînceput · ❌ blocat

## Definition of Done (30) — urmărire

Toate ⬜ până sunt demonstrate prin teste. Se completează pe măsură ce fazele trec porțile.
Vezi lista completă în [`DIRECTIVE-simplead-backup.md`](DIRECTIVE-simplead-backup.md) (secțiunea DEFINITION OF DONE).

## Jurnal de faze (cel mai recent sus)

### P2 — Nucleul 🟡 (în lucru — bucățile 1–3 livrate; rămâne orchestrarea FSM end-to-end)
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
