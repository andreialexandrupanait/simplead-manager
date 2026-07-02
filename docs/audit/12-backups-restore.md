# 12 — Backups & Restore

**Data:** 2026-07-02 · **Auditor:** Claude (audit Faza 1, modulul 12) · **Scope:** `app/Services/Backup/*`, `app/Services/AppBackup/*`, `RollbackService.php`, `WordPressBackupDownloader.php`, job-urile CreateBackup / CreateIncrementalBackup / RestoreBackup / ReplicateBackup / PrecacheBackupFileList / NotifyBackupFailed / CreateAppBackup / ExportBackupForLocal (WIP necomis), UI Livewire (BackupsOverview, SiteBackups, RestoreConfirmation, WithBackupActions), controllere (BackupDownloadController, BackupLocalExportDownloadController, AppBackupDownloadController, Api/BackupCallbackController, Api/BackupRelayController), comenzi (VerifyBackupRestoreCommand, CleanupBackupTemp, BackupReleaseLock, ReindexBackupsFromStorageCommand, DatabaseDumpCommand, AppBackup*), plus partea de restore din pluginul WP (`class-backup-endpoint.php`). Include tot working tree-ul necomis (feature-ul „export pentru Local by Flywheel").

---

## Rezumat executiv

1. **Nu există niciun locking la nivel de site pe restore.** `RestoreBackup` e unic doar per **backup** (`uniqueId = 'restore-'.backup->id`, `app/Jobs/RestoreBackup.php:51-54`), iar UI-ul (`RestoreConfirmation::dispatchRestore()`, `app/Livewire/Sites/Detail/Components/RestoreConfirmation.php:265-292`) nu verifică dacă alt restore sau backup e deja în curs pe același site. Două restore-uri din backup-uri diferite (sau un restore + un backup programat) pot rula simultan pe același site live → fișiere/DB interleave → site corupt. **P0.**
2. **Restore-ul este non-atomic și fără rollback.** Pe WP fișierele se extrag direct peste `ABSPATH` (`class-backup-endpoint.php:2477`), SQL-ul se importă statement-cu-statement fără tranzacție și tolerează până la 10% erori (`class-backup-endpoint.php:2484-2532`), iar „safety backup"-ul pre-restore este **doar de bază de date** (`RestoreConfirmation.php:244-251`) chiar și când se restaurează fișiere. Un restore care moare la mijloc lasă site-ul clientului stricat, fără cale automată de revenire. **P0.**
3. **Authz aproape absent pe acțiunile distructive din UI.** `BackupsOverview::deleteBackup()/bulkDelete()` nu au nicio verificare de autorizare (`app/Livewire/Backups/BackupsOverview.php:299-348`); `RestoreConfirmation::openModal()` încarcă orice backup după ID, ne-scopat la site și fără verificare de rol (`RestoreConfirmation.php:56-59`); un utilizator **Viewer** poate lansa restore, șterge backup-uri, porni backup-uri. `BackupPolicy` există dar nu e folosită nicăieri. **P1.**
4. **Backup-urile se pot opri silențios.** `DiskSpaceGuard` blochează dispatch-ul tuturor backup-urilor programate doar cu un `Log::warning` (`DiskSpaceGuard.php:42-58`), fără notificare; un `RestoreBackup` ucis de timeout/SIGKILL rămâne blocat pe `in_progress` pentru totdeauna (nu are `failed()` și nici `uniqueFor`) și **nu mai poate fi reluat niciodată** (lock-ul unic nu expiră; `backup:release-lock` nu acoperă lock-urile de restore — `BackupReleaseLock.php:35-36`). **P1.**
5. **Retenția pe zile șterge lanțuri incrementale încă valabile** (decide după vârsta full-ului, nu a celui mai nou membru — `RetentionService.php:36-77` + `cleanupOrphans()` la 271-283), iar `BackupsOverview::deleteBackup()` permite ștergerea unui full cu incrementale (fără guard-ul din trait) → incrementalele devin orfane și sunt șterse automat la următorul ciclu. **P1.**
6. **WIP-ul de export „Local by Flywheel" nu validează formatul sursă**: pentru backup-uri `v2-zip`/`direct-s3`/incrementale produce un zip „completed" care conține **doar baza de date** (intrările `files_chunk_*.zip`/`files.zip` sunt sărite cu un simplu warning — `LocalFlywheelRepackager.php:91-110`), deci un export aparent reușit importă un site gol în Local. **P1.**
7. Partea bună: pipeline-ul de **creare** a backup-urilor este matur — verificare de integritate Level A la fiecare backup (SHA256 + CHECKCONS + parser SQL), verificare Level B săptămânală prin re-descărcare, replicare 3-2-1, auto-retry pentru backup-uri blocate, alertare pe eșec (`NotifyBackupFailed`), sidecar metadata + comandă de reindexare pentru disaster recovery. Restul raportului arată că partea de **restore** nu e la același standard.

---

## Inventar & corectitudine

### Ce face modulul (verificat în cod)

- **Formate de backup**: `v2-zip` (legacy, citire), `multipart-v3` (legacy, citire; scriere eliminată), `v3-zip` (calea unică de scriere actuală — `CreateBackup.php:126-129`), `direct-s3` (flux push: arhiva se construiește pe WP și se urcă direct în S3 prin URL-uri presigned — `CreateBackup.php:663-840`).
- **Ținte de stocare**: local, Dropbox (OAuth refresh-token), S3/B2/Hetzner Object Storage (`StorageFactory.php:14-19`). Nu există criptare client-side; nu se cere SSE la S3 (`S3Driver.php:65-69`).
- **Programare**: `BackupDispatcher` rulează la fiecare minut (`routes/console.php:31-35`), cu stagger de 180s între site-uri, auto-retry (max 2) pentru backup-uri blocate (`BackupDispatcher.php:170-259`), circuit breaker per site și guard de spațiu pe disc.
- **Retenție**: chain-aware (full + incrementale ca unitate), pe număr sau pe zile (`RetentionService.php:21-88`), aplicată sincron după fiecare backup reușit.
- **Verificare**: Level A la creare (`IntegrityVerifier::verifyV3Zip` — SHA256, CHECKCONS, parser structural pe dump-ul SQL, prezența `files/*`); Level B săptămânal (`backup:verify-restore --count=3`, duminică 03:00 — `routes/console.php:137-141`) care re-descarcă din storage și re-verifică; alertă critică dacă ≥1/3 din eșantion pică (`VerifyBackupRestoreCommand.php:124-131`).
- **Restore**: descărcare cu failover pe replici, verificare checksum, extract, re-împachetare `files.zip`, trimitere către WP prin URL temporar `/restore-download/{token}`, restore fișiere → push plugin → restore DB → `PostRestoreVerifier` (clear cache, fix Elementor, diagnostic), restore selectiv de fișiere, restore de lanț incremental.
- **App backup** (aplicația manager): dump Postgres + `.env` criptat + storage, retenție proprie, restore DB prin `psql`, comenzi + UI admin (`Settings\ApplicationBackup`).
- **WIP necomis**: export „Local by Flywheel" — job `ExportBackupForLocal` + `LocalFlywheelRepackager` (re-împachetează v3-zip în layoutul `app/public/` + `app/sql/local.sql`), coloane noi `local_export_*` (migrația `2026_06_08_120000`), butoane în `site-backups.blade.php`, rută signed `backups.download-local`. Modificarea din `S3Driver.php` (retry adaptiv + resume la multipart) este solidă.

### Cod mort / pe jumătate

| Element | Dovadă | Stare |
|---|---|---|
| `Api/BackupRelayController` | nicio rută nu îl înregistrează (`routes/api.php:9-11` are doar `/backup/callback`; grep pe `routes/` nu găsește relay) | **mort** |
| `Services/Backup/StreamingBackupUploader` | folosit doar de propriul test (`tests/Unit/Services/Backup/StreamingBackupUploaderTest.php`); calea de scriere multipart-v3 a fost eliminată | **mort** |
| `CreateBackup::createArchive()/verifyIntegrity()/finalize()` | `handle()` folosește exclusiv `runDirectUploadPipeline()`/`runV3ZipPipeline()` (`CreateBackup.php:109-130`); `createArchive` (373-439) nu mai e apelat — și e singurul loc care dispatch-uia `PrecacheBackupFileList` (linia 436) | **mort** (cu efect secundar: precache-ul nu mai rulează — vezi B-P1-7) |
| `Policies/BackupPolicy` | definește `view/create/delete/restore` cu reguli sănătoase (delete = admin, restore ≠ viewer) dar **nu e apelată nicăieri** (grep: zero `authorize(..., $backup)`) | **mort** — regulile intenționate nu se aplică |
| `RollbackService` | e rollback de **versiuni plugin/temă** (RollbackPoint), nu rollback de restore; funcțional, dar numele induce în eroare în contextul modulului | viu, alt scop |
| `BackupSidecarMetadata::buildForV2Zip()` refolosit pentru v3-zip/direct-s3 cu override manual de `format` (`CreateBackup.php:576-578`, 1012-1014) | smell minor | viu |

Nu am găsit TODO-uri/FIXME în fișierele modulului.

---

## Siguranța operațiilor distructive

### Restore (operația cea mai periculoasă din toată aplicația)

- **Confirmare**: modal cu un singur checkbox „confirm" (`restore-confirmation.blade.php:180`) — fără type-to-confirm cu numele site-ului, fără rezumat diff („backup din 12 mai, site-ul are 240 de articole noi de atunci").
- **Dry-run**: nu există.
- **Locking / concurență**: **absent la nivel de site** — vezi B-P0-1. `ShouldBeUnique` pe `restore-{backupId}` previne doar dublul restore al *aceluiași* backup. În plus, `BackupDispatcher` verifică doar backup-uri `pending/in_progress` înainte să programeze un backup (`BackupDispatcher.php:45-47`) — un restore în curs nu setează nimic pe care dispatcher-ul să-l vadă, deci un backup programat poate porni în mijlocul unui restore și poate arhiva un site pe jumătate restaurat.
- **Idempotență / atomicitate**: restore-ul NU e idempotent și NU e atomic. Pe WP: extract direct peste `ABSPATH` (`class-backup-endpoint.php:2477`), import SQL fără tranzacție care aruncă doar la eșec de DDL sau >10% erori DML — și abia **după** ce a rulat tot (`class-backup-endpoint.php:2484-2532`). `set_time_limit(600)` pe endpoint (`:1616`) vs. timeout de 1200s pe cererea managerului (`RestoreBackup.php:788-791`) — pe site-uri mari extract-ul poate fi întrerupt la mijloc.
- **Rollback**: inexistent. Backup-ul de siguranță pre-restore este `type => 'database'`, `includes_files => false` (`RestoreConfirmation.php:244-251`), deci la un full-restore eșuat **fișierele vechi sunt pierdute definitiv**. Butonul „restore anyway" (`RestoreConfirmation.php:203-210`) permite skip complet, iar `restore()` continuă și când safety backup-ul are status `failed` (`RestoreConfirmation.php:196-200`).
- **Ordinea operațiilor** e gândită corect (fișiere → push plugin → DB → post-verify — `RestoreBackup.php:619-654`), iar `PostRestoreVerifier` face o treabă reală (clear cache, fix Elementor, reactivare, diagnostic loopback — `PostRestoreVerifier.php:17-129`), dar toate best-effort.
- **Restore selectiv, calea tar**: `createSelectiveArchive()` ignoră complet exit code-ul lui `tar` (`RestoreBackup.php:745-751` — pipe-urile se închid imediat, `proc_close` nedeverificat) → o extracție eșuată produce o arhivă selectivă goală/parțială care se restaurează „cu succes".

