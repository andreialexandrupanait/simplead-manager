# simplead-backup — DECISION LOG

> Fiecare decizie tehnică semnificativă, cu justificare și (unde e cazul) măsurători din laborator.
> Alegerile între opțiuni tehnice se fac prin prototip + benchmark, nu prin preferință.

## D-001 — Bază de branch unică (snapshot-parity docs + spike code)
**Decizie:** `feature/simplead-backup-production-ready` = `feature/snapshot-parity-backup-engine`
(design docs) + merge `spike/simplead-backup-architecture-validation` (cod validat + docs/backup/spike).
**De ce:** roadmap-ul și dovezile spike stăteau pe două branch-uri diferite; construcția are nevoie
de ambele la un loc. main e ancestor al snapshot-parity (merge curat, fără atingerea codului de producție).
**Măsurători:** n/a.

## D-002 — Izolare V2 prin config dedicat + flag-uri default false
**Decizie:** `config/backup_v2.php` separat de `config/backups.php`; toate flag-urile de activare = false.
**De ce:** blast radius zero pe motorul V1 live; V2 complet inert în producție cu default-urile.
**Măsurători:** n/a.

## D-003 — Laravel: țintim versiunea reală din repo (^12.0)
**Decizie:** `composer.json` are `laravel/framework: ^12.0` → construim pe Laravel 12 (nu 11 cum
zicea vechiul CLAUDE.md). Fără upgrade de framework în această muncă.
**De ce:** evităm să combinăm un upgrade de framework cu construcția motorului.

## D-008 — Namespace izolat `App\Backup\V2\*` (P1)
**Decizie:** tot codul V2 sub `App\Backup\V2\` (Enums/StateMachine/Models/Support/Console/Jobs/Legacy),
înregistrat printr-un `BackupV2ServiceProvider` care expune DOAR comanda read-only (fără scheduler/queue).
**De ce:** blast radius zero pe V1; activare/dezactivare independentă; namespace curat.

## D-009 — FSM cu graf explicit + tranziții idempotente (P1)
**Decizie:** state machine cu hartă explicită de muchii legale; `assertTransition` aruncă
`IllegalStateTransition` pe muchii ilegale; self-transition = no-op permis (pentru redelivery idempotent).
`rollback → failed` (niciodată completed): un rollback reușit = restore eșuat cu site salvat la pre-restore.
`corrupt` accesibil doar din `upload_verifying`/`finalizing`. `isActive() = !isTerminal()`.
**De ce:** callback-uri duplicate/redelivery trebuie să fie no-op; tranziții ilegale trebuie prinse tare.

## D-010 — Migrări aditive/reversibile, jsonb, self-ref chain (P1)
**Decizie:** tabele NOI `backup_sessions`/`restore_sessions`, coloane `jsonb`, `idempotency_key` unic,
FK aditive spre `sites`/`backups`, `full_base_id` self-ref pentru chain. `down()` dă drop curat.
**Măsurători:** migrate → rollback(step=2) → re-migrate curat pe PostgreSQL 16 (verificat în lab).

## Decizii deschise (se închid prin benchmark în fazele următoare)
- **D-004 (P2) — Format de segment/chunk:** object-per-file vs pack/TAR-stream vs segmente
  compresate limitate vs multipart-per-segment. Criterii: impact minim, restore eficient, număr
  rezonabil de obiecte S3, reluare per segment, checksum, streaming, temp redus, fișiere foarte mari.
- **D-005 (P2) — Dimensiune parte multipart + TTL presigned:** din benchmark de throughput/rată de eșec.
- **D-006 (P2) — ÎNCHIS:** dump DB consistent implementat în `simplead-backup` (`class-consistent-dumper.php`):
  o singură conexiune mysqli, `SET SESSION REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`,
  `ORDER BY` pe PK real, binar/BLOB ca hex `0x...`, snapshotul e DOAR mecanism de citire (textul de restore
  = DDL+INSERT, fără statement-urile de snapshot), output segmentat gzip. **Dovedit în lab: 0 orfani pe
  MySQL 8.0.46 ȘI MariaDB 11.8** (contrast paged: 60/62 orfani), CRC32 binar identic, cu Woo/HPOS + Multisite.
  Fără binlog/WAL în v1. Reluarea cross-request = repornire (snapshot nou), documentat onest.
- **D-007 (P1/P2) — Profile de resurse:** valorile finale LOW/NORMAL/FAST din capability discovery +
  benchmark de latență, nu hardcodate.
