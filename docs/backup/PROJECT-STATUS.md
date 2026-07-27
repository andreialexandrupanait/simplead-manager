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
| P2 | Plugin simplead-backup + backup de bază (nucleul) | 🟡 în lucru | scaffold plugin + capabilities + **dump DB consistent dovedit (0 orfani MySQL8+MariaDB11)** |
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

### P2 — Nucleul 🟡 (în lucru — bucata 1 din N livrată)
**Livrat & dovedit (bucata 1 — WORDPRESS-ENGINE):**
- Plugin NOU `wordpress-plugin/simplead-backup/` independent: header + `SAM_BACKUP_VERSION`, REST namespace
  propriu `simplead-backup/v1`, opțiuni proprii `sam_backup_*`, temp propriu, log + diagnostic proprii,
  auth HMAC-SHA256 + timestamp + **nonce OBLIGATORIU** + anti-replay. Connectorul NU e atins.
- Endpoint `capabilities` (PHP/WP/DB/extensii/disk/multisite/snapshot-supported; `shell_exec` doar raportat, niciodată folosit).
- **Dump DB consistent** (`class-consistent-dumper.php`): o conexiune, `START TRANSACTION WITH CONSISTENT
  SNAPSHOT`, ORDER BY pe PK, binar→hex, output segmentat gzip. **REVIEW independent (rerulat de mine):
  0 orfani pe MySQL 8.0.46 ȘI MariaDB 11.8** (contrast paged 60/62), CRC32 binar identic, Woo/HPOS + Multisite.
- Sintaxă PHP OK pe toate fișierele; connectorul neatins.

**Rămâne în P2 (bucățile următoare):** inventar fișiere + excluderi aplicate înainte de citire; chunking
fișiere + format segment (benchmark); upload S3 multipart întărit (retry/checksum/resume per parte + reaper);
`manifest.json`+`metadata.json`+`checksums.json`+`_COMPLETE` obligatoriu; contract empty-chunk; orchestrare
FSM în Laravel (jobs care conduc sesiunea capability→inventory→db_export→…→completed); shim în connector;
full/db-only/files-only end-to-end; fallback non-InnoDB; DiskSpaceGuard înainte de dump.

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
