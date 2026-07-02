# 29 — Audit de consistență arhitecturală

**Data:** 2026-07-02 · **Auditor:** Claude (auditor senior Laravel/DevOps) · **Scope:** întreg working tree-ul, inclusiv codul necomis (export backup „Local by Flywheel") · **Metodă:** citire directă a codului; fiecare afirmație citează `path:line` verificat.

---

## Rezumat executiv

Aplicația are o arhitectură **mai disciplinată decât media** pentru un proiect intern crescut rapid: `declare(strict_types=1)` este prezent în **toate cele 497 fișiere PHP din `app/`** (verificat prin grep invers — 0 lipsuri), controllerele sunt subțiri (max. 151 linii — `app/Http/Controllers/DropboxAuthController.php`), modelele Site/Backup sunt zvelte, iar nucleul HTTP-către-WordPress este centralizat corect în `WordPressHttpClient` + `WordPressApiService` (fațadă din 9 trait-uri `Concerns/`, cu interfață și factory).

Problemele reale sunt concentrate în trei zone:

1. **Un canal HTTP paralel către site-urile WP, rupt funcțional** — `SiteSeoAudit` și `RunSeoAudit` apelează connectorul cu un header (`X-SAM-API-Key`) pe care pluginul **nu îl citește nicăieri**; autentificarea pluginului cere `X-SAM-Key` + `X-SAM-Timestamp` + `X-SAM-Signature`. Toate acțiunile „push SEO fix" (meta/robots/canonical/OG, bulk fix) eșuează silențios pe connector v2.14.0. (**P1, ARH-01**)
2. **Logica de business a celui mai periculos flux (backup/restore) trăiește în Jobs-uri gigant**, nu în servicii: `CreateBackup.php` are 1.195 linii și 4 pipeline-uri de upload; `RestoreBackup.php` are 876 linii. (**P1, ARH-02**)
3. **Duplicare prin copy-paste**: o funcție de 80 de linii identică în două locuri, `formatBytes()` definit de 7 ori, `match` de severitate→culoare duplicat în 3 sendere de notificări.

Vestea bună: cele 4 servicii suspectate de orfanaj (`OpenApiService`, `ModuleConfigService`, `BulkSettingsCopyService`, `CircuitBreakerService`) **sunt toate folosite real** — nu există cod orfan semnificativ în `app/Services/`.

**Bilanț: 0 × P0 · 2 × P1 · 9 × P2 · 5 × P3.**

---

## Disciplina stratului de servicii

Eșantion analizat: ~30 din cele 124 de servicii (`WordPressApiService`, `WordPressApiServiceFactory`, `WordPressHttpClient` + 9 `Concerns/`, `WordPressBackupDownloader`, `CloudflareService`, `GoogleApiService`, `GoogleAnalyticsService`, `GoogleSearchConsoleService`, `ReportGeneratorService`, `ReportDataGatherer`, `ReportRecommendationService`, `SafeUpdateService`, `PluginManagerService`, `SecurityScanService`, `CoreFileIntegrityService`, `BulkSettingsCopyService`, `ModuleConfigService`, `OpenApiService`, `SettingsService`, `CircuitBreakerService`, `JobTracker`, `ActivityLogger`, `HealthScoreService`, `StatusPageService`, `DashboardService`, senderele `Notifications/*`, `Backup/Storage/*`, `RetentionPolicyService`, `LocalFlywheelRepackager`).

**Nu există un contract comun.** Coexistă trei familii incompatibile:

**Familia A — servicii stateless cu DI prin constructor (stilul „corect", majoritar în zonele critice):**
```php
// app/Services/SafeUpdateService.php:17-21
public function __construct(
    protected RollbackService $rollbackService,
    protected WordPressApiServiceFactory $apiFactory,
    protected ScreenshotService $screenshotService,
) {}
```
La fel `PluginManagerService.php:18-20`, `SecurityScanService.php:18-20`, `CoreFileIntegrityService.php:15-16`, `BulkSettingsCopyService.php:14`. Pattern-ul `WordPressApiServiceFactory` (`app/Services/WordPressApiServiceFactory.php:12-15`) este folosit în 31 de fișiere și este singura cale prin care se construiește `WordPressApiService` (`new WordPressApiService` apare o singură dată, în factory — verificat prin grep). **Acesta este punctul cel mai matur al arhitecturii.**

**Familia B — servicii legate de model, instanțiate cu `new` inline, imposibil de injectat/mockat:**
```php
// app/Services/CloudflareService.php:16-18
public function __construct(
    private CloudflareConnection $connection,
) {}
```
`new CloudflareService($connection)` apare în **10 locuri** (`app/Livewire/Sites/Detail/SiteCloudflare.php:78,97,134,170,203,235`, `app/Livewire/Settings/IntegrationsSettings.php:445,474`, `app/Jobs/SyncCloudflareZone.php:48`, `app/Jobs/ValidateExternalConnections.php:90`, `app/Services/DnsSelectorDiscoveryService.php:94`). La fel `new GoogleAnalyticsService($google)` (`app/Jobs/FetchAnalyticsData.php:61`, `app/Livewire/Sites/Detail/SiteAnalytics.php:161,214`), `new GoogleSearchConsoleService($google)` (`app/Jobs/FetchSearchConsoleData.php:65`, `app/Jobs/FetchKeywordRankings.php:53`, `app/Livewire/Sites/Detail/SiteSearchConsole.php:180`), `new ReportGeneratorService(...)` (`app/Jobs/GenerateReport.php:116`, `app/Console/Commands/RegenerateReport.php:44`). Pentru `WordPressApiService` (aceeași formă — `__construct(protected Site $site)`, `WordPressApiService.php:37-41`) s-a construit un factory; pentru Cloudflare/Google nu — inconsecvență directă între servicii-frați.

**Familia C — servicii complet statice (utility classes deghizate):**
- `CircuitBreakerService` — 4 metode statice (`app/Services/CircuitBreakerService.php:22,45`)
- `JobTracker` — 8 metode statice (`app/Services/JobTracker.php:22,32,47`)
- `ActivityLogger` — 27 metode statice (`app/Services/ActivityLogger.php`)
- `HealthScoreService::calculate()` (`app/Services/HealthScoreService.php:11`), `StatusPageService` (5 statice, `app/Services/StatusPageService.php:16,48,70,174`)
- toate senderele de notificări: `SlackNotificationSender::send(...)` (`app/Services/Notifications/SlackNotificationSender.php:13-18`), identic Discord/Telegram/Webhook — **fără interfață comună**, deși au semnătură identică `(NotificationChannel, string, string, array, string): array`.

25 din 124 de servicii conțin `public static function` (grep). Nimic din Familia C nu poate fi înlocuit într-un test fără `Cache`/`Http` fakes globale.

**Interfețe:** există exact 4 în tot proiectul — `app/Contracts/WordPressApiServiceInterface.php`, `app/Contracts/ReportSectionGathererInterface.php`, `app/Services/Backup/Storage/StorageDriver.php`, `app/Services/IncidentResponse/Contracts/PlaybookInterface.php`. Toate patru sunt bine alese (exact punctele de polimorfism real), dar restul serviciilor cu I/O extern (Cloudflare, Google, Postmark, Gotenberg, PageSpeed) nu au niciuna.

**Verdict:** disciplina există pe axa WP-API (interfață + factory + client semnat) și pe axa Backup/Storage (interfață + factory), dar restul stratului de servicii e un amestec de trei stiluri fără regulă scrisă.

---

## Duplicare

### D1 — Canal HTTP-către-WP paralel, în afara `WordPressApiService`, și RUPT (P1)

Clientul canonic semnează fiecare request cu HMAC:
```php
// app/Services/WordPress/WordPressHttpClient.php:94
$signature = hash_hmac('sha256', $stringToSign, (string) $this->site->api_secret);
```
Pluginul **refuză** orice request fără triul de headere:
```php
// wordpress-plugin/simplead-manager-connector/includes/class-authentication.php:22-26
$api_key   = $request->get_header('X-SAM-Key');
$timestamp = $request->get_header('X-SAM-Timestamp');
$signature = $request->get_header('X-SAM-Signature');
if (empty($api_key) || empty($timestamp) || empty($signature)) {
```
și **toate** rutele SEO folosesc acest `check_permission` standard (`includes/endpoints/class-seo-endpoint.php:36-90` → `includes/class-rest-api.php:95-110`).

În paralel, managerul are 7 call-site-uri care ocolesc clientul semnat și trimit un header inventat:
```php
// app/Livewire/Sites/Detail/SiteSeoAudit.php:618-620 (identic la :508-510, :655, :690, :729, :759)
$response = \Illuminate\Support\Facades\Http::timeout(15)
    ->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])
    ->post(rtrim($this->site->url, '/').'/wp-json/simplead/v1/seo/update-meta', [...]);
```
```php
// app/Jobs/RunSeoAudit.php:46
$r = Http::timeout(15)->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])->get(...'/wp-json/simplead/v1/seo/analysis');
```
`grep -rn 'X-SAM-API-Key' wordpress-plugin/` → **0 rezultate**. Headerul nu există în plugin. Deci: push meta/robots/canonical/OG fix și `bulkFix()` din UI eșuează 100% (utilizatorul vede „Failed" fără să știe că e imposibil să reușească), iar `RunSeoAudit` pierde silențios îmbogățirea cu `seo_plugin`/`search_visibility`/`redirects` (are catch de degradare la `RunSeoAudit.php:66-68`). În plus, cheia API decriptată e trimisă într-un header pe care serverul îl ignoră — expunere inutilă.

### D2 — `gatherSiteDataForRecs()`: 80 de linii copy-paste identice (P2)

`app/Livewire/Traits/WithReportGeneration.php:160-239` și `app/Livewire/Sites/Detail/ReportRecommendationsManager.php:213-292` conțin aceeași funcție. Diff efectiv rulat pe cele două corpuri: **identice integral pe toate cele 80 de linii** (prima diferență apare abia la funcția următoare — `gatherSectionPreviews` vs `render`). Ambele hrănesc `new ReportRecommendationService($data, $language)` (`WithReportGeneration.php:68`, `ReportRecommendationsManager.php:46`), iar o a treia instanțiere trăiește în `app/Services/ReportDataGatherer.php:118`. Scenariu de eșec: se adaugă o sursă de date nouă într-o copie → recomandările „regenerate" din managerul de sugestii diverg de cele generate la crearea raportului, fără nicio eroare.

### D3 — `formatBytes()` definit de 7 ori (P2)

`app/Services/AppBackup/AppBackupHelpers.php:58`, `app/Services/Reports/BaseReportSectionGatherer.php:80`, `app/Livewire/Settings/ApplicationBackup.php:402`, `app/Livewire/Sites/Detail/SiteBackups.php:161`, `app/Models/StorageDestination.php:92`, `app/Models/PerformanceTest.php:227`, `app/Models/DatabaseHealthCheck.php:119`. Șapte implementări ale aceleiași formatări — divergență garantată la rotunjire/unități.

### D4 — `match` de severitate duplicat în senderele de notificări (P2)

```php
// app/Services/Notifications/SlackNotificationSender.php:25-30
$color = match ($severity) {
    'critical' => '#DC2626', 'warning' => '#EAB308', 'success' => '#16A34A', default => '#6B7280',
};
```
```php
// app/Services/Notifications/DiscordNotificationSender.php:25-30
$color = match ($severity) {
    'critical' => 0xDC2626, 'warning' => 0xEAB308, 'success' => 0x16A34A, default => 0x6B7280,
};
```
plus varianta emoji în `TelegramNotificationSender.php:35-40`. Aceleași valori, trei sintaxe; există deja `app/Enums/Severity.php` care ar putea deține maparea (cum face `BackupStatus::color()` — `app/Enums/BackupStatus.php`), dar senderele primesc `string $severity = 'warning'` (`SlackNotificationSender.php:18`), nu enum-ul.

### D5 — Pattern-ul „ia conexiunea → new CloudflareService → try/flash" repetat de 6 ori în aceeași componentă (P2, simptom al D-Familiei B)

`app/Livewire/Sites/Detail/SiteCloudflare.php:78, 97, 134, 170, 203, 235` — fiecare metodă publică reface manual: fetch `CloudflareConnection`/`siteCloudflare`, `new CloudflareService(...)`, `try { ... } catch { session()->flash('cf-error', ...) }`.

---

## Dimensiuni

### Top 10 fișiere `app/` (wc -l, verificat)

| Linii | Fișier | Observație |
|---|---|---|
| 1.195 | `app/Jobs/CreateBackup.php` | god object — vezi ARH-02 |
| 876 | `app/Jobs/RestoreBackup.php` | god object pe flux destructiv |
| 793 | `app/Livewire/Sites/Detail/SiteSeoAudit.php` | ~45 metode publice, HTTP inline (D1) |
| 769 | `app/Jobs/CrawlSitePages.php` | crawler întreg într-un Job |
| 707 | `app/Services/SeoAudit/ExcelExportService.php` | singurul serviciu >600 |
| 655 | `app/Jobs/AnalyzeSeoPages.php` | |
| 616 | `app/Jobs/CreateIncrementalBackup.php` | |
| 599 | `app/Livewire/Traits/WithReportGeneration.php` | trait-fantomă (vezi secțiunea Traits) |
| 528 | `app/Services/DashboardService.php` | |
| 516 | `app/Livewire/Sites/Detail/SitePerformance.php` | |

### Top 10 fișiere `wordpress-plugin/` (44 fișiere, 14.872 LOC total)

| Linii | Fișier |
|---|---|
| 2.690 | `includes/endpoints/class-backup-endpoint.php` |
| 1.109 | `includes/endpoints/class-seo-endpoint.php` |
| 818 | `includes/endpoints/class-database-endpoint.php` |
| 672 | `includes/class-content-media-tweaks.php` |
| 657 | `includes/class-mu-plugin-manager.php` |
| 579 | `includes/endpoints/class-security-endpoint.php` |
| 537 | `includes/class-security-hardening.php` |
| 512 | `includes/endpoints/class-plugins-endpoint.php` |
| 492 | `includes/class-performance-tweaks.php` |
| 456 | `includes/endpoints/class-diagnostic-endpoint.php` |

### Componente Livewire ≥400 linii (9)

`SiteSeoAudit` 793, `WithReportGeneration` (trait) 599, `SitePerformance` 516, `IntegrationsSettings` 515 (cu validare HTTP inline la `:362,405`), `SiteOverview` 459, `WithMaintenancePlanForm` (trait) 453, `ApplicationBackup` 421, `SitePlugins` 417 (apeluri `Http::` către wordpress.org la `:332,338`), `SiteAnalytics` 405.

### God objects — diagnostic

`CreateBackup` conține patru pipeline-uri complete de livrare (chunked download `:214`, arhivare `:373`, „V3 zip" `:448-541`, direct-upload cu multipart S3 `:663-1051`) plus verificare de integritate `:1066`, upload `:1090` și finalizare `:1110` — responsabilități care în restul aplicației ar fi servicii (`StreamingBackupUploader`, `IntegrityVerifier` există deja în `app/Services/Backup/`, dar jobul își păstrează propriile variante interne, ex. `verifyV3Zip()` la `:511` vs `app/Services/Backup/IntegrityVerifier.php`). Echivalentul din plugin, `class-backup-endpoint.php` (2.690 linii), suferă de aceeași boală în oglindă.

---

## Fat models / controllers

**Nu este o problemă aici — constatare pozitivă, cu o excepție punctuală.**

- `app/Models/Site.php` — **240 linii**, din care ~110 sunt docblock `@property`. Relațiile (40+) sunt extrase în `app/Models/Traits/HasSiteRelationships.php` (334 linii), scope-urile în `HasSiteScopes.php` (51), deci modelul „efectiv" e ~680 de linii bine compartimentate. **Excepția:** hook-ul `booted()` conține logică de business și side-effects — `DashboardService::invalidateCache()` la fiecare `saved`/`deleted` (`Site.php:196-202`, cuplaj model→serviciu static) și, la `created`, dispatch de job + aplicarea planului de mentenanță întreg (`Site.php:204-216`: `FetchSiteFavicon::dispatch($site)` + `app(ModuleConfigService::class)->applyPlan(...)`). Orice `Site::create()` din teste, seeders sau `BulkAddSites` declanșează crearea de monitoare/config-uri — comportament implicit greu de dezactivat.
- `app/Models/Backup.php` — **289 linii**, model exemplar: enum cast (`:132-134`), scope-uri (`:161-171`), accessors de prezentare care deleagă la enum (`:221-233`).
- Controllere: cel mai mare este `DropboxAuthController.php` cu 151 linii; media e sub 60. Grosimea UI e în Livewire, nu în controllere — consecvent cu convenția proiectului.

---

## Convenții

- **`strict_types`:** 497/497 fișiere în `app/` îl au (grep invers → 0 lipsuri). **100% conform.** Pluginul WP: 0/44 (țintă PHP 7.4, acceptabil, dar divergent de restul repo-ului).
- **Sufixul `Service`:** în rădăcina `app/Services/` doar 5 fișiere nu-l au: `ActivityLogger`, `JobTracker`, `ReportDataGatherer`, `WordPressApiServiceFactory`, `WordPressBackupDownloader` — nume rezonabile pentru rolurile lor. În `Services/Backup/` însă conviețuiesc `ManifestService` și `BackupManifestV3`, `RetentionService` și `IntegrityVerifier`/`DiskSpaceGuard`/`SqlDumpParser`/`BackupZipBuilder` — niciun criteriu vizibil pentru când un colaborator primește sufixul.
- **Enums:** 18 enum-uri în `app/Enums/`, referite din 78 de fișiere — adopție bună. Dar string-literale de status supraviețuiesc în paralel: `app/Jobs/CreateBackup.php:547` (`'status' => 'completed'` — funcționează doar datorită cast-ului), iar codul **necomis** introduce un status nou complet stringly-typed: `local_export_status` = `'processing'`/`'completed'`/`'failed'` (`app/Jobs/ExportBackupForLocal.php:71,114,148`) fără enum, deși `BackupStatus` există alături în același fișier (`:54`).
- **DTOs:** directorul `app/DTOs/` conține exact 2 clase (`DashboardStats`, `DashboardSummary`), folosite în 2 fișiere. Restul aplicației comunică prin array-shapes documentate în PHPDoc (ex. `PluginManagerService.php:24` — `@return array{success: bool, message: string, version: ?string}`) sau nedocumentate (senderele de notificări returnează `['success' =>, 'response_code' =>, 'error' =>]` prin convenție orală). „DTOs în `app/DTOs/`" din CLAUDE.md este aspirațional, nu real.
- **Form Requests:** regula din `.claude/rules/laravel.md` e moot — validarea trăiește în Livewire, controllerele n-au aproape deloc input de validat.

---

## Cod orfan

Toate cele 4 servicii suspectate sunt **folosite real** (grep pe `app/`, `routes/`, `config/`, `resources/`, `tests/`, `bootstrap/`):

| Serviciu | Verdict | Dovezi |
|---|---|---|
| `OpenApiService` | **folosit** (lookup CUI firme RO, cache 24h + rate limit — `app/Services/OpenApiService.php:17-44`) | `app/Livewire/Clients/ClientForm.php`, `app/Livewire/Settings/IntegrationsSettings.php` |
| `ModuleConfigService` | **folosit intens** — 10 referințe | `app/Models/Site.php:214`, `app/Services/MaintenancePlanService.php`, `app/Livewire/Sites/BulkSettings.php`, `SiteSettings.php`, `MaintenancePlans.php`, `resources/views/components/sidebar/site-sidebar.blade.php` ș.a. |
| `BulkSettingsCopyService` | **folosit** | `app/Livewire/Components/CopySettingsModal.php`, `app/Livewire/Sites/BulkSettings.php` |
| `CircuitBreakerService` | **folosit intens** — 18 referințe | toate dispatcherele (`app/Dispatchers/*.php`), joburile de sync/backup/SEO, `app/Livewire/Backups/BackupsOverview.php` |

Nu am identificat servicii moarte în eșantion. Singurul „aproape-orfan" funcțional este perechea SEO-push descrisă la D1: codul există și e viu în UI, dar nu poate reuși niciodată — cod mort de facto.

---

## Traits Livewire

`app/Livewire/Traits/` conține 21 de trait-uri, 3.277 linii total. Numărând consumatorii fiecăruia (grep pe `use ...Trait;` în afara directorului `Traits/`), se despart în două categorii:

**Reutilizare reală (legitime):** `WithSiteAuthorization` — 31 consumatori, `WithSorting` — 9, `WithJobTracking` — 9, `WithTableFilters` — 3, `WithWpAdminLogin` — 2.

**File-splitting deghizat — exact 1 consumator (opt trait-uri, ~2.600 linii):**

| Trait | Linii | Unicul consumator |
|---|---|---|
| `WithReportGeneration` | 599 | `Sites/Detail/SiteReports.php` (92 linii!) |
| `WithMaintenancePlanForm` | 453 | `MaintenancePlans.php` |
| `WithBackupActions` | 374 | `Sites/Detail/SiteBackups.php` (176 linii) |
| `WithTemplateForm` | 341 | 1 |
| `WithPluginManagement` | 189 | 1 |
| `WithBulkSiteActions` | 119 | 1 |
| `WithBackupProgress` | 111 | 1 |
| `WithReportScheduling` | 103 | 1 |

Ce ascund efectiv:
- `WithReportGeneration` ascunde o **a doua implementare a secțiunilor de raport**: 15 metode `preview*()` (`WithReportGeneration.php:277-573` — `previewOverview`, `previewUptime`, `previewBackups`...) care refac în miniatură munca gatherer-elor din `app/Services/Reports/Sections/` (19 clase cu interfață dedicată). Plus copia integrală `gatherSiteDataForRecs()` (D2). Componenta gazdă `SiteReports.php` are 92 de linii — trait-ul E componenta.
- `WithBackupActions` (modificat necomis) ascunde orchestrare de operațiuni distructive — dispatch backup full/incremental, delete, bulk delete, cancel, export Local (`WithBackupActions.php:19,66,113,181,213,287,345`) — logică ce în alte module ar sta într-un serviciu.
- `WithMaintenancePlanForm` ascunde construirea structurilor de setări de securitate/tweaks (`:321,346`) — business logic pură, fără nicio dependență de Livewire.

Efect practic: statistica „componente Livewire sub 400 de linii" e parțial cosmetică — `SiteBackups` real = 176 + 374 + 111 (WithBackupProgress) ≈ 660 de linii de comportament.

---

## Structura wordpress-plugin

44 fișiere PHP, 14.872 LOC, v2.14.0 (header `Version:` și `SAM_VERSION` sincronizate — `simplead-manager-connector.php:6,20`, conform convenției din CLAUDE.md).

**Puncte solide:**
- Clasă de bază unică pentru toate endpointurile, cu lanț de gardă consecvent IP whitelist → rate limiter → HMAC (`includes/class-rest-api.php:88-110`) și helpers `success()`/`error()` uniforme (`:115-125`). Toate cele 23 de endpointuri verificate prin eșantion folosesc `[$this, 'check_permission']` (ex. `class-seo-endpoint.php:36-90`).
- Încărcare în două trepte rezonabilă: `require_once` doar pentru cele 10 clase care înregistrează hooks la boot (`simplead-manager-connector.php:28-38`), restul prin `spl_autoload_register` (`:41`).

**Puncte slabe:**
- Autoloader-ul e o **hartă statică scrisă de mână** (`simplead-manager-connector.php:42-80`) — fiecare clasă nouă cere o intrare manuală; o omisiune = fatal error la runtime pe site-ul clientului, nu la CI (care oricum nu există).
- `class-backup-endpoint.php` — 2.690 linii, god object în oglindă cu `CreateBackup.php` din manager: pregătire, chunking, manifest, sesiuni, direct-upload, toate într-o clasă.
- Fără `strict_types`, fără namespace-uri (prefix `SAM_` — justificabil pentru compatibilitate WP/PHP 7.4, dar înseamnă două dialecte PHP în același repo).
- **Duplicare conceptuală cu managerul**, nu textuală: formatul manifestului v3 e construit în plugin (`class-backup-endpoint.php:115-121,443` — `generate_manifest`, `download_session_manifest`) și interpretat în manager (`app/Services/Backup/BackupManifestV3.php:53-98`), fără nicio definiție comună a schemei (nici măcar un fișier de constante partajat); orice schimbare cere modificare sincronizată în două limbaje de organizare diferite, cu versiuni de plugin desincronizate în flotă.

---

## Direcție arhitecturală recomandată

1. **Rutați TOT traficul către connector prin `WordPressApiService`** (efort: mic-mediu, 1-2 zile). Adăugați un concern `ManagesSeo` în `app/Services/WordPress/Concerns/` cu `updateMeta()/updateRobots()/updateCanonical()/updateOg()/getSeoAnalysis()`, înlocuiți cele 7 call-site-uri `Http::...X-SAM-API-Key` din `SiteSeoAudit.php` și `RunSeoAudit.php`. Repară feature-ul mort ȘI închide canalul paralel nesemnat. Adăugați apoi o regulă PHPStan/pint-custom sau măcar o notă în CLAUDE.md: „`Http::` către `$site->url` este interzis în afara `WordPressHttpClient`".
2. **Extrageți pipeline-urile din `CreateBackup`/`RestoreBackup` în servicii** (efort: mare, 1-2 săptămâni, incremental). Jobul rămâne orchestrator subțire (stare, retry, JobTracker); pipeline-urile (chunked/v3-zip/direct-upload) devin clase în `app/Services/Backup/Pipelines/` cu un contract comun, testabile unitar. Structura există deja pe jumătate (`StreamingBackupUploader`, `IntegrityVerifier`, `BackupZipBuilder`) — trebuie doar ca jobul să le folosească în loc să-și păstreze variantele interne.
3. **Uniformizați Familia B pe modelul WP-API** (efort: mic, 1-2 zile): `CloudflareServiceFactory`, `GoogleAnalyticsServiceFactory` (sau o metodă `forConnection()` pe serviciu, înregistrată în container). Elimină cele ~15 `new *Service($connection)` din Livewire/Jobs și deblochează mock-uirea în testele Livewire existente.
4. **Dizolvați trait-urile cu un singur consumator** (efort: mediu, incremental): logica de business din `WithReportGeneration`/`WithMaintenancePlanForm`/`WithBackupActions` coboară în servicii (`ReportPreviewService` care refolosește gatherer-ele din `Reports/Sections/`, `MaintenancePlanService` existent, `BackupActionService`); trait-ul păstrează doar proprietăți Livewire + delegare. Elimină simultan D2.
5. **Un singur helper de formatare + enum-uri pentru toate statusurile noi** (efort: foarte mic, o zi): `Number::fileSize()` din Laravel (există deja în framework) în locul celor 7 `formatBytes()`; enum `LocalExportStatus` (sau reutilizare `BackupStatus`) pentru câmpurile din migrarea necomisă înainte de a o comite — după comitere costul dublează.

---

## Constatări

| ID | Sev. | Fișiere:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| ARH-01 | **P1** | `app/Livewire/Sites/Detail/SiteSeoAudit.php:508-510,618-620,655,690,729,759`; `app/Jobs/RunSeoAudit.php:46`; `wordpress-plugin/.../includes/class-authentication.php:22-26`; `includes/class-rest-api.php:95-110` | Canal HTTP paralel către connector cu header `X-SAM-API-Key` inexistent în plugin (auth cere `X-SAM-Key`+`Timestamp`+`Signature`); ocolește `WordPressHttpClient` (semnare HMAC la `WordPressHttpClient.php:94`) | Operatorul apasă „push meta fix"/„bulk fix" pe un site live → toate cererile primesc 401/403; UI arată „Failed" fără cauză; `RunSeoAudit` pierde silențios datele de la connector; api_key decriptat e trimis într-un header ignorat de server | Concern `ManagesSeo` pe `WordPressApiService`; înlocuire call-site-uri; interzis `Http::` către `$site->url` în afara clientului |
| ARH-02 | **P1** | `app/Jobs/CreateBackup.php` (1.195 linii, 4 pipeline-uri: `:214,373,448,663`), `app/Jobs/RestoreBackup.php` (876), `app/Jobs/CreateIncrementalBackup.php` (616) | Logica de business a celui mai destructiv flux trăiește în Jobs-uri god-object, cu variante interne duplicate ale serviciilor existente (ex. `verifyV3Zip` la `CreateBackup.php:511` vs `Services/Backup/IntegrityVerifier.php`) | Orice modificare la un pipeline (ex. direct-upload) trece prin același fișier cu finalize-ul celorlalte trei; o regresie la finalize marchează backup-uri corupte drept `completed` → descoperire abia la restore pe site live | Joburi = orchestratori; pipeline-uri extrase în `Services/Backup/Pipelines/` cu contract comun + teste unitare |
| ARH-03 | P2 | `app/Livewire/Traits/WithReportGeneration.php:160-239`; `app/Livewire/Sites/Detail/ReportRecommendationsManager.php:213-292` | `gatherSiteDataForRecs()` — 80 linii identice copy-paste (diff rulat: 0 diferențe) | O sursă de date nouă adăugată doar într-o copie → recomandările regenerate din UI diverg de cele din raportul generat, silențios | Extragere în serviciu unic (`ReportRecommendationDataService`) |
| ARH-04 | P2 | `app/Livewire/Sites/Detail/SiteCloudflare.php:78,97,134,170,203,235`; `app/Jobs/FetchAnalyticsData.php:61`; `app/Jobs/FetchSearchConsoleData.php:65`; `app/Livewire/Sites/Detail/SiteAnalytics.php:161,214` ș.a. | Servicii model-bound (`CloudflareService`, `GoogleAnalyticsService`, `GoogleSearchConsoleService`) instanțiate cu `new` în ~15 locuri; inconsecvent cu pattern-ul factory folosit pentru `WordPressApiService` (31 consumatori de factory) | Imposibil de mockat în teste Livewire; schimbarea constructorului = 15 fișiere atinse; testele lovesc API-uri reale sau se bazează pe `Http::fake` global | Factory-uri sau `forConnection()` înregistrate în container |
| ARH-05 | P2 | `app/Services/Notifications/SlackNotificationSender.php:13-30`; `DiscordNotificationSender.php:13-30`; `TelegramNotificationSender.php:15-40`; `WebhookNotificationSender.php` | Sendere statice cu semnătură identică dar fără interfață; `match` severitate→culoare duplicat de 3 ori cu aceleași valori | Adăugarea severității `info` cere 3+ edit-uri sincronizate; un kanал uitat afișează culoarea default fără eroare | Interfață `NotificationSenderInterface` + maparea culorilor în `Enums/Severity` (modelul `BackupStatus::color()` există deja) |
| ARH-06 | P2 | `app/Livewire/Traits/` — `WithReportGeneration` (599), `WithMaintenancePlanForm` (453), `WithBackupActions` (374), `WithTemplateForm` (341) + încă 4, toate cu **exact 1 consumator** | Trait-uri folosite ca file-splitting, nu reuse; ascund business logic (inclusiv acțiuni distructive de backup) în stratul UI | `SiteBackups` „are 176 linii" dar comportamental ~660; logica din trait nu e testabilă fără componenta gazdă; recenziile de cod subestimează raza de impact | Coborâre logică în servicii; trait-ul păstrează doar state + delegare |
| ARH-07 | P2 | `app/Livewire/Traits/WithReportGeneration.php:277-573`; `app/Services/Reports/Sections/*` (19 clase) | 15 metode `preview*()` reimplementează în paralel secțiunile de raport acoperite de gatherer-ele cu interfață dedicată (`Contracts/ReportSectionGathererInterface.php`) | Preview-ul din wizard arată alte cifre decât raportul final (surse/agregări diferite) → clientul primește PDF diferit de ce a văzut operatorul | Preview = gatherer-ele reale cu flag `preview`/limită de date |
| ARH-08 | P2 | `app/Models/Site.php:196-216` | Side-effects de business în hook-uri de model: invalidare cache dashboard la fiecare `saved`, dispatch `FetchSiteFavicon` + `ModuleConfigService::applyPlan()` la `created` | `Site::factory()->create()` în orice test/seeder/import bulk creează monitoare și dispatch-uiește joburi; comportament imposibil de dezactivat selectiv; cuplaj model→serviciu static | Mutare în `SiteObserver` explicit sau în serviciul de creare site (`ManageSites`/Livewire), lăsând modelul pasiv |
| ARH-09 | P2 | `app/Livewire/Sites/Detail/SiteSeoAudit.php` (793, ~45 metode); `IntegrationsSettings.php` (515, HTTP inline `:362,405`); `SitePlugins.php` (417, HTTP `:332,338`); `SitePerformance.php` (516) | Componente Livewire god-object cu I/O extern inline, contrar propriei convenții „Services handle business logic" | Orice schimbare UI atinge fișierul care conține și integrarea externă; imposibil de testat fără `Http::fake` pe tot | Extragere I/O în servicii; țintă <400 linii/componentă (prag deja implicit în cod) |
| ARH-10 | P2 | `wordpress-plugin/.../includes/endpoints/class-backup-endpoint.php` (2.690 linii); `app/Services/Backup/BackupManifestV3.php:53-98` | God object în plugin, oglindă a ARH-02; formatul manifestului v3 definit implicit în două codebase-uri fără schemă comună | O schimbare de câmp în manifest cere release sincronizat plugin+manager; flota rulează versiuni mixte → restore-ul citește manifest într-un format neașteptat | Spargere pe clase per-flux în plugin; document de schemă versionat (`manifest-v3.md` sau constante partajate prin generator) |
| ARH-11 | P2 | `app/Services/CircuitBreakerService.php`, `JobTracker.php`, `ActivityLogger.php`, `HealthScoreService.php`, `StatusPageService.php`, `Services/Notifications/*` | A treia familie de servicii: complet statice (25/124 servicii au metode statice) — trei stiluri de contract fără regulă scrisă | Cod nou copiază stilul fișierului vecin → divergența crește; serviciile statice nu pot fi înlocuite în teste | Regulă în CLAUDE.md: instanță+DI implicit; static doar pentru utilitare pure fără I/O |
| ARH-12 | P3 | `app/Jobs/ExportBackupForLocal.php:71,114,148` (necomis); `app/Jobs/CreateBackup.php:547` | Statusuri stringly-typed (`'processing'`/`'completed'`/`'failed'`) deși `BackupStatus` e importat în același fișier (`ExportBackupForLocal.php:7`); 18 enum-uri există dar nu se aplică la câmpuri noi | Typo `'complete'` vs `'completed'` trece de PHPStan și de review; UI-ul de poll nu mai vede niciodată exportul gata | Enum `LocalExportStatus` + cast în model **înainte** de comiterea migrării `2026_06_08_120000` |
| ARH-13 | P3 | `app/Services/AppBackup/AppBackupHelpers.php:58` + 6 alte locații (vezi D3) | `formatBytes()` definit de 7 ori (servicii, modele, Livewire) | Rotunjiri/unități diferite între ecrane pentru același fișier | `Number::fileSize()` (nativ Laravel) peste tot |
| ARH-14 | P3 | `app/DTOs/` (2 clase, 2 consumatori); ex. contract oral: `app/Services/Notifications/SlackNotificationSender.php` return `['success'=>,...]` | DTO-urile promise de structura proiectului sunt practic inexistente; array-shapes prin convenție orală | Cheie redenumită într-un producer → consumer citește `null` fără eroare (PHPStan L5 nu prinde toate array-shapes nedeclarate) | Măcar `@return array{...}` obligatoriu (există precedent: `PluginManagerService.php:24`); DTO-uri pentru payload-uri cross-modul |
| ARH-15 | P3 | `app/Services/Backup/` — `ManifestService` vs `BackupManifestV3`, `RetentionService` vs `IntegrityVerifier`/`DiskSpaceGuard`/`BackupZipBuilder`; rădăcină: `ActivityLogger`, `JobTracker` etc. | Sufixul `Service` aplicat fără criteriu în interiorul aceluiași subdirector | Cost de navigare/căutare; incertitudine la naming pentru cod nou | Convenție scrisă: sufix doar pentru fațade de modul; colaboratorii poartă nume de rol |
| ARH-16 | P3 | `wordpress-plugin/.../simplead-manager-connector.php:42-80`; 0/44 fișiere cu `strict_types` | Autoloader cu hartă statică manuală; dialect PHP diferit de manager (fără strict_types/namespaces — justificat de ținta PHP 7.4, dar nedocumentat) | Clasă nouă fără intrare în hartă → fatal error direct pe site-ul clientului (nu există CI care să-l prindă) | Generator de hartă la build sau convenție nume-clasa→fișier + fallback; notă de dialect în CLAUDE.md |

**Contori: P0 = 0 · P1 = 2 · P2 = 9 · P3 = 5.**