### Ștergeri

- `WithBackupActions::deleteBackup()` (trait, folosit de SiteBackups): guard pe `is_locked` și pe incrementale, dar șterge **doar fișierul primar** — nu replicile secundare, nu sidecar-ul, nu manifestul, nu exportul Local; pentru `multipart-v3` (`file_path` = prefix) `delete()` eșuează silențios și rândul DB dispare oricum (`WithBackupActions.php:198-208`).
- `BackupsOverview::deleteBackup()/bulkDelete()`: fără autorizare, fără guard pe incrementale (`BackupsOverview.php:299-348`) — vezi B-P1-2/B-P1-5.
- `RetentionService::deleteBackup()` este, prin contrast, implementarea corectă (șterge toate replicile, sidecar, manifest, păstrează rândul la eșec parțial — `RetentionService.php:96-173`). UI-ul ar trebui să o refolosească.

### Audit logging (cine-a-făcut-ce-pe-ce-site)

- **Nimic pentru restore**: niciun apel `ActivityLogger` în `RestoreBackup`, `RestoreConfirmation`, și niciun user id persistat. Singurul audit e pe WP (`SAM_Audit_Logger`, `class-backup-endpoint.php:1698-1728`) — adică exact pe mașina care poate fi compromisă/restaurată.
- **Nimic pentru ștergerea backup-urilor** și pentru exportul Local. `ActivityLogger::backupCompleted/backupFailed` acoperă doar crearea.

