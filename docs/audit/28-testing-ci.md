# 28 — Testing & CI

**Data:** 2026-07-02 · **Auditor:** Claude (audit tematic Faza 2) · **Scope:** `tests/`, `phpunit.xml`, `phpstan.neon`, `phpstan-baseline.neon`, absența CI, testabilitatea codului · **Metodă:** fiecare fișier de test citit integral; comenzi exclusiv read-only.

---

## Rezumat executiv

Platforma execută operații distructive pe site-uri live de clienți (restore, update, ștergere useri, remediere automată AI) și are **152 de metode de test în 23 de fișiere (~3.300 linii)** pentru ~400 de clase de aplicație plus un plugin WP de ~14,9k LOC. Nu există **niciun** test HTTP, Livewire, de autentificare, de comandă de consolă sau pentru connector. Nu există **niciun CI** (verificat: nu există `.github/`, `.gitlab-ci.yml`, `Jenkinsfile`, `.circleci/`, `bitbucket-pipelines.yml`; `deploy.sh` nu conține niciun pas de `pint`/`phpstan`/`phpunit`). Fluxul real de livrare este *commit → push → deploy pe producție*, fără nicio poartă automată.

Contextul istoric este esențial: pe 1 aprilie 2026, commit-ul `a9672fb` („chore: remove staging env, tests, and unused files") a **șters integral suita anterioară — 95 de fișiere de test, −15.712 linii**, inclusiv `phpunit.xml`, testele de auth, ~50 de teste Livewire, `RestoreBackupTest`, `SecurityAgentControllerTest`, `BackupDownloadTest` — cu justificarea „not maintained". Ce există azi este o reconstrucție parțială, reactivă (ex. `tests/Feature/CriticalSchemaTest.php` a fost adăugat după incidentul real „9 days of broken backups" — vezi comentariul din fișier, liniile 13–16, și commit-ul `3d00f75` „fix: harden backup pipeline after 12-day scheduler outage").

Ce e testat e în general de calitate decentă (logica de decizie a serviciilor distructive, integritatea arhivelor de backup). Ce lipsește este exact stratul unde o regresie lovește un site live: job-urile `RestoreBackup` (876 linii) și `CreateBackup` (1.195 linii) — **zero teste directe**, autorizarea acțiunilor Livewire distructive, autul HMAC al connectorului, endpoint-urile publice cu token. În plus, `phpunit.xml` nu izolează infrastructura: rulat pe acest VPS, un test care atinge `JobTracker::appendLog()` **scrie în Redis-ul de producție**. Iar suita nici măcar nu este rulabilă în mod documentat pe host (nu există PHP pe host, imaginea de producție nu are dev-deps — verificat, existența bazei `simplead_test`: neverificat).

Constatări: **0×P0, 6×P1, 7×P2, 3×P3**. Livrabilul principal — planul de remediere ordonat pe risc — este în secțiunea dedicată.

---

## Realitatea coverage-ului

Configurația (`phpunit.xml:21–33`): `DB_CONNECTION=pgsql`, `DB_DATABASE=simplead_test`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `MAIL_MAILER=array`. **Nu sunt suprascrise:** `DB_HOST`/`DB_USERNAME`/`DB_PASSWORD` și niciun `REDIS_*` — testele moștenesc valorile din `.env` (pe acest server: producția). 11 din 22 de fișiere de test folosesc `RefreshDatabase` (deci ating Postgres real); restul sunt unit-teste pure sau pe filesystem.

Baza de test: `tests/TestCase.php:13–25` oferă `createMockApi()`/`createMockApiFactory()` — mock pe `WordPressApiServiceInterface` + factory. Acesta este seam-ul central al întregii suite: **tot ce înseamnă „site WordPress" este un mock configurat manual**, deci nimic nu validează contractul real cu pluginul connector.

Evaluare fișier cu fișier (fiecare citit integral):

| Fișier | Ce acoperă REAL | Soliditate |
|---|---|---|
| `tests/Feature/CriticalSchemaTest.php` (51 l., 9 cazuri data-provider) | Existența coloanelor citite de dispatcher-ele pe minut (`backups.auto_retry_count` etc.) — guard născut dintr-un incident real | **Solid și valoros** — prinde exact clasa de eșec „migrare marcată Ran fără DDL aplicat" care a produs 9 zile de backups stricate |
| `tests/Feature/Jobs/DeleteSpamUsersJobTest.php` (98 l., 2 teste) | Singurul test de job din suită: ștergerea locală urmează răspunsul WP (`deleted`/`failed`), userul eșuat rămâne | **Solid pe logică**, dar forma răspunsului e hard-codată în mock; comentariul de la linia 49 („The connector returns deleted/failed at the top level (no `data` wrapper)") dovedește că acest contract a divergat deja o dată. Linia 26 `Redis::spy()` — dovadă că autorii știu că restul suitei NU e izolat de Redis |
| `tests/Feature/Services/DatabaseCleanupServiceTest.php` (133 l., 7 teste) | Agregare stats, normalizare chei, înregistrare `completed`/`failed`, optimize/delete/convert table | Decent; API mock-uit, dar side-effects DB verificate real |
| `tests/Feature/Services/IncidentActionExecutorTest.php` (181 l., 10 teste) | Guard-uri (action limit, unknown action), înregistrarea acțiunilor + `sequence`, incrementare contor, excepție → failed | Decent pe orchestrare. **Nu testează acțiunile distructive în sine** (deactivate_plugin e testat doar pe cazul „missing plugin_id") |
| `tests/Feature/Services/IncidentResponderServiceTest.php` (172 l., 7 teste) | Guard-uri de siguranță: disabled, cooldown, limită orară, escaladare când ambele tier-uri eșuează | **Valoros** — acestea sunt frânele sistemului AI care execută acțiuni pe site-uri live. Toți cei 4 colaboratori sunt mock-uiți, deci dovedește doar rutarea/guard-urile, nu execuția |
| `tests/Feature/Services/PluginManagerServiceTest.php` (159 l., 8 teste) | performUpdate + UpdateLog, activate/deactivate/delete cu side-effects DB, eșec API păstrează recordul | Decent; bun că verifică și negativele |
| `tests/Feature/Services/RetentionServiceTest.php` (184 l., 8 teste) | Logica de ștergere: count/days, lanțuri incrementale, backups locked protejate, orfani curățați, failed neatinse | **Cel mai bun din suită** — ștergerea de backups e o operație P0-adiacentă și logica de decizie e acoperită complet pe DB real |
| `tests/Feature/Services/RollbackServiceTest.php` (182 l., 8 teste) | Ciclul de viață al rollback points, parametrii exacți trimiși la API (`->with('plugin', 'yoast-seo', '20.0')`), dispatch `SyncWordPressSite` | Solid pe logică; execuția reală a rollback-ului pe site = mock |
| `tests/Feature/Services/SafeUpdateServiceTest.php` (195 l., 6 teste) | Gating pe health-check, `auto_rollback` chiar declanșează `executeRollback`, excepția marchează `failed` | **Valoros** — logica ce decide dacă un update stricat e derulat înapoi. Job-ul `RunSafeUpdate` care înfășoară totul: netestat |
| `tests/Feature/Services/SecurityCommandServiceTest.php` (209 l., 9 teste) | Coadă de comenzi: prioritizare, anulare duplicate, retry sub max_attempts, stale cleanup | Solid, pur DB, fără mock-uri |
| `tests/Unit/MigrationCollisionTest.php` (51 l., 1 test) | Guard anti-coliziune timestamp migrări | OK, dar `KNOWN_COLLISIONS` (liniile 16–23) e **stale**: referă prefixe `2026_02_*` care nu mai există — migrările au fost squash-uite în `database/schema/pgsql-schema.sql` + 8 fișiere rămase |
| `tests/Unit/Services/AppBackupCreatorTest.php` (107 l., 10 teste) | `resolveComponents()` privată via Reflection | Valoare mică; `test_format_bytes_helper` (liniile 99–106) verifică doar „nu aruncă” + `assertIsString` |
| `tests/Unit/Services/AppBackupHelpersTest.php` (67 l., 4 teste) | `exec()` și `cleanupDir()` pe FS real | Mic dar real |
| `tests/Unit/Services/AppBackup/AppBackupRestorerTest.php` (195 l., 9 teste) | Guard-uri componente lipsă + `verifyRestore` (match/mismatch/missing/no_baseline) | Mixt. `test_pgsql_command_is_built_correctly` și `test_mysql_command_is_built_correctly` (liniile 148–194) sunt **tautologice**: construiesc `sprintf`-ul ÎN test și fac assert pe propriul string — zero legătură cu codul de producție. Restore-ul propriu-zis al aplicației: netestat |
| `tests/Unit/Services/Backup/IntegrityVerifierTest.php` (160 l., 6 teste) | Construiește arhive ZIP reale (meta, chunks, db gzip), le corupe, verifică detecția: sha mismatch, meta lipsă, arhivă coruptă, dump trunchiat | **Excelent** — exact tipul de test care previne un restore cu arhivă coruptă |
| `tests/Unit/Services/Backup/PostRestoreVerifierTest.php` (172 l., 9 teste) | Pașii post-restore (cache, Elementor, diagnostic) + degradare grațioasă | Decent, dar assert-uri pe stringuri de mesaj („cache cleared”) — fragil |
| `tests/Unit/Services/Backup/SqlDumpParserTest.php` (111 l., 6 teste) | Validare dump SQL pe fișiere reale, inclusiv gzip: header/footer, empty, no CREATE TABLE | **Solid** |
| `tests/Unit/Services/Backup/StreamingBackupUploaderTest.php` (151 l., 8 teste) | Upload + rollback + manifest pe driver **local** real | Solid, dar doar driverul `local`; S3/Dropbox — netestate (și `S3Driver.php` e chiar modificat necomis în working tree) |
| `tests/Unit/Services/IncidentResponse/AiAgentServiceTest.php` (199 l., 7 teste) | Guard-uri (fără API key, limită apeluri + `Http::assertNothingSent`), bucle tool_use (resolve/escalate/end_turn) pe `Http::fake` Anthropic, și `test_tool_definitions_do_not_include_delete_plugin` | **Solid și important** — verifică că agentul AI nu primește unelte de ștergere |
| `tests/Unit/Services/IncidentResponse/PlaybookRunnerTest.php` (115 l., 9 teste) | Rutarea trigger→playbook, unicitatea numelor, toate trigger-ele au playbook | OK, superficial (doar selecția, nu pașii playbook-urilor) |
| `tests/Unit/Services/MaintenancePlanServiceTest.php` (98 l., 9 teste) | Helpere pure de numărat setări | Trivial |
| `tests/Unit/Services/WordPressBackupDownloaderTest.php` (301 l., 7 teste) | Protocolul de download: fallback init→sync, checksum mismatch șterge fișierul, chunk exec failure, fișier gol detectat, progress callback | **Solid** — jumătatea de „download” a pipeline-ului de backup e acoperită la nivel de protocol (pe răspunsuri PSR-7 fabricate, nu HTTP real) |

**Concluzia coverage-ului:** ce există acoperă *logica de decizie* a câtorva servicii distructive și *integritatea arhivelor*. Este o suită „de servicii”, nu „de aplicație”: niciun request HTTP nu e testat, nicio componentă Livewire nu e montată, niciun job greu nu e rulat cap-coadă. Mock-ul omniprezent pe `WordPressApiServiceInterface` face ca orice schimbare de contract în plugin (s-a întâmplat deja, vezi `DeleteSpamUsersJobTest.php:49`) să treacă verde prin toată suita.

**Statusul suitei (verde/roșu): neverificat** — nu poate fi rulată read-only în acest mediu (vezi mai jos), iar rularea ei pe acest VPS ar atinge Redis-ul de producție (T-03).

---

## Ce NU e testat deloc

Toate afirmațiile de mai jos sunt verificate prin listarea completă `tests/` (23 de fișiere, enumerate mai sus — nu există alte directoare):

1. **HTTP / rute** — zero. Nu există niciun test cu `$this->get()`/`$this->post()`. Neacoperite: toate cele 29 de controllere, endpoint-urile publice fără auth din `routes/web.php` (`/restore-download/{token}` — closure la `routes/web.php:40–51`, `/r/{report}/{token}`, `/reports/{report}/download/signed`, `/download/connector-plugin/signed`, `/api/webhooks/inbound`) și toate cele 3 suprafețe din `routes/api.php` (callback HMAC `X-Backup-Token` — `app/Http/Controllers/Api/BackupCallbackController.php:16–31`, Bearer PAT `/v1/*`, agent HMAC `/agent/{site_token}/security/*`).
2. **Livewire** — zero din 104 componente. Nicio verificare că `deleteBackup`/`bulkDelete` (`app/Livewire/Traits/WithBackupActions.php:181–247`) respectă scoping-ul `$this->site->backups()` sau flag-ul `is_locked`; nicio montare de componentă; niciun test de autorizare pe acțiuni distructive. (Suita ștearsă în `a9672fb` conținea ~50 de astfel de fișiere, inclusiv `SiteBackupsTest`, `SecurityUsersTest`, `GlobalDashboardAuthorizationTest`.)
3. **Auth / 2FA / middleware** — zero. `routes/auth.php` (Breeze + Google SSO + invitații + 2FA), `TwoFactorChallengeController`, middleware-ul `EnforceTwoFactor` (care a avut deja 2 fix-uri recente: commit-urile `d43c52d`, `fc0defd` — regresii care ar fi fost prinse de un test de middleware) — nimic.
4. **Connectorul WP** — zero teste pentru cele 44 de fișiere PHP (~14,9k LOC), inclusiv `includes/class-authentication.php` (HMAC + nonce anti-replay + calea legacy fără nonce încă acceptată, liniile 44–119) și cele 24 de clase de endpoint REST. Nici partea de semnare din manager (`app/Services/WordPress/WordPressHttpClient.php:81–103 buildAuthHeaders`) nu are vreun test — protocolul criptografic dintre manager și site-uri e complet neverificat automat, pe ambele capete.
5. **Comenzile de consolă** — zero din 21, inclusiv `VerifyBackupRestoreCommand`, `BackupReleaseLock`, `ReindexBackupsFromStorageCommand`, `SecurityMaintenanceCommand` (ștergeri de retenție). La fel `routes/console.php` (218 linii, ~30 intrări de scheduler) — clasa de eșec care a produs deja „12-day scheduler outage” (commit `3d00f75`).
6. **Job-urile grele** — 50 din 51 netestate direct: `CreateBackup` (1.195 linii), `RestoreBackup` (876 linii), `RunSafeUpdate`, `PushConnectorPlugin` (132 linii), `ReplicateBackup`, `RunIncidentResponse`, `CheckUptime`, `GenerateReport` etc. Singurul testat: `DeleteSpamUsersJob`.
7. **Notificări & alerting** — zero: sender-ele Slack/Discord/Telegram/Email, `ProcessNotificationBatch`, escaladările, `NotifyBackupFailed` — canalul prin care agenția află că un backup a eșuat.
8. **Codul WIP necomis** — `app/Jobs/ExportBackupForLocal.php`, `app/Services/Backup/LocalFlywheelRepackager.php`, `app/Http/Controllers/BackupLocalExportDownloadController.php`, migrația `2026_06_08_120000_add_local_export_fields_to_backups_table.php` + modificările din `WithBackupActions.php`/`S3Driver.php` — zero teste, direct pe fluxul de backup.

---

## CI

**Nu există CI.** Verificat: niciun `.github/` (nici măcar directorul), `.gitlab-ci.yml`, `Jenkinsfile`, `.circleci/`, `.drone.yml`, `bitbucket-pipelines.yml`. `deploy.sh` nu conține pași de test/lint/analiză (grep pe `phpunit|pint|phpstan|test`: zero rezultate relevante). Remote-ul e GitHub (`github.com/andreialexandrupanait/simplead-manager`), deci GitHub Actions e alegerea naturală.

Agravant, suita nu e rulabilă nici măcar local în mod documentat:
- Pe host nu există binar PHP (`/usr/bin/env: 'php': No such file or directory` la `./vendor/bin/phpunit`).
- Containerul de producție nu are dev-deps (verificat: `vendor/bin/` în container conține doar `carbon`, `jp.php`, `patch-type-declarations`, `var-dump-server` — fără phpunit/phpstan/pint). Wrapper-ul `bin/pint` funcționează doar pentru că montează vendor-ul de pe host peste container; nu există echivalent `bin/phpunit`/`bin/phpstan`.
- Existența bazei `simplead_test` pe serverul pgsql: **neverificat** (necesita parolă; citirea `.env` e interzisă de politica proiectului).

### Pipeline-ul minim propus (GitHub Actions, un singur workflow)

```yaml
# .github/workflows/ci.yml (schiță)
on: [push, pull_request]
jobs:
  lint:      # ./vendor/bin/pint --test            (~30s)
  static:    # ./vendor/bin/phpstan analyse        (~2-3 min; ridică parallel > 1 în CI)
  audit:     # composer audit                      (~10s)
  tests:     # services: postgres:16-alpine, redis:7-alpine
             # php 8.3 + ext: pgsql, redis, zip, gd, pcntl, intl
             # composer install → cp .env.example .env → key:generate
             # ./vendor/bin/phpunit  (schema dump-ul database/schema/pgsql-schema.sql
             #  se încarcă automat de migrate la RefreshDatabase)
```

Detalii de care va depinde efortul: (1) `phpunit.xml` trebuie completat cu `DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD` + `REDIS_HOST` pentru serviciile din CI (azi vin din `.env` — în CI pur și simplu nu ar exista); (2) extensiile PHP cerute de backup pipeline (zip, gd) și de teste; (3) baseline-ul PHPStan e deja comis, deci `static` trece din prima; (4) un badge/branch-protection pe `main` ca `deploy` să nu mai fie posibil pe roșu.

**Efort estimat: 0,5–1 zi** pentru pipeline-ul complet (inclusiv debugging-ul încărcării schema dump-ului pe Postgres 16 și cache-ul de composer), plus **~0,5 zile** pentru un wrapper local `bin/test` (docker compose profile cu `pgsql-test` efemer) ca suita să fie rulabilă și pe VPS fără să atingă producția.

---

## PHPStan

Configurație (`phpstan.neon`): **level 5** + Larastan, `parallel.maximumNumberOfProcesses: 1`, `reportUnmatchedIgnoredErrors: false`, ignore global `#Access to an undefined property App\\Livewire\\#` și `excludePaths`: `app/Console/Commands/MigrateReportFiles.php` + **`app/Livewire/Traits/`** (liniile 15–17). Excluderea întregului director de trait-uri Livewire scoate din analiză exact codul acțiunilor distructive UI (`WithBackupActions.php`, cu modificări necomise chiar acum) — o gaură de analiză, nu doar de test.

Baseline (`phpstan-baseline.neon`, 727 linii): **121 de intrări distincte, 160 de erori totale** (suma câmpurilor `count`). Distribuția pe identifier (numărată exact):

| Identifier | Intrări | Risc |
|---|---|---|
| `argument.type` | 73 | majoritatea familia `string\|false` (~45 de intrări o menționează) — vezi mai jos |
| `method.notFound` | 16 | mixt: scope-uri Eloquent nedeclarate (zgomot) + **drift real de interfață** |
| `return.type` | 10 | `string\|false` nefiltrat propagat ca `string` |
| `offsetAccess.*` | 10 | chei de array posibil lipsă (mai ales în `IntegrityVerifier`, `SettingsService`) |
| `staticClassAccess.privateMethod` | 3 | `WordPressEolService` — `static::` pe metode private; fatal doar la subclasare |
| `empty.variable` / `deadCode.unreachable` / `closure.unusedUse` | 4 | cod mort / logică suspectă |
| `variable.undefined` | 1 | **bug aproape sigur** |
| `arguments.count` | 1 | **bug aproape sigur** |
| altele (`method.unused`, `binaryOp.invalid`, `encapsedStringPart`, `offsetAccess.invalidOffset`) | 4 | mixt |

### Categorii după riscul de a ascunde bug-uri reale

**Categoria A — foarte probabil bug-uri reale de runtime, mascate de baseline:**
1. `phpstan-baseline.neon:357–361` — `Undefined variable: $config` ×3 în `app/Services/AppBackup/AppBackupCreator.php`. O variabilă nedefinită într-un serviciu care creează backup-ul aplicației manager = notice + comportament nedefinit pe o cale de execuție.
2. `phpstan-baseline.neon:663–667` — `Job class App\Jobs\GenerateReport constructor invoked with 2 parameters in GenerateReport::dispatch(), 4-8 required` în `app/Livewire/Reports/ReportsOverview.php`. Dacă acea cale de cod e atinsă, e `ArgumentCountError` fatal la dispatch.
3. `phpstan-baseline.neon:717–727` — `Unreachable statement` + `Variable $metrics in empty() always exists and is always falsy` în `app/Livewire/Traits/WithReportGeneration.php`. Dublu suspect: fișierul e în `app/Livewire/Traits/`, care e în `excludePaths` — intrările sunt orfane, iar `reportUnmatchedIgnoredErrors: false` ascunde asta.
4. `phpstan-baseline.neon:544–548` — `Binary operation "%" between float|int<60, max>|string and 60 results in an error` în `app/Services/Reports/BaseReportSectionGatherer.php` — `TypeError` potențial pe generarea de rapoarte.

**Categoria B — drift de contract (risc mediu, ascunde LSP breaks):**
5. `phpstan-baseline.neon:189–199` — `Call to an undefined method App\Contracts\WordPressApiServiceInterface::bulkDeleteUsers()` (în `DeleteSpamUsersJob`) și `::getErrorLogs()` (în `FetchPhpErrorLogs`). Interfața — singurul seam de testare al aplicației — nu declară metode pe care job-urile le apelează. Orice implementare alternativă (inclusiv mock-urile din teste, care se configurează pe interfață în `TestCase::createMockApi()`) e formal invalidă. Neverificat dacă la rulare PHPUnit 11 refuză configurarea acestor metode pe mock — încă un motiv pentru care statusul suitei trebuie stabilit urgent.
6. Uniunile `Model|Collection` din `find()`: `phpstan-baseline.neon:33–43` (`BackupCallbackController`), `:45–61` (`TwoFactorChallengeController`), `:285–295` (`RetentionCleanup`). Concret: `Backup::find($backupId)` cu `$backupId = $request->input('backup_id')` (`app/Http/Controllers/Api/BackupCallbackController.php:17,23`) — un `backup_id[]=1&backup_id[]=2` trimis de un client întoarce Collection și crapă cu 500 pe endpoint public. Robustețe, nu breșă, dar e exact genul de lucru pe care baseline-ul îl îngroapă.

**Categoria C — familia `string|false` (~45 intrări, risc contextual):** majoritatea sunt `json_encode`/`file_get_contents`/`fopen`/`fread` cu retur neverificat. Inofensive în cod de raportare, dar mai multe sunt **pe calea de backup/restore**: `app/Jobs/RestoreBackup.php` (5 intrări: `json_decode(string|false)`, `fread/fclose(resource|false)`, `ZipArchive::addFromString(string|false)`), `BackupManifestV3::encode()` care poate întoarce `false` scris ca manifest, `DropboxDriver`/`LocalDriver`. Pe disc plin sau citire S3 eșuată, acestea degenerează silențios (manifest corupt, arhivă cu intrare goală) în loc să eșueze zgomotos — într-un pipeline care ulterior suprascrie site-uri live.

**Categoria D — zgomot real (fals-pozitive de nivel/DSL):** scope-uri Eloquent (`HasMany::completed()`, `::running()`, `::broken()` — ~8 intrări), `offsetAccess` pe array-shapes cu chei opționale, `closure.unusedUse`. Acestea pot rămâne; restul ar trebui scoase din baseline și reparate.

Recomandare (fără aplicare): reparați categoria A (4 intrări, ~0,5 zile), declarați metodele lipsă pe interfață (categoria B, ~0,5 zile), eliminați `app/Livewire/Traits/` din `excludePaths`, apoi regenerați baseline-ul și activați `reportUnmatchedIgnoredErrors: true`. Trecerea la level 6 e prematură până nu scade baseline-ul.

---

## Plan de remediere a testării — ORDONAT PE RISC

> Livrabilul principal. Fiecare bloc: teste concrete, efort estimat (zile de lucru efectiv), regresia periculoasă pe care o prinde. Ordinea = ordinea riscului de a strica un site live de client. Total: ~13–17 zile, împărțibile în sprinturi.

### Bloc 0 (precondiție) — Fundația rulabilă și izolată — **1 zi**
- Pin în `phpunit.xml`: `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST` (sau `REDIS_CLIENT` fake) — azi vin din `.env`-ul de producție (`phpunit.xml:21–33` nu le conține).
- Izolare Redis global: `Redis::spy()`/prefix dedicat în `TestCase::setUp()` sau un `phpunit.xml` cu `REDIS_DB` separat — azi doar `DeleteSpamUsersJobTest.php:26` se protejează individual, iar `JobTracker::appendLog()` (`app/Services/JobTracker.php:84–86`) scrie direct în Redis-ul din `.env`.
- `bin/test` (wrapper docker cu pgsql efemer) + documentarea creării `simplead_test`.
- **Prinde:** poluarea producției de către suită; deblochează tot restul planului. Fără acest bloc, oricine rulează testele pe VPS riscă side-effects în prod.

### Bloc 1 — Restore path end-to-end cu site fake — **4–5 zile** (cel mai mare risc din platformă)
Harness: o implementare `FakeWordPressApiService` a interfeței (nu mock per-test) care simulează protocolul de restore al connectorului (upload chunk, db import, health, maintenance on/off) + arhive reale construite ca în `IntegrityVerifierTest::buildArchive()` pe `StorageDestination` local.
Teste concrete:
1. Restore full v2-zip happy-path: stages în ordine, `progress_percent` monoton, `PostRestoreVerifier` invocat, backup-ul marcat restaurat.
2. Restore v3 (manifest + chunks) — calea `BackupManifestV3` din `RestoreBackup.php`.
3. **Arhivă coruptă / sha mismatch → job-ul abortează ÎNAINTE de a trimite ceva spre site** (integrarea `IntegrityVerifier` în job — azi verifier-ul e testat izolat, integrarea nu).
4. Eșec la chunk-ul k din n (fake-ul întoarce 500): status `failed`, mesaj util, **site-ul nu rămâne în maintenance mode**.
5. Lanț incremental: full + incremental compuse în ordinea corectă.
6. `/restore-download/{token}`: token non-hex → 404 (`routes/web.php:42–44`), fișier consumat/expirat → 404, conținutul servit e exact arhiva.
7. `VerifyBackupRestoreCommand` smoke-test pe fake.
- **Prinde:** regresia P0 supremă — suprascrierea unui site live de client cu o arhivă coruptă/parțială sau lăsarea lui în maintenance după un restore eșuat. Este singura operație din platformă unde nu există „undo”.

### Bloc 2 — Plugin push / safe-update — **2–3 zile**
1. `RunSafeUpdate` job feature-test (queue sync): pending → completed cu rollback point creat; health fail + `auto_rollback=true` → `executeRollback` chemat (extinde `SafeUpdateServiceTest` la nivel de job, cu notificările verificate `Queue::assertPushed`).
2. `PushConnectorPlugin` (`app/Jobs/PushConnectorPlugin.php`): zip-ul construit are hash stabil, header `Version:` == `SAM_VERSION` (guard pe convenția din CLAUDE.md), URL-ul semnat `download.connector-plugin.signed` e valid și expiră; răspuns 403 (Cloudflare loopback) → marcat failed cu retry, nu success.
3. `ConnectorPluginDownloadController`: semnătură invalidă → 403; validă → zip-ul corect.
4. Update de plugin raportat `success=false` de WP → `UpdateLog.success=false` și plugin-ul local nemodificat (negativul din `PluginManagerServiceTest` ridicat la nivel de job + Livewire dispatch).
- **Prinde:** împingerea unui connector corupt pe toate site-urile (pierderea managementului întregii flote) și update-uri care raportează succes cu site-ul down.

### Bloc 3 — Auth connector: HMAC + replay — **2 zile**
1. Manager-side (`WordPressHttpClient::buildAuthHeaders`, liniile 81–103): golden-test — pentru method/path/body/timestamp/nonce fixate, semnătura HMAC-SHA256 exactă; nonce unic per request; body-ul inclus în string-to-sign (regresia clasică: semnare fără body = tampering liber pe payload).
2. Plugin-side (`includes/class-authentication.php`) cu Brain Monkey/WP_Mock (stub `get_transient`/`set_transient`/`wp_cache_add`): timestamp în afara toleranței → respins (liniile 44–53); nonce reutilizat → respins înainte și după validarea HMAC (liniile 59–67, 107–119); semnătură greșită → `hash_equals` respinge; **test care documentează explicit calea legacy fără nonce încă acceptată** (liniile 78–96) — ca eliminarea ei planificată să fie o schimbare testată.
3. Round-trip: headerele produse de manager trec de validarea pluginului (același string-to-sign pe ambele capete — protecție anti-drift între cele două implementări).
- **Prinde:** breșă de replay/tampering pe canalul care poate șterge useri, instala plugin-uri și importa baze de date pe site-urile clienților.

### Bloc 4 — Backup callback + fluxurile publice cu token — **1–2 zile**
1. `POST /api/backup/callback`: token HMAC valid → progress actualizat (30–90%); token invalid → 403 și backup neatins (`BackupCallbackController.php:28–31`); token/backup_id lipsă → 400; `backup_id` array → 4xx, nu 500 (uniunea `Model|Collection` din baseline, `phpstan-baseline.neon:33–43`); `generateToken` e stabil pentru același backup.
2. `/r/{report}/{token}`: token greșit → 404/403; `reports.download.signed`: semnătură expirată → 403.
3. WIP-ul necomis `BackupLocalExportDownloadController`: cere auth + `authorize('view', $backup->site)` (liniile 18–21) — test de policy + token expirat; `ExportBackupForLocal`/`LocalFlywheelRepackager`: repachetarea produce zip valid (reutilizează harness-ul din Bloc 1).
4. Rate-limiting smoke: `throttle` pe rutele publice chiar răspunde 429.
- **Prinde:** manipularea stării backup-urilor de către terți și scurgerea arhivelor (care conțin dump-uri complete de DB ale clienților) prin endpoint-urile publice.

### Bloc 5 — Authz pe acțiunile Livewire distructive — **2–3 zile**
Cu `Livewire::test()` (există deja 24 de factories):
1. `WithBackupActions::deleteBackup/bulkDelete/toggleLock/downloadBackup`: ID de backup al **altui** site → `ModelNotFoundException` (garanția scoping-ului `$this->site->backups()` — `WithBackupActions.php:184, 215–216`); backup locked → refuz + mesaj (liniile 186–190); full cu incrementals → refuz (192–196); `bulkDelete` sare locked (217).
2. Restore trigger din UI: cere confirmare, dispatch-uiește `RestoreBackup` cu backup-ul corect (`Queue::assertPushed` cu closure pe ID).
3. `SecurityUsers`/spam delete: dispatch `DeleteSpamUsersJob` doar cu ID-urile selectate, nu tot site-ul.
4. Update-uri bulk din `Livewire/Updates/*`: doar site-uri conectate.
5. Guest/2FA: componentele de site nu se montează neautentificat; `EnforceTwoFactor` lasă endpoint-ul Livewire să treacă (regresia deja produsă de două ori: commit-urile `fc0defd`, `d43c52d`).
- **Prinde:** cross-site actions prin payload Livewire manipulat (un ID de backup/plugin al clientului B trimis din pagina clientului A) și ștergeri fără confirmare.

### Bloc 6 — Notificări critice — **1–2 zile**
1. `NotifyBackupFailed`: backup failed → notificare pe canalele configurate, **o singură dată** (dedupe), cu site-ul corect.
2. `ProcessNotificationBatch` + `ProcessNotificationEscalations`: batch-ul grupează pe canal, escaladarea se declanșează după pragul configurat (atenție: `ProcessNotificationBatch` folosește `Redis` direct — depinde de Bloc 0).
3. Uptime down → incident + notificare; recovery → notificare de recovery, nu duplicat de down.
4. Sender-ele Slack/Discord/Telegram/Webhook pe `Http::fake`: payload-ul are formatul corect; `WebhookNotificationSender` semnează HMAC corect (`phpstan-baseline.neon:520–523` arată `hash_hmac` cu `string|false` chiar acolo).
- **Prinde:** tăcerea alerting-ului — clasa de eșec deja materializată („9 days of broken backups” au fost 9 zile pentru că nimeni n-a fost notificat).

După blocurile 0–6: CI-ul din secțiunea anterioară devine poartă obligatorie pe `main` (branch protection), iar `deploy` fără CI verde nu se mai face.

---

## Testabilitate

**Ce ajută (seams existente):**
- `App\Contracts\WordPressApiServiceInterface` + `WordPressApiServiceFactory` — seam-ul central există și e folosit consecvent: job-urile îl iau din container (`app(WordPressApiServiceFactory::class)->make($site)` — ex. `app/Jobs/RestoreBackup.php:233,547`, `CreateBackup.php:106`), serviciile prin constructor injection (`DatabaseCleanupService.php:15`, `SafeUpdateService.php:19`). `DeleteSpamUsersJobTest` demonstrează că substituția prin `$this->app->instance()` funcționează. Un `FakeWordPressApiService` (Bloc 1) e deci fezabil fără refactor.
- 24 de factories deja scrise, schema squash-uită (`database/schema/pgsql-schema.sql` + 8 migrații) face `RefreshDatabase` rapid.
- `QUEUE_CONNECTION=sync` permite teste de job end-to-end fără worker.

**Ce blochează sau scumpește testarea:**
1. **God-jobs**: `CreateBackup.php` — 1.195 linii, `RestoreBackup.php` — 876 linii; protocol HTTP, filesystem, ZipArchive, progres DB și decizii de retry împletite în `handle()`. Singura testare realistă e cea end-to-end din Bloc 1; testarea unitară a pașilor ar cere extragerea lor (parțial făcută: `IntegrityVerifier`, `SqlDumpParser`, `PostRestoreVerifier`, `WordPressBackupDownloader` — exact bucățile care AU teste; corelația e grăitoare).
2. **Servicii statice ne-mockabile**: `ActivityLogger` (toate metodele statice — `app/Services/ActivityLogger.php:13,37,51`), `JobTracker` (static + `Redis::` facade direct, liniile 84–99), `NotificationService` (9 metode statice, plus `Redis` direct). Orice test al unui apelant fie atinge Redis/DB real, fie n-are cum să intercepteze apelul. Minim: rutarea Redis-ului prin `Cache`/conexiune injectabilă.
3. **`curl_init` direct** în `WordPressHttpClient.php:169` (download-ul cu retry) — ocolește `Http::fake`; testabil doar cu un server HTTP real sau prin extragerea unui client injectabil. Partea `Http::withHeaders` (linia 126) e fake-abilă.
4. **Interfața incompletă** (`bulkDeleteUsers`, `getErrorLogs` lipsă — vezi PHPStan cat. B) face mock-urile pe interfață formal invalide; trebuie completată înaintea extinderii suitei.
5. **Pluginul WP** folosește funcții globale WordPress (`get_transient`, `wp_cache_add`, `register_rest_route`) — netestabil fără Brain Monkey/WP_Mock (dev-dep nou, doar în `wordpress-plugin/`) sau un `wp-env`. Costul e inclus în Bloc 3.
6. **`app/Livewire/Traits/` exclus din PHPStan** (`phpstan.neon:17`) + zero teste = codul acțiunilor UI distructive nu e verificat de absolut nimic automat.
7. **Mediu**: fără PHP pe host și fără dev-deps în imaginea de producție, bariera de intrare pentru „rulez testele înainte de commit” e azi practic infinită — de aici și „not maintained” din `a9672fb`. Bloc 0 + CI elimină cauza-rădăcină, nu doar simptomul.

---

## Constatări

| ID | Sev. | Fișiere:linii | Descriere | Scenariu de eșec | Schiță de remediere |
|---|---|---|---|---|---|
| T-01 | **P1** | commit `a9672fb` (2026-04-01); `tests/` | Suita anterioară (95 fișiere, −15.712 linii: auth, ~50 Livewire, controllers, `RestoreBackupTest`, `SecurityAgentControllerTest`, `phpunit.xml`) ștearsă integral ca „not maintained”; reconstruită doar ~20% și doar pe servicii | Orice regresie în straturile șterse (auth, Livewire, HTTP) ajunge nedetectată în producție; s-a întâmplat deja de 2× pe `EnforceTwoFactor` (`fc0defd`, `d43c52d`) | Planul pe blocuri din acest raport; recuperare selectivă din git history (`git show a9672fb^:tests/...`) unde componentele nu s-au schimbat |
| T-02 | **P1** | absența `.github/`; `deploy.sh` (fără pași de test) | Zero CI: nicio poartă automată între commit și producție; lint/analiză/teste rulează doar dacă cineva își amintește (și nici nu poate — vezi T-05) | Cod care nu compilează / teste roșii / vulnerabilități composer ajung direct pe serverul care administrează site-urile clienților | GitHub Actions: pint --test, phpstan, phpunit pe postgres+redis services, composer audit; branch protection pe `main`; efort 0,5–1 zi |
| T-03 | **P1** | `phpunit.xml:21–33`; `app/Services/JobTracker.php:84–99`; `app/Jobs/ProcessNotificationBatch.php`; `tests/Feature/Jobs/DeleteSpamUsersJobTest.php:26` | phpunit.xml nu suprascrie `DB_HOST/USERNAME/PASSWORD` și niciun `REDIS_*` → testele moștenesc `.env`-ul de producție; `JobTracker::appendLog`/`NotificationService`/`ProcessNotificationBatch` folosesc facade-ul `Redis` direct; un singur test se protejează cu `Redis::spy()` | Rularea suitei pe VPS scrie chei în Redis-ul de producție (partajat cu Horizon și cache) și se conectează la serverul pgsql de producție (baza `simplead_test`); un typo în `DB_DATABASE` la o rulare manuală ar rula `RefreshDatabase` pe o bază reală | Bloc 0: pin complet al env-ului de test în phpunit.xml + izolare Redis globală în `TestCase`; `bin/test` cu pgsql efemer |
| T-04 | **P1** | `app/Jobs/RestoreBackup.php` (876 l.), `CreateBackup.php` (1.195 l.), `RunSafeUpdate.php`, `PushConnectorPlugin.php`, `wordpress-plugin/simplead-manager-connector/` (44 fișiere), `routes/api.php:9` | Operațiile distructive cap-coadă și întregul connector nu au niciun test: doar sub-componente izolate (verifier, parser, downloader) sunt acoperite; integrarea lor în job-uri — nu | O regresie în orchestrarea restore-ului (ex. ordinea maintenance-mode/import, tratarea eșecului la chunk k) suprascrie parțial un site live sau îl lasă în maintenance; nedetectabilă înainte de client | Blocurile 1–4 din plan (restore e2e cu site fake, safe-update, HMAC, callback) |
| T-05 | **P1** | host fără PHP (verificat); container prod fără dev-deps (verificat `vendor/bin/`); `bin/` conține doar `pint` | Suita nu e rulabilă în niciun mod documentat pe mediul de lucru; existența `simplead_test`: neverificat; statusul suitei (verde/roșu): **neverificat** | „Testele” există dar nimeni nu le poate rula → putrezesc exact ca suita ștearsă în T-01; posibil deja roșii (vezi T-08: mock pe metode nedeclarate în interfață) | `bin/test` wrapper docker (compose profile cu pgsql-test) + documentare în CLAUDE.md; CI ca plasă permanentă |
| T-06 | **P1** | `phpstan.neon:15–17`; `phpstan-baseline.neon:357–361, 663–667, 717–727, 544–548` | Baseline-ul (121 intrări/160 erori) îngroapă bug-uri probabile de runtime: `Undefined variable: $config` ×3 (AppBackupCreator), `GenerateReport::dispatch()` cu 2 din 4–8 argumente (ReportsOverview), cod inaccesibil (WithReportGeneration), `%` pe string (BaseReportSectionGatherer); în plus `excludePaths: app/Livewire/Traits/` scoate din analiză chiar trait-urile acțiunilor distructive | Backup-ul aplicației rulează cu variabilă nedefinită pe o ramură; generarea raportului din UI crapă cu `ArgumentCountError`; modificările necomise din `WithBackupActions.php` nu sunt analizate de nimic | Reparat categoria A (~1 zi), completat interfața (cat. B), scos `Livewire/Traits` din excludePaths, regenerat baseline, `reportUnmatchedIgnoredErrors: true` |
| T-07 | P2 | `tests/Unit/Services/AppBackup/AppBackupRestorerTest.php:148–194`; `tests/Unit/Services/AppBackupCreatorTest.php:99–106` | Teste tautologice: comenzile psql/mysql sunt construite în test și verificate pe ele însele (zero cod de producție exercitat); `formatBytes` testat doar ca „nu aruncă” | Fals sentiment de acoperire pe restore-ul aplicației manager — componenta reală (`AppBackupRestorer::restoreDatabase`) poate regresa cu suita verde | Înlocuire cu teste pe metoda reală de construire a comenzii (extrasă ca metodă pură) sau ștergere |
| T-08 | P2 | `tests/TestCase.php:13–25`; `phpstan-baseline.neon:189–199`; `tests/Feature/Jobs/DeleteSpamUsersJobTest.php:49–57` | Toată suita mock-uiește `WordPressApiServiceInterface` cu forme de răspuns hard-codate, iar interfața nici nu declară `bulkDeleteUsers`/`getErrorLogs`; contractul cu pluginul real a divergat deja o dată (comentariul despre lipsa wrapper-ului `data`) | Pluginul schimbă forma răspunsului → toate testele rămân verzi, producția interpretează greșit rezultatul (ex. consideră șterși useri care n-au fost) | Completarea interfeței + testele de contract round-trip din Bloc 3; fixture-uri de răspuns extrase din pluginul real |
| T-09 | P2 | `tests/Unit/MigrationCollisionTest.php:16–23`; `database/migrations/` (8 fișiere) | `KNOWN_COLLISIONS` referă prefixe `2026_02_*` inexistente după squash-ul migrărilor în `database/schema/pgsql-schema.sql` | Zgomot/confuzie; lista moartă sugerează excepții care nu mai există | Golirea constantei (nicio coliziune reală rămasă) |
| T-10 | P2 | `tests/Unit/Services/Backup/StreamingBackupUploaderTest.php:30–32`; `app/Services/Backup/Storage/S3Driver.php` (modificat necomis), `DropboxDriver.php` | Doar driverul `local` e testat; S3 (destinația reală de producție) și Dropbox — zero, iar S3Driver are modificări necomise chiar acum | O regresie în multipart-upload S3 corupe silențios backup-urile off-site; se descoperă la restore, adică prea târziu | Teste S3 pe MinIO în CI (service container) sau pe `Storage::fake('s3')` pentru logica de path/manifest; minim un test de contract pe `S3Driver` |
| T-11 | P2 | `app/Jobs/ExportBackupForLocal.php`, `app/Services/Backup/LocalFlywheelRepackager.php`, `app/Http/Controllers/BackupLocalExportDownloadController.php`, `database/migrations/2026_06_08_...` (toate necomise) | Feature nou pe fluxul de backup dezvoltat fără niciun test, într-un working tree fără CI | Repachetarea Flywheel produce arhive invalide livrate clientului; endpoint-ul de download expune arhive dacă policy-ul/token-ul regresează | Testele din Bloc 4.3 înainte de commit |
| T-12 | P2 | `routes/console.php` (218 l.); `app/Console/Commands/` (21 fișiere); commit `3d00f75` | Schedulerul și comenzile — zero teste, deși au produs deja un outage de 12 zile; nici măcar un smoke-test `schedule:list`/`command:exists` | O comandă redenumită/ștearsă lasă scheduler-ul să eșueze silențios pe minut; backups/monitoring moarte până observă cineva | Test care iterează `Schedule` și verifică că fiecare comandă/job referit există; smoke-test pe comenzile de backup |
| T-13 | P3 | ex. `DeleteSpamUsersJobTest.php:71,96`; `PostRestoreVerifierTest.php:56–60` | Assert-uri pe stringuri de mesaj („Deleted 1 of 1 spam users”, „cache cleared”) — cuplare la copy, nu la comportament | Refactor de mesaje sparge teste fără regresie reală → erodează încrederea în suită | Assert pe stare (DB/status) primar, mesaje secundar |
| T-14 | P3 | `phpstan.neon:6–7` | `parallel.maximumNumberOfProcesses: 1` — analiza rulează pe un singur proces | Analiză lentă local și în CI (minute în plus per rulare) → tentația de a o sări | În CI: eliminat limita (era probabil pentru RAM-ul VPS-ului) |
| T-15 | P3 | `phpunit.xml:16–20` | `<source>` e definit dar nu se colectează/raportează coverage nicăieri (niciun script, niciun CI) | Nu există nicio măsură obiectivă a progresului planului de testare | `--coverage-clover` în job-ul de CI + prag minim doar pe `app/Services/Backup` și `app/Jobs` critice (nu global) |
| T-16 | P3 | `tests/Feature/Services/RetentionServiceTest.php:30–35` | Testul de retenție folosește un `StorageDestination` local cu path în `sys_get_temp_dir()` dar nu creează fișierele — ștergerea fizică nu e exercitată, doar cea din DB | O regresie în `StorageFactory`/driver la delete (ex. path greșit) ar trece testul: DB curată, fișiere orfane pe S3 care umplu discul | Creat fișiere reale în setup și assert pe dispariția lor |

### Sinteză severități

| Severitate | Număr | ID-uri |
|---|---|---|
| P0 | 0 | — |
| P1 | 6 | T-01…T-06 |
| P2 | 7 | T-07…T-12, T-16* |
| P3 | 4 | T-13, T-14, T-15 (+T-16 dacă se consideră minor) |

\* T-16 clasificat P2 pentru că atinge ștergerea fizică a backup-urilor; numărătoarea finală folosită: **7×P2, 3×P3**.
