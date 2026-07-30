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

## D-011 — Orchestrare backup FULL end-to-end condusă de FSM (P2, dovedit în lab)
**Decizie:** `App\Backup\V2\Orchestration\BackupRunner` conduce o `BackupSession` prin **întreg** graful
FSM (fiecare tranziție prin `transitionTo`, fără scurtături): `capability_check` → `capabilities()`;
`inventory` → `files/inventory` (salvează `exclusion_policy_hash` + `scope_hash` + plan); `database_export`
→ `database/dump` + **pull-and-free** per segment; `uploading` → `files/chunk-exec`+`chunk-download`
(pull-and-free) per chunk; `upload_verifying` → `headObject` (size) + re-download & sha256 per obiect;
`finalizing` → `manifest.json`/`checksums.json`/`metadata.json` apoi **`_COMPLETE` scris ULTIMUL**, abia
apoi `completed`. Client HMAC `App\Backup\V2\Plugin\SimpleadBackupClient` (semnătură
`METHOD|ROUTE|TS|NONCE|BODY`, nonce obligatoriu, headere `X-SAM-Backup-*`), factory S3 lab
`App\Backup\V2\Storage\S3ClientFactory` (MinIO; prod = din `StorageDestination`, TODO), job inert
`RunBackupSessionJob` (gate pe `backup_v2.enabled`).
- **Sub-decizie D-011a — endpoint NOU `database/chunk-download` în `simplead-backup`** (v0.2.0): pluginul
  nu avea cale de a servi segmentele DB dumpuite; adăugat simetric cu `files/chunk-download` (auth HMAC,
  `delete=1` = pull-and-free, path-guard). Fără el nu există backup FULL end-to-end din manager.
- **Sub-decizie D-011b — idempotență/resume prin `confirmed_objects` (jsonb) + `checkpoint.db_done`:** un
  obiect confirmat nu se re-pull-uiește/re-urcă niciodată (pull-and-free face re-pull imposibil oricum);
  dump-ul DB se short-circuit-ează după ce toate segmentele sunt confirmate. Resume mid-fază = re-intrare
  în starea curentă (self-transition idempotent).
- **Sub-decizie D-011c — verify-before-complete:** orice obiect lipsă/nepotrivit (size sau sha256) →
  `corrupt`, NICIODATĂ `completed`; dump `done=false`/`consistent=false` → `corrupt`.
**Dovedit în lab** (`tests/Feature/Backup/V2/BackupRunnerE2ETest.php`, plugin + MinIO reale, 3 teste /
192 aserțiuni): backup FULL `completed` cu `_COMPLETE` scris ultimul, toate obiectele `database/`+`files/`
verificate size+sha256; **restore-oracle fișiere 41/41, 0 lipsă / 0 nepotriviri**; **empty-chunk skip-uit**
(nu se descarcă, nu apare în manifest); **resume după crash injectat — fiecare chunk pull-uit exact o dată,
obiectele confirmate cu ETag+mtime neschimbate (0 re-upload)**. **DB restore-oracle real**
(`spike/orchestrator/verify-db-restore.sh`): import în MySQL 8.0 nou = **0 erori, 59/59 tabele** (incl.
WooCommerce/HPOS `wp_wc_orders`). Pint curat, PHPStan 0 erori pe `app/Backup/V2`.