### App restore (baza de date a managerului)

`AppBackupRestorer::restoreDatabase()` rulează `psql` direct pe DB-ul live (`AppBackupRestorer.php:49-71`), fără maintenance mode, fără oprirea Horizon (job-urile care rulează în timpul importului scriu într-o schemă în curs de suprascriere). Ruta e admin-only (`routes/web.php:192,213`) și modalul cere confirmare (`ApplicationBackup.php:277-293`). Verificarea post-restore pe row counts e un plus real (`AppBackupRestorer.php:117-168`).

---

## Securitate

### Entry points și authz

| Entry point | Auth | Verdict |
|---|---|---|
| `GET /sites/{site}/backups`, `GET /backups` | `auth` + `verified`; `SiteBackups::mount()` → `authorizeSiteAccess` (`SiteBackups.php:31`) | OK pentru **citire** |
| Acțiuni Livewire `backupDatabase/backupFull/backupIncremental/toggleLock/deleteBackup/bulkDelete/updateNotes/exportBackupForLocal/cancelBackup` (trait `WithBackupActions`) | doar autentificare; **zero verificare de rol** — un Viewer le poate apela pe toate (trait-ul nu apelează niciodată `authorizeSiteModification`, care există în `WithSiteAuthorization.php:25-38`) | **DEFECT** (B-P1-2) |
| `RestoreConfirmation::openModal(int $backupId)` | `Backup::with(...)->findOrFail($backupId)` — **ne-scopat la site, fără verificare de rol** (`RestoreConfirmation.php:56-59`); `restore()/restoreAnyway()/dispatchRestore()` la fel | **DEFECT** — un Manager cu acces la un singur site (sau un Viewer) poate restaura orice backup al oricărui site (B-P1-2) |
| `BackupsOverview::deleteBackup/bulkDelete` | **niciun** apel de autorizare (`BackupsOverview.php:299-348`); prin contrast `cancelBackup`/`backupStaleSite` din același fișier apelează `authorizeSiteModification` (161, 193) | **DEFECT** (B-P1-2) |
| `GET /backups/{backup}/download` | `signed` + throttle + `authorize('view', $site)` (`BackupDownloadController.php:22`, `routes/web.php:129`) | OK |
| `GET /backups/{backup}/download-local` (WIP) | idem + path-traversal guard cu `realpath` (`BackupLocalExportDownloadController.php:34-41`) | OK |
| `GET /settings/app-backups/{appBackup}/download` | `signed` + grup `role:admin` (`routes/web.php:227`) | OK |
| `GET /restore-download/{token}` (fără auth) | token hex de 64 caractere = 256 biți entropie (`RestoreBackup.php:781`), regex strict + throttle 10/min (`routes/web.php:40-51`) | acceptabil, dar: **nu e single-use, nu are TTL propriu** — fișierul trăiește cât durează cererea către WP (până la 1200s) și **rămâne pe disc pentru totdeauna dacă workerul e ucis** (`finally @unlink` nu rulează la SIGKILL; `CleanupBackupTemp` curăță doar prefixele `backup-*` și `php*` — `CleanupBackupTemp.php:43,67`) (B-P2-4) |
| `POST /api/backup/callback` (fără auth clasică) | HMAC `hash_hmac('sha256', id.'|'.created_at, APP_KEY)` (`BackupCallbackController.php:54-57`), `hash_equals` | OK ca design, dar tokenul e **static pe viața backup-ului** (nu expiră, nu e revocabil); impact limitat la update de progres |
| `Api/BackupRelayController` | fără rută = nefolosit; dacă ar fi montat, ar accepta scriere de chunk-uri arbitrare cu același token static | de șters (B-P2-6) |

### Alte aspecte

- **Mass assignment**: `Backup::$fillable` include câmpuri de integritate (`status`, `checksum`, `file_path`, `verification_status` — `Backup.php:78-130`). Nu am găsit binding Livewire direct pe model, deci risc practic mic; de notat doar.
- **SSRF**: singurele URL-uri fetch-uite sunt construite server-side (`config('app.url')` + token; URL-uri presigned generate de manager). WP-ul primește `download_url` de la manager prin canal HMAC. Nicio intrare de utilizator nu ajunge în URL-uri. OK.
- **Zip handling**: pluginul WP folosește `safe_extract_zip()` cu respingere `..` și verificare `realpath` per director (`class-backup-endpoint.php:2644-2669`) — protecție zip-slip reală. Pe partea de manager, arhivele extrase provin din propriul pipeline.
- **Secrete în loguri**: nu am găsit chei/tokene logate; `S3Driver`/`DropboxDriver` loghează doar căi și mesaje de eroare; credențialele sunt criptate în `StorageDestination.config` și decriptate la construcție (`S3Driver.php:29-30`, `DropboxDriver.php:397-429`).
- **Criptare at rest**: **inexistentă** pentru backup-urile site-urilor (dump-uri SQL cu date personale ale clienților, în clar, pe S3/Dropbox/local). Singura criptare este `.env`-ul din app backup — criptat însă cu `APP_KEY` (`AppBackupCreator.php:319`), adică exact cheia care se pierde odată cu serverul → circulară pentru DR (B-P2-9).

---

## Igienă queue/job

| Job | Queue | tries / backoff / timeout | unique | failed() | Verdict |
|---|---|---|---|---|---|
| `CreateBackup` | backups | 2 / [120] / 2700 | `backup-{siteId}`, `uniqueFor=2700` | da (trait, `BackupJobTrait.php:143-171`) | bun; dedup suplimentar aplicativ în `prepare()` (`CreateBackup.php:152-190`) |
| `CreateIncrementalBackup` | backups | 2 / [120] / 2700 | `backup-{siteId}` (partajat cu CreateBackup — corect) | da | bun |
| `RestoreBackup` | backups | 1 / — / 3600 | `restore-{backupId}`, **fără `uniqueFor`** | **NU** | **DEFECT**: la timeout/kill → `restore_status` blocat `in_progress` + lock unic permanent; `backup:release-lock` nu-l acoperă (`BackupReleaseLock.php:35-36`) (B-P1-3) |
| `ReplicateBackup` | backups | 3 / [60,300,900] / 1800 | per (backup,dest), `uniqueFor=1800`, idempotent sub `lockForUpdate` (`ReplicateBackup.php:139-159`) | implicit (doar log la eșec final — replica lipsă e vizibilă doar în `replicas[]`) | bun; lipsă alertare la eșec definitiv |
| `ExportBackupForLocal` (WIP) | **default** (nu backups!) | 2 / — / 1800 | `local-export-{backupId}`, `uniqueFor=1800` | da (`ExportBackupForLocal.php:137-143`) | OK, dar fără `DiskSpaceGuard` — descarcă + reconstruiește arhive de zeci de GB pe discul aplicației (B-P2-11) |
| `PrecacheBackupFileList` | default | 1 / — / 120 | — | — | orfan: dispatch-ul lui e în cod mort (vezi B-P1-7) |
| `NotifyBackupFailed` | notifications | 3 / [30,60,120] / 30 | — | — | bun |
| `CreateAppBackup` | backups | 1 / — / 1800 | — | da + notificare critică (`CreateAppBackup.php:46-64`) | bun |

**Dacă coada `backups` e blocată** (3 workeri prod, `config/horizon.php:233-244,294-296`): restore-ul — operațiunea de urgență prin definiție — stă la coadă în spatele backup-urilor programate, replicărilor și exporturilor Local. Nu există coadă prioritară pentru restore (B-P2-10). Backup-urile `pending` care nu pornesc în 45 min sunt recuperate de `recoverStuckBackups()` (`BackupDispatcher.php:181-185`) — bun. Pentru restore nu există niciun mecanism echivalent.

---

## Error handling & observabilitate

**Vizibil / alertat corect:**
- Eșec backup (inclusiv stuck-recovery epuizat) → `NotifyBackupFailed` cu severitate critical (`BackupJobTrait.php:76-78`, `BackupDispatcher.php:293`).
- Eșec verificare Level B ≥1/3 din eșantion → notificare critical (`VerifyBackupRestoreCommand.php:124-131`).
- Eșec app backup → notificare critical (`CreateAppBackup.php:46-64`).
- Health score de backup per site + agregat pe dashboard (`BackupHealthService`), listă „stale sites" >36h (`BackupsOverview.php:113-125`).

**Silențios / doar în log (periculos):**
- **Disc plin → toate backup-urile programate se opresc** cu un singur `Log::warning` pe oră (`DiskSpaceGuard.php:42-58`, apelat din `BackupDispatcher.php:30-32` și `ScheduledAppBackupCommand.php:20`). Niciun canal de notificare. Singurul semnal secundar este degradarea health score-ului, la >36h. (B-P1-4)
- **Eșecul unui restore nu notifică pe nimeni** — doar `restore_status=failed` pe rând + job failed în Horizon (`RestoreBackup.php:81-97`). Dacă operatorul închide pagina după dispatch, un site pe jumătate restaurat rămâne nedetectat. (B-P2-1)
- Site-uri excluse din dispatch (deconectate, monitoring dezactivat — `BackupDispatcher.php:37-43`) nu generează niciodată „backup overdue"; `next_backup_at` rămâne în trecut, silențios.
- `deleteBackup` din UI înghite orice excepție de storage și șterge rândul (`WithBackupActions.php:204-206`) → fișiere orfane necontabilizate.
- Level B folosește `verifyArchive()` pentru v3-zip (`VerifyBackupRestoreCommand.php:68-93`) în loc de `verifyV3Zip()`: pentru v3-zip nu există `chunk_files` în meta și nici `files.zip`, deci verificarea fișierelor e sărită ca „db-only backup" (`IntegrityVerifier.php:154-157`) — un v3-zip full fără niciun `files/*` ar trece Level B. (B-P2-8)
- `backup:verify-restore` — numele promite un test de restore; în realitate e doar verificare de integritate prin re-descărcare. **Nu există niciun test de restore real (nici măcar periodic, într-un sandbox).** (B-P2-7)

---

## Teste

**Există azi** (toate verificate în `tests/`):
- `tests/Unit/Services/Backup/IntegrityVerifierTest.php` (160 linii), `SqlDumpParserTest.php` (111), `PostRestoreVerifierTest.php` (172), `StreamingBackupUploaderTest.php` (151 — testează cod mort);
- `tests/Feature/Services/RetentionServiceTest.php` (184);
- `tests/Unit/Services/WordPressBackupDownloaderTest.php`, `AppBackupCreatorTest.php`, `AppBackupHelpersTest.php`, `AppBackup/AppBackupRestorerTest.php`.

**Nu există**: niciun test pentru `CreateBackup`, `CreateIncrementalBackup`, `RestoreBackup`, `ReplicateBackup`, `BackupDispatcher`, `ExportBackupForLocal`/`LocalFlywheelRepackager` (WIP), nicio componentă Livewire de backup, niciun test de rută/authz.