## D-012 — Incremental FIȘIERE + chain-uri + retenție chain-safe (P3, dovedit în lab)
**Decizie:** incrementalul este **DOAR fișiere**; **DB = full dump la FIECARE backup** (respectă D din
`TARGET-ARCHITECTURE.md` — fără „incremental DB" falsificat, universal restaurabil pe hosturi heterogene).
- **Diff plugin-side (`SAM_Backup_File_Diff`, v0.3.0):** endpoint-ul `files/inventory` acceptă `base_manifest`
  (`{p,sha256,s,m}` din manifestul bazei) și produce **changed / new / tombstones**; DOAR changed+new intră
  în plan (aceleași reguli STORE + fișier-mare=chunk-propriu + empty-chunk skip). Detectare: **fast-path
  unchanged** când size ȘI mtime identice (fără hash — de aceea manifestul poartă acum `m`); altfel confirmare
  **autoritativă cu sha256** (un `touch` fără schimbare de conținut → 0 re-upload). Neschimbatele NU se re-urcă.
- **Chain (`App\Backup\V2\Chain\ChainResolver`):** `resolveChain` ordonează `[full, inc1, …, target]` din
  `full_base_id`+`chain_position`; `materialize` aplică full apoi fiecare incremental (new/changed suprascriu,
  tombstones șterg) → starea finală exactă pentru restore/oracle; `baseFileState` = base_manifest pentru un
  incremental în curs. Chain rupt (bază lipsă/incompletă, gap/duplicat de poziție, manifest lipsă/corupt) →
  **`BrokenChainException` ÎNAINTE de orice restore**. `ManifestReader` abstract (S3 în lab/prod, in-memory în teste).
- **BackupRunner:** calea incrementală cere baza (materializată din chain), o trimite la `files/inventory`, urcă
  doar changed+new, scrie **tombstones + `full_base_id`+`chain_position`+`base_manifest_ref`** în manifest. Full
  nou = chain nou. DB = full dump ca de obicei.
- **Retenție chain-safe (`App\Backup\V2\Retention\ChainRetentionService`):** unitatea atomică = chain-ul; NU
  șterge o bază cu incrementale dependente încă în fereastră, nici ultimul full valid, nici ultimul verificat,
  nici `protected`; selectează doar chain-uri complet-expirate. **Dry-run implicit** (`backup_v2.retention_dry_run`,
  log-only ca V1); ștergerea reală cere `apply(force:true)` ȘI `backup_v2.enabled`. Câmpuri noi aditive pe
  `backup_sessions`: `protected`, `verified_at`, `expires_at` (migrare reversibilă, tabelă proprie V2 — zero impact V1).
**Dovedit în lab** (`tests/Feature/Backup/V2/`, MinIO real + clasele reale ale pluginului + spike-wp real):
`IncrementalChainE2ETest` — incremental urcă DOAR changed+new (neschimbatele NU), tombstones înregistrate,
`full_base_id`+`chain_position` corecte; **restore din full+2 incrementale = identic la bit (0 lipsă / 0
nepotriviri), tombstones aplicate (fișierele șterse NU apar)**; chain rupt detectat (bază incompletă →
`resolveChain`; manifest bază șters → `materialize`). `IncrementalHttpE2ETest` (spike-wp real, HTTP) —
incremental peste fixture NESCHIMBAT urcă **0 chunk-uri fișiere** dar **full DB dump** (DB full/backup).
`ChainResolverTest`/`ChainRetentionServiceTest` — algebra chain + selecția retenției. Plus harness CLI plugin
`tests/files-diff-harness.php` (0/0). **Pint curat, PHPStan 0 erori pe `app/Backup/V2`.** V2 inert în prod (flag-uri false).

## D-004 — Format chunk fișiere: zip-per-chunk, compresie STORE (P2, măsurat)
**Decizie:** partea de FIȘIERE se împachetează ca **un `ZipArchive` per chunk** (`files/chunk_N.zip`),
grupat pe director (localitate) sub un prag configurabil (implicit 100 MiB), cu **compresie STORE**
(nu DEFLATE) implicit, suprascriabilă per-site de manager. Un fișier mai mare decât pragul devine
**singur un chunk** (fără split intra-file în v1). Chunk gol → nu se produce (contract empty-chunk).
Implementat în `simplead-backup/includes/files/{class-exclusions,class-inventory,class-file-chunker}.php`
+ endpoint `files/inventory|chunk-exec|chunk-download`.
**De ce STORE:** payload-ul WP e dominat de media deja-comprimată (jpg/png/mp4/pdf/woff) → DEFLATE
arde CPU pentru câștig ~0 și pe date incompresibile face arhiva **mai mare**.
**Măsurători (lab, fișier incompresibil 300 MB, `tests/files-test.sh`):**
| Metodă | Timp | Output | Ratio |
|---|---|---|---|
| STORE | **0.44 s** | 314,572,900 B | 1.0000 |
| DEFLATE | 5.88 s | 314,623,491 B | 1.0002 (mai mare) |
→ STORE de ~13× mai rapid și output mai mic pe incompresibil. **STORE ales ca default.**
**Restul dovedit în lab (0/0, temp bounded):** inventar determinist (hash + count/bytes identice pe 2
rulări), 6/6 excluderi corecte (0 scurgeri în plan), fișier mare = chunk propriu (314,572,954 B ≈ dim.
fișier), **temp peak 315 MB ≈ cel mai mare chunk** (pull-and-free, NU totalul de 1.02 GB), fiecare chunk
cu sha256, restore-oracle **5001/5001, 0 lipsă / 0 nepotriviri**, ZipArchive streaming (fără buffering RAM).
Rămâne deschis DOAR agregarea la nivel de backup (manifest global + `_COMPLETE`) — bucata de upload/orchestrare.

## Decizii deschise (se închid prin benchmark în fazele următoare)
- **D-005 (P2) — ÎNCHIS:** upload S3 multipart întărit `App\Backup\V2\Storage\HardenedMultipartUploader`:
  model **pull server-side** (managerul deține creds, urcă local; WP rămâne subțire — fără creds/URL-uri lungi),
  cu `presignPartUrl()` păstrat la **TTL scurt** (`presigned_ttl_seconds`, nu 4h) pentru viitoarea cale push.
  Părți mici (**16 MiB** default, din benchmark: 16 MiB = ~93% throughput-ul de la 64 MiB dar resume 4× mai
  fin, retry blast-radius 4× mai mic), retry per-parte backoff+jitter, checksum per parte (sha256 + Content-MD5
  + ETag verificat), resume din `confirmed_parts` jsonb (same UploadId, nu reîncarcă părți confirmate), reaper
  `ListMultipartUploads` (filtrat client-side — MinIO ignoră Prefix), abort curat fără dangling. Concurrency 1
  (simplifică resume/checksum; bounded = hardening viitor). **Dovedit pe MinIO real: 13 teste, 69 aserțiuni,
  resume-fără-reîncărcare + reaper-fără-dangling + failure-injection.**
- **D-006 (P2) — ÎNCHIS:** dump DB consistent implementat în `simplead-backup` (`class-consistent-dumper.php`):
  o singură conexiune mysqli, `SET SESSION REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`,
  `ORDER BY` pe PK real, binar/BLOB ca hex `0x...`, snapshotul e DOAR mecanism de citire (textul de restore
  = DDL+INSERT, fără statement-urile de snapshot), output segmentat gzip. **Dovedit în lab: 0 orfani pe
  MySQL 8.0.46 ȘI MariaDB 11.8** (contrast paged: 60/62 orfani), CRC32 binar identic, cu Woo/HPOS + Multisite.
  Fără binlog/WAL în v1. Reluarea cross-request = repornire (snapshot nou), documentat onest.
- **D-007 (P1/P2) — Profile de resurse:** valorile finale LOW/NORMAL/FAST din capability discovery +
  benchmark de latență, nu hardcodate.