**Setul minim viabil (cele mai periculoase regresii):**
1. **Concurență restore**: dispatch `RestoreBackup` pentru backup A al site-ului X în timp ce backup B al aceluiași site are `restore_status=in_progress` → al doilea trebuie refuzat (azi pică — testul codifică fix-ul B-P0-1).
2. **Authz**: user cu rol `viewer` apelează `deleteBackup`, `restore()`, `backupFull` → 403 / refuz (azi pică — B-P1-2).
3. **Scoping**: `RestoreConfirmation::openModal($backupId)` cu backup al unui site la care userul nu are acces → refuz (azi pică).
4. **Retenție chain**: config `days=30`, full de 40 zile cu incremental de 5 zile → lanțul NU se șterge (azi pică — B-P1-5).
5. **Chain integrity la delete**: `BackupsOverview::deleteBackup` pe un full cu incrementale → refuz (azi pică).
6. **Export Local pe format greșit**: `ExportBackupForLocal` pe backup `v2-zip`/`direct-s3`/incremental → `local_export_status=failed` cu mesaj clar, nu „completed" cu zip fără fișiere (azi pică — B-P1-6).
7. **Restore selectiv pe v3-zip**: `BackupBrowserService::listContents()` pe un v3-zip cu `files/wp-content/...` → `has_files=true` + listă nevidă (azi pică — B-P1-7).

---

## Model de date

- **Indexuri**: bune pentru query-urile fierbinți — `backups(site_id, created_at)`, `(site_id, status)`, `(site_id, restore_status)`, `(status, created_at)`, `(verification_status, verified_at)`, `expires_at` (`database/schema/pgsql-schema.sql:6440-6482`); WIP-ul adaugă `(site_id, local_export_status)` (migrația `2026_06_08_120000`, cu `IF NOT EXISTS` + index — corect scrisă).
- **N+1**: tratat conștient — subselect `_previous_file_size` în `SiteBackups::getBackupHistoryProperty()` (`SiteBackups.php:137-146`), batch loading în `BackupsOverview::siteHealthScores()` (`BackupsOverview.php:30-84`). Notă: `BackupsOverview::backupHealth()` apelează `BackupHealthService::aggregate()` care face `scoreForSite()` per site = 2-3 query-uri × N site-uri la fiecare render (`BackupHealthService.php:155-183`) — P3 la zeci de site-uri.
- **FK / orfane**: `parent_backup_id` cu `ON DELETE SET NULL` (`pgsql-schema.sql:7482`) + `cleanupOrphans()` — coerent, dar transformă orice ștergere greșită a unui full în ștergerea în cascadă a incrementalelor (vezi B-P1-5). `site_id` — ștergerea site-ului lasă comportamentul pe seama FK-ului global (neverificat aici, în afara scope-ului).
- **Soft-delete**: `backups` nu are soft-delete deloc — ștergerea e definitivă și fără audit trail; `sites` are (`whereNull('deleted_at')` în dispatcher).
- **Drift contabil**: `used_bytes` pe `StorageDestination` se incrementează/decrementează manual în 6 locuri diferite; exportul Local nu îl incrementează, ștergerile parțiale/multipart îl pot dubla-decrementa doar prin grija `RetentionService`; UI delete decrementează chiar dacă `delete()` a eșuat silențios. Sursă sigură de drift (B-P3-2).
- **Orfane de storage**: la ștergerea din UI rămân pe storage: replici secundare, sidecar `.meta.json`, manifest, export Local (`local_export_file_path` nu e șters nicăieri, nici măcar în `RetentionService`). (B-P2-2)

---

## Constatări

| ID | Sev. | Fișier:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| B-P0-1 | **P0** | `app/Jobs/RestoreBackup.php:51-54`; `app/Livewire/Sites/Detail/Components/RestoreConfirmation.php:265-292`; `app/Dispatchers/BackupDispatcher.php:45-47` | Niciun lock la nivel de **site** pentru restore; unicitatea e per backup. UI nu verifică restore/backup în curs; dispatcher-ul nu știe de restore-uri. | Operatorul restaurează backup A, nu are răbdare, dă restore și pe backup B (sau pornește backup-ul programat în mijlocul restore-ului). Două job-uri scriu simultan fișiere+DB pe site-ul live → site corupt ireversibil (safety backup doar DB). | `uniqueId = 'restore-site-'.$site->id` + guard aplicativ în `dispatchRestore()` (refuz dacă există `restore_status IN (pending,in_progress)` pe site) + `BackupDispatcher` să sară site-urile cu restore activ. |
| B-P0-2 | **P0** | `RestoreConfirmation.php:244-251,196-210`; `wordpress-plugin/.../class-backup-endpoint.php:1616,2477,2484-2532`; `app/Jobs/RestoreBackup.php:619-654` | Restore non-atomic (extract peste ABSPATH, SQL fără tranzacție, toleranță 10% erori) + safety backup pre-restore **doar DB** + „restore anyway" + continuare când safety backup-ul a eșuat → un restore eșuat la mijloc lasă site-ul stricat fără cale de revenire pentru fișiere. | Site de 8GB: `set_time_limit(600)` pe WP expiră în timpul extract-ului; jumătate din `wp-content` e suprascris, DB-ul vechi cu fișiere noi → white screen. Fișierele originale nu mai există nicăieri. | Safety backup pre-restore = **full** (sau măcar full când `restoreFiles=true`); pe WP: extract în staging dir + swap; blocarea „restore anyway" fără safety backup reușit sau typed-confirmation explicită de risc. |
| B-P1-1 | P1 | `app/Jobs/RestoreBackup.php` (fără `failed()`, fără `uniqueFor`); `app/Console/Commands/BackupReleaseLock.php:35-36`; `app/Livewire/Sites/Detail/SiteBackups.php:46-52` | Worker ucis (deploy, OOM, timeout 3600) în timpul unui restore → `restore_status` rămâne `in_progress` pe viață, lock-ul unic nu expiră niciodată, iar acel backup nu mai poate fi restaurat. Nicio comandă de deblocare (release-lock acoperă doar create-backup). | Deploy cu `horizon:terminate` în timpul unui restore lung → UI arată permanent „restoring", retry imposibil; în criză, singura opțiune e intervenție manuală în Redis+DB. | Adaugă `failed()` (marchează `restore_status=failed` + notificare) + `uniqueFor=3600` + extinde `backup:release-lock` la lock-urile de restore; detecție „stuck restore" în dispatcher (heartbeat, ca la backup). |
| B-P1-2 | P1 | `app/Livewire/Backups/BackupsOverview.php:299-348`; `RestoreConfirmation.php:56-59,183-210`; `app/Livewire/Traits/WithBackupActions.php` (toate metodele); `app/Policies/BackupPolicy.php` (nefolosită) | Authz absent pe acțiunile distructive: Viewer poate șterge/porni/restaura; `openModal(backupId)` ne-scopat la site permite oricărui user autentificat să restaureze orice backup al oricărui site (inclusiv site-uri la care nu are acces). `BackupPolicy` cu regulile corecte există dar nu e apelată. | Un cont Viewer (sau Manager al clientului X) trimite `open-restore-confirmation` cu ID-ul unui backup al clientului Y și îi restaurează site-ul la o stare veche; sau șterge în masă backup-urile tuturor. | Apelează `BackupPolicy` (`$this->authorize('restore', $backup)` etc.) în toate acțiunile Livewire; scopează `openModal` la `$this->site->backups()`. |
| B-P1-3 | P1 | `app/Services/Backup/DiskSpaceGuard.php:42-58`; `app/Dispatchers/BackupDispatcher.php:30-32` | Sub 10GB liber, dispatcher-ul se oprește complet, cu alertă doar în `laravel.log`. Backup-urile programate se opresc **silențios** pentru toate site-urile. | Temp-urile orfane de restore (vezi B-P2-4) umplu discul; zile întregi fără niciun backup nou; se observă abia când e nevoie de un restore. | `alertOnce()` să treacă prin `NotificationService::notifyAppEvent(severity: critical)` (infrastructura există deja). |
| B-P1-4 | P1 | `app/Services/Backup/RetentionService.php:36-39,73-77,271-283` | Retenția pe zile decide după vârsta **full-ului**, nu a celui mai nou membru al lanțului: full mai vechi decât cutoff-ul cu incrementale recente → full-ul se șterge, incrementalele devin orfane (FK SET NULL) și `cleanupOrphans()` le șterge și pe ele. | Config „păstrează 30 zile", full la zi 0, incrementale zilnice: la ziua 31 se șterge tot lanțul, inclusiv incrementalul de ieri → puncte de restaurare din fereastra promisă pierdute. | Candidatura lanțului la ștergere = `max(created_at)` al lanțului < cutoff (necesită încărcarea lanțurilor complete, nu doar a rândurilor pre-filtrate). |
| B-P1-5 | P1 | `app/Livewire/Backups/BackupsOverview.php:299-321,323-348` (comparativ cu guard-ul din `WithBackupActions.php:192-196`) | `deleteBackup`/`bulkDelete` din overview nu verifică `incrementals()->exists()` → un full cu incrementale poate fi șters; lanțul devine orfan și e curățat automat (aceeași cascadă ca B-P1-4). | Operator curăță backup-uri vechi din pagina globală, șterge full-ul de săptămâna trecută → toate incrementalele săptămânii devin de nerestaurat și sunt șterse la următoarea retenție. | Refolosește guard-ul din trait (ideal: mută ștergerea în `RetentionService::deleteBackup()` + policy). |
| B-P1-6 | P1 (WIP) | `app/Jobs/ExportBackupForLocal.php:54-61` (doar check de status); `app/Services/Backup/LocalFlywheelRepackager.php:91-110`; `app/Livewire/Traits/WithBackupActions.php:287-312` | Exportul Local presupune format `v3-zip` dar nu îl validează. Pentru `v2-zip`/`direct-s3`: intrările `files_chunk_*.zip`/`files.zip` sunt sărite cu warning → export `completed` doar cu `app/sql/local.sql`. Pentru incremental: exportă doar fișierele modificate. Pentru `multipart-v3`: `download(prefix)` eșuează (măcar vizibil). | Colegul exportă un backup `direct-s3`, importă în Local: site fără nicio temă/plugin/upload; dacă folosea exportul ca „backup de siguranță înainte de un experiment", datele lipsesc. | Guard la enqueue + în job: `format === 'v3-zip' && type !== 'incremental'`, altfel `local_export_status=failed` cu mesaj explicit; în repackager, aruncă pe intrări neașteptate în loc de warning. |
| B-P1-7 | P1 | `app/Services/Backup/BackupBrowserService.php:55-56` (doar `files.zip`); `app/Jobs/CreateBackup.php:436` (precache doar în `createArchive()` mort) | Browserul de conținut nu cunoaște layoutul v3-zip (`files/...`), iar precache-ul nu mai rulează pentru v3-zip → pentru toate backup-urile noi restore-ul selectiv raportează `has_files=false` (cache-uit 30 zile) și e efectiv nefuncțional, silențios. | În criză, operatorul vrea să restaureze doar `wp-config.php`/un plugin dintr-un backup de ieri (v3-zip): modalul arată „no files"; e forțat să facă full restore (mult mai riscant — vezi B-P0-2). | Învață `listContents()` layoutul v3 (`files/` prefix, strip la afișare) + dispatch `PrecacheBackupFileList` din `runV3ZipPipeline()`. |
| B-P1-8 | P1 | `app/Jobs/RestoreBackup.php` (zero ActivityLogger); `RestoreConfirmation.php:265-292`; `BackupsOverview.php:299-348` | Niciun audit trail pe manager pentru restore (start/succes/eșec) și pentru ștergeri de backup: nu se persistă cine, când, ce backup, pe ce site. | „Cine a restaurat site-ul clientului X marți și de ce arată ca acum 3 săptămâni?" — fără răspuns; incident de tip insider imposibil de reconstituit. | `ActivityLogger::backupRestored/backupDeleted(site, user, backup)` la dispatch și în `doRestore()`/`failed()`; include user_id în payload-ul jobului. |
| B-P2-1 | P2 | `app/Jobs/RestoreBackup.php:81-97` | Eșecul restore-ului nu trimite nicio notificare (spre deosebire de backup). | Restore dispatch-uit, operatorul pleacă; eșec la minutul 20; nimeni nu află până nu se plânge clientul. | `NotifyRestoreFailed` simetric cu `NotifyBackupFailed`, severitate critical. |
| B-P2-2 | P2 | `WithBackupActions.php:198-208`; `BackupsOverview.php:309-318`; `RetentionService` (nu șterge `local_export_file_path`) | Ștergerea din UI lasă orfane: replici secundare, sidecar, manifest, export Local; pentru multipart, `delete(prefix)` eșuează silențios; rândul DB dispare oricum. | Costuri S3/Dropbox cresc constant; `used_bytes` fals; reindexarea găsește „backup-uri" fantomă. | UI să delege la `RetentionService::deleteBackup()` + adaugă ștergerea exportului Local acolo. |
| B-P2-3 | P2 | `RestoreConfirmation.php:196-200,212-229` | `restore()` consideră safety backup-ul `failed` ca stare acceptabilă de continuare (singurul blocaj e pe `in_progress`). | Safety backup-ul pică (disc plin), operatorul mai apasă o dată „Restore" → restore fără nicio plasă de siguranță, fără avertisment diferențiat. | La `failed`, cere explicit „restore anyway" cu avertisment, nu continua din butonul normal. |
| B-P2-4 | P2 | `app/Console/Commands/CleanupBackupTemp.php:43,67`; `RestoreBackup.php:60,782` | Cleanup-ul de temp acoperă doar `backup-*` și `php*`; directoarele `restore-*`, fișierele `restore-{token}`, `local-export-*`, `replicate-*`, `verify-restore-*`, `browse-*`, `app-backup-download-*` rămân pe disc la crash. | Worker ucis în restore → zeci de GB orfani; se acumulează până activează DiskSpaceGuard → B-P1-3 (backup-urile se opresc). | Extinde prefixele curățate (sau curăță tot `storage/app/temp` peste o vârstă). |
| B-P2-5 | P2 | `routes/web.php:40-51`; `RestoreBackup.php:779-796` | Tokenul `/restore-download/{token}` nu e single-use și nu are TTL propriu; fișierul (arhivă completă a site-ului) e servit oricui are tokenul cât durează cererea (≤20 min) și pe termen nelimitat dacă unlink-ul din `finally` nu rulează. | Combinat cu B-P2-4: după un crash, o arhivă full-site rămâne descărcabilă fără autentificare pe termen nelimitat (protecția reală e doar entropia tokenului). | Marchează tokenul în cache cu TTL + șterge fișierul la prima descărcare completă / expirare; include `restore-*` în cleanup. |
| B-P2-6 | P2 | `app/Http/Controllers/Api/BackupRelayController.php` (fără rută); `app/Services/Backup/StreamingBackupUploader.php` (folosit doar de testul propriu); `CreateBackup.php:373-439,1066-1195` (cale v2 moartă); `app/Policies/BackupPolicy.php` | Cod mort semnificativ în cel mai critic modul — derutează și maschează regresii (ex. precache-ul pierdut, B-P1-7). | Un viitor dev „repară" relay-ul sau re-montează calea v2 și reintroduce comportamente netestate. | Șterge relay+uploader+calea v2 moartă; folosește sau șterge BackupPolicy (de preferat: folosește — B-P1-2). |
| B-P2-7 | P2 | `app/Console/Commands/VerifyBackupRestoreCommand.php` (nume vs. conținut); `routes/console.php:136-141` | „backup:verify-restore" nu face niciun restore — doar re-descărcare + verificare structurală. Nu există test de restore real periodic. | Un bug de restore (ex. B-P1-7, ordinea chunk-urilor, formate) rămâne nedetectat până la prima criză reală. | Redenumește onest (`backup:verify-integrity`) și/sau adaugă un smoke-restore lunar într-un container WP de sacrificiu. |
| B-P2-8 | P2 | `VerifyBackupRestoreCommand.php:68-93`; `IntegrityVerifier.php:141-157` | Level B folosește `verifyArchive()` pentru v3-zip: fără `chunk_files` și fără `files.zip`, verificarea fișierelor e sărită ca „db-only" — un v3-zip full fără `files/*` trece verificarea săptămânală. | Un bug de build care produce arhive fără fișiere ar fi „verificat PASS" săptămânal; descoperit doar la restore. | Rutare pe format: `verifyV3Zip()` pentru `v3-zip` (funcția există deja și verifică `files/*`). |
| B-P2-9 | P2 | `app/Services/AppBackup/AppBackupCreator.php:308-320` | `.env`-ul din app backup e criptat cu `encrypt()` = `APP_KEY`, iar `APP_KEY` trăiește în chiar acel `.env` → în scenariul „serverul e pierdut", backup-ul de config e indescifrabil. | VPS-ul moare; ai app backup-ul pe S3 dar nu poți decripta `.env` (cheia era pe VPS) → reconstrucție manuală a tuturor secretelor. | Criptează cu o cheie separată (age/GPG/parolă ținută în vault extern), documentată în runbook. |
| B-P2-10 | P2 | `config/horizon.php:233-244`; toate job-urile pe coada `backups` | Restore-ul împarte coada cu backup-urile/replicările; fără prioritate. | Incident la 03:05, coada plină de backup-uri programate de 30 min fiecare → restore-ul de urgență așteaptă. | Coadă `restores` dedicată, prioritară în supervisor (`queue => ['restores','backups']`). |
| B-P2-11 | P2 (WIP) | `app/Jobs/ExportBackupForLocal.php:75-111` | Exportul Local descarcă arhiva sursă + scrie output-ul pe discul app fără `DiskSpaceGuard` și fără să contabilizeze `used_bytes` la upload. | Export la un backup de 15GB cu 20GB liberi → disc plin în producție → B-P1-3 în cascadă. | Verifică `DiskSpaceGuard` (cu estimare 2×file_size) înainte de download; incrementează `used_bytes`. |
| B-P2-12 | P2 | `app/Jobs/RestoreBackup.php:734-751` | Calea tar din `createSelectiveArchive()` ignoră exit code-ul și stderr-ul lui `tar` → arhivă selectivă goală/parțială restaurată „cu succes". | Restore selectiv al 3 fișiere dintr-un backup tar.gz legacy; tar eșuează silențios; jobul raportează succes fără să fi restaurat nimic. | Verifică `proc_close()` ≠ 0 și numărul de fișiere extrase == cele cerute; aruncă altfel. |
| B-P2-13 | P2 | `app/Console/Commands/DatabaseDumpCommand.php:26-33`; `routes/console.php:100-104` | Dump-ul zilnic „independent" al Postgres-ului stă doar pe discul aceluiași VPS (`storage/app/db-dumps`), 7 zile. | Discul/VPS-ul moare → mor și site-backup-urile din DB (recuperabile prin reindex) și dump-urile DB, în același eveniment. | Push suplimentar către un StorageDestination off-site (S3 e deja configurat în app). |
| B-P2-14 | P2 | `app/Services/AppBackup/AppBackupRestorer.php:33-75` | Restore-ul DB-ului aplicației rulează `psql` pe DB-ul live fără maintenance mode și fără oprirea Horizon. | Job-urile active scriu în tabele aflate în mijlocul importului → date mixte/deadlock-uri; restore „reușit" cu stare inconsistentă. | `artisan down` + pauză Horizon în jurul importului (documentat și în UI). |
| B-P3-1 | P3 | `app/Livewire/Traits/WithBackupActions.php:256-285` | `downloadBackup` pentru `multipart-v3` generează presigned URL către prefix (nu fișier) → link mort; niciun guard pe format. | Click „Download" pe un backup multipart legacy → 404 de la S3. | Ascunde/înlocuiește butonul pentru formate ne-single-file. |
| B-P3-2 | P3 | 6 locuri care ating `used_bytes` (`WithBackupActions.php:202,231`, `BackupsOverview.php:313,333`, `RetentionService.php:132`, `ReplicateBackup.php:161`, finalize-urile din CreateBackup) | Contabilitate manuală distribuită a `used_bytes` → drift garantat în timp (decrement la delete eșuat, export Local necontabilizat). | Cotele afișate în UI diverg de realitate; alerta de cvasi-plin devine nefiabilă. | Job periodic de reconciliere (`listRecursive` + sum) sau centralizare într-un serviciu unic. |
| B-P3-3 | P3 | `app/Services/Backup/BackupHealthService.php:155-183` | `aggregate()` = 2-3 query-uri per site per render pe pagina Backups. | La 100+ site-uri, pagina Backups face ~300 query-uri per poll. | Batch ca în `siteHealthScores()` (deja există modelul) + cache scurt. |
| B-P3-4 | P3 | `app/Livewire/Sites/Detail/SiteBackups.php:78-89` | `estimatedBackupSize` = (db+uploads)×0.6 — ignoră core/plugins/themes; estimare sistematic sub realitate. | Utilizatorul subestimează spațiul necesar. | Folosește dimensiunea ultimului backup reușit ca estimare primară. |
| B-P3-5 | P3 | `app/Models/Backup.php:78-130` | `$fillable` include câmpuri de integritate (`checksum`, `status`, `verification_status`) — mass assignment permisiv; azi fără vector de exploatare identificat. | Un viitor endpoint care face `update($request->all())` ar permite falsificarea verificării. | Restrânge fillable sau folosește `$guarded` explicit pe câmpurile de integritate. |
| B-P3-6 | P3 | `app/Http/Controllers/Api/BackupCallbackController.php:54-57` | Tokenul HMAC de callback e static pe viața backupului, fără expirare; poate doar altera progresul afișat. | Un WP compromis poate „îngheța" progress bar-ul altui backup dacă ghicește ID-ul (impact cosmetic). | Include o fereastră temporală în HMAC sau folosește tokenul doar cât backupul e `in_progress`. |

**Contor: 2×P0, 8×P1, 14×P2, 6×P3.**

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-urile existente

1. **Restore cu plasă de siguranță reală (leagă B-P0-2)**: safety backup full obligatoriu + buton „Undo restore" care restaurează automat safety backup-ul — UX-ul există deja (modal, progres, chain restore), lipsește doar politica. *(S)*
2. **Notificări simetrice**: restore failed / replicare failed definitiv / disc plin / „site X nu a mai avut backup de N zile" prin `NotificationService` — toate canalele (Slack/Telegram) există deja; azi doar backup-failed e conectat. *(S)*
3. **Type-to-confirm + context în modalul de restore**: cere tastarea domeniului și afișează vârsta backupului („restaurezi la o stare de acum 26 de zile; între timp: 14 update-uri de plugin, ultima verificare de integritate: PASS acum 2 zile"). Toate datele sunt deja pe rândul `backups`. *(S)*
4. **Pagina Backups: coloană „replicare & verificare"** — `replicas[]` count, `verification_status`, `local_export_status` sunt în DB dar invizibile în listă; un badge „3-2-1 incomplet" ar face gap-urile de replicare acționabile. *(S)*
5. **Runbook DR generat automat**: `ReindexBackupsFromStorageCommand` + sidecar-urile fac reconstrucția posibilă; un doc `docs/disaster-recovery.md` generat/actualizat de comandă (destinatări, bucket-uri, pași) ar transforma-o în procedură. *(M)*

### (b) Feature-uri noi

1. **Test de restore automat în sandbox (paritate ManageWP/WPMU DEV „Safe Updates" infra)**: lunar, restaurează cel mai recent backup al fiecărui site într-un container WP efemer (docker există deja în stack) și rulează `PostRestoreVerifier` + un smoke check HTTP; raportează „restore-tested ✓" pe backup. Rațiune: singura garanție reală că backupurile sunt restaurabile — azi nu există niciun restore de test. *(L)*
2. **Staging one-click din backup (paritate SpinupWP/WPMU DEV)**: „Restore to new site" — restaurează backupul pe un subdomeniu/site nou în loc de site-ul live; elimină 80% din cazurile în care azi se face restore riscant pe live (verificări, comparări, recuperare de conținut). Pipeline-ul de materializare a arhivelor există deja în `RestoreBackup`; lipsește doar ținta alternativă. *(M)*
3. **Criptare client-side opțională a backupurilor (paritate UpdraftPlus premium)**: age/libsodium stream la upload, cheie per instalație ținută în afara serverului; azi dump-urile SQL ale clienților stau în clar pe storage terț (Dropbox/S3). *(M)*

---
*Raport generat pe baza citirii integrale a fișierelor citate; numerele de linie corespund working tree-ului din 2026-07-02 (inclusiv modificările necomise).*
