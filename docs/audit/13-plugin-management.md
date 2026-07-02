# 13 — Plugin Management (update-uri/push-uri pe site-uri live)

**Data:** 2026-07-02 · **Auditor:** Claude (audit Faza 1) · **Scope:** `PluginManagerService`, `SafeUpdateService`, `RollbackService` (partajat cu modulul 12), `PluginConflictService`, `PluginRiskAssessmentService`, `PluginAbandonmentService`, `Jobs/RunSafeUpdate`, `Jobs/CheckPluginVulnerabilities`, `Livewire/Updates/UpdatesOverview`, `Livewire/Sites/Detail/SitePlugins` + trait-urile `WithPluginManagement`/`WithThemeManagement`, endpoint-urile connector WP (plugins/themes/core/rollback).

---

## Rezumat executiv

1. **„Safe Update" nu e safe și nici măcar nu actualizează.** `SafeUpdateService::runSafeUpdate()` ignoră complet rezultatul apelului de update (`app/Services/SafeUpdateService.php:62-67`) și, pentru plugin-uri, trimite **slug-ul** în loc de **plugin file** — connector-ul WP validează pe file path și răspunde „Invalid plugin path" (`wordpress-plugin/simplead-manager-connector/includes/endpoints/class-plugins-endpoint.php:96-97,217-222`). Serviciul scrie apoi `UpdateLog` cu `success => true` necondiționat (`SafeUpdateService.php:78-88`) și marchează update-ul `completed`. Singurul consumator e remedierea AI de incidente (`IncidentActionExecutor.php:185-195`): o „patch-uire" de vulnerabilitate raportată ca reușită **nu se întâmplă niciodată** (PM-P0-1).
2. **Fluxul real de update din UI nu are nicio plasă de siguranță.** `UpdatesOverview` și `SitePlugins` apelează direct `PluginManagerService` — fără backup garantat, fără health check post-update, fără rollback point, sincron în request-ul Livewire, inclusiv „Update all sites" dintr-un click (PM-P0-2). Pipeline-ul cu screenshot/health-check/auto-rollback există dar e cod mort pentru operatori.
3. **Recuperarea e stricată:** butonul de rollback caută `UpdateLog` cu `where('name', $plugin->slug)`, dar log-urile de update sunt scrise cu numele afișat → „No previous version found" aproape întotdeauna (PM-P1-1).
4. **Authz incomplet:** majoritatea acțiunilor distructive Livewire (update/activate/deactivate/delete plugin & theme, bulk update, update core) nu apelează `authorizeSiteModification` — un utilizator „viewer" le poate executa (PM-P1-2); `updatePluginAcrossSites` sare peste `canAccessSite` per site (PM-P1-3).
5. **`auto_update` e un toggle mort**: nu există niciun consumator backend și flag-ul e resetat la `false` la fiecare sync (PM-P1-4). Timeout-ul HTTP de 30s pe apelurile de update (inclusiv core) garantează false eșecuri și drift de inventar (PM-P1-5). „Backup before updates" e dispatch async — update-ul pornește înainte ca backup-ul să existe (PM-P1-6).
6. Testele existente (3 fișiere, 21 teste) mock-uiesc API-ul cu orice identificator și maschează exact bug-urile de mai sus.

---

## Inventar & corectitudine

**Ce face de fapt modulul:**

- **Inventar**: `Jobs/SyncWordPressSite.php` (coada `sync`, `tries=3`, `timeout=120`, `ShouldBeUnique`) trage plugin-uri/teme/useri de la connector și face `updateOrCreate` + ștergere hard a intrărilor dispărute (`SyncWordPressSite.php:74-136`). Declanșat de `DataSyncDispatcher` la fiecare minut, dar per site doar dacă `last_synced_at <= now-6h` (`app/Dispatchers/DataSyncDispatcher.php:83-97`) sau manual (`SitePlugins::syncNow`, rate-limited 10/h). **Prospețimea inventarului: până la 6 ore vechime**; mitigat parțial de connector, care re-validează la update („already up to date" — `class-plugins-endpoint.php:243-259`).
- **Update-uri**: `PluginManagerService` (update single/bulk plugin/theme/core, activate/deactivate/delete) → connector prin `ManagesPlugins`/`ManagesThemes`/`ManagesSiteInfo`. UI: `UpdatesOverview` (vedere globală cross-site) și `SitePlugins` (per site).
- **Vizibilitate pre-update**: da — UI arată per rând `v{versiune curentă} → v{versiune țintă}` (`resources/views/livewire/updates/updates-overview.blade.php:139`) și `wire:confirm` pe operațiile bulk (`:99,:109`). Update-ul individual nu are confirmare (acceptabil).
- **Risk assessment** (`PluginRiskAssessmentService`): trimite changelog + metadate wp.org la API-ul Anthropic și primește scor 0-100. **Doar on-demand** (butonul `assessRisk` din `SitePlugins.php:360-384`), rezultatul nu e persistat și **nu condiționează nimic** în fluxul de update. Coloanele `ai_risk_score`/`ai_risk_assessment` din `safe_updates` (schema `pgsql-schema.sql:2298-2299`) nu au niciun writer/reader — **coloane moarte** (verificat prin grep pe tot `app/`).
- **Conflicte** (`PluginConflictService::checkSite`, apelat din `SyncWordPressSite.php:207`): DB de conflicte cunoscute + 3 categorii hardcodate de funcționalitate duplicată. Rezultatele se persistă în `site_plugin_conflicts` și se notifică, dar **nu există nicio suprafață UI** care să le afișeze, iar `dismiss()` (`PluginConflictService.php:141-144`) nu are apelanți (verificat prin grep). Feature pe jumătate.
- **Abandon** (`PluginAbandonmentService`): verifică wp.org (closed / neactualizat >2 ani); folosit în detaliile de plugin. `sleep(1)` per plugin într-un loop sincron (`PluginAbandonmentService.php:113-116`).
- **Vulnerabilități** (`Jobs/CheckPluginVulnerabilities` + `VulnerabilityCheckService`): feed Wordfence Intelligence (`VulnerabilityCheckService.php:133`), zilnic la 05:00 (`routes/console.php:161-165`).

**Cod mort / feature-uri pe jumătate:**

| Element | Dovadă | Stare |
|---|---|---|
| `Jobs/RunSafeUpdate` | grep pe tot `app/`+`routes/`: zero dispatch-uri | **niciodată dispatch-uit**; `failed()` cu notificare `safe_update_failed` (`RunSafeUpdate.php:41-63`) nu se poate declanșa |
| `SafeUpdate` (model + pipeline) | folosit doar de `IncidentActionExecutor.php:185-189` (sincron) | fără UI, fără flux operator |
| `RollbackPoint` / `RollbackService::getAvailablePoints` | grep în `app/Livewire` + `resources/views`: zero referințe | puncte create la fiecare safe update, invizibile în UI |
| `auto_update` pe `SitePlugin`/`SiteTheme` | vezi PM-P1-4 | toggle UI fără consumator; resetat de sync |
| `ai_risk_score`/`ai_risk_assessment` | zero referințe în cod | coloane moarte |
| `'developer-developer'` în lista Backup | `PluginConflictService.php:17` | slug inexistent pe wp.org — probabil typo/placeholder |

TODO-uri explicite: nu am găsit `TODO`/`FIXME` în fișierele modulului.

---

## Siguranța operațiilor distructive

Aceasta e cea mai slabă zonă a modulului.

**Fluxul real de update (ce rulează efectiv când operatorul apasă „Update"):**

- `UpdatesOverview::updateSingle` (`UpdatesOverview.php:136-180`), `updateAllForSite` (`:182-228`), `updatePluginAcrossSites` (`:230-297`) și `SitePlugins`/`WithPluginManagement::updatePlugin` (`WithPluginManagement.php:18-42`) apelează toate `PluginManagerService::performUpdate` direct:
  - **Fără backup** în toate căile din `UpdatesOverview` (nicio referință la backup în fișier).
  - **Fără health check post-update** — dacă update-ul aruncă un fatal PHP pe site (whitescreen), nimic nu detectează; site-ul rămâne stricat până când uptime-monitorul (modulul 15) sau un client sună.
  - **Fără rollback point** — `RollbackService::createRollbackPoint` e apelat doar din `SafeUpdateService.php:70-76`, care nu e în flux.
  - **Execuție sincronă în request-ul Livewire**: `updateAllForSite` face N apeluri HTTP seriale către site (30s timeout fiecare) în același request PHP-FPM; `updatePluginAcrossSites` face M site-uri serial. La 10+ site-uri, request-ul depășește orice `max_execution_time` rezonabil și moare **la mijloc** — jumătate din flotă actualizată, fără raport final, fără posibilitate de reluare idempotentă.
- `runPreUpdateBackup()` (`WithPluginManagement.php:182-188`, la fel `WithThemeManagement.php:115`) — vezi PM-P1-6: `CreateBackup::dispatch(...)` **async**, deci `bulkUpdatePlugins` (`WithPluginManagement.php:102-110`) continuă imediat cu update-ul; backup-ul „pre_update" rulează în paralel sau după, și e doar `'database'`, deși update-urile de plugin modifică fișiere.

**SafeUpdateService, citit rând cu rând** (`app/Services/SafeUpdateService.php`):

1. Backup: `CreateBackup::dispatchSync(...)` (`:54`) — sincron, corect, dar doar dacă există `backupConfig`; fără config → merge mai departe **silențios fără backup**.
2. Update: `$updateResult = match(...)` (`:62-67`) — **valoarea nu e verificată nicăieri**; pentru `'plugin'` trimite `$safeUpdate->slug` deși `ManagesPlugins::updatePlugins(array $pluginFiles)` (`ManagesPlugins.php:17`) și endpoint-ul WP așteaptă plugin file (`class-plugins-endpoint.php:96-97` validează `isset($all_plugins[$plugin_file])`, cheile fiind `dir/file.php`). Update-ul de plugin **eșuează întotdeauna** cu „Invalid plugin path", nedetectat.
3. `UpdateLog` cu `success => true` necondiționat (`:78-88`) — audit trail fals.
4. Rollback point creat **după** update (`:70-76`) chiar dacă update-ul a eșuat.
5. Health check + visual diff (`:91-101`, prag 15%) — verifică un site pe care **nu s-a schimbat nimic** → trece → status `completed`.
6. `auto_rollback` (`:119-123`, default DB `true` — `pgsql-schema.sql:2290`) face rollback la `from_version` — pentru un update care nu s-a aplicat, „rollback-ul" reinstalează versiunea deja instalată.

**Idempotență / locking:**

- Nu există **niciun lock la nivel de site** pentru update-uri: doi operatori (sau un dublu-click după un fals „failed" cauzat de timeout) pot rula simultan `Plugin_Upgrader` pe același site → directoare de plugin pe jumătate suprascrise. `RunSafeUpdate` are `ShouldBeUnique` pe id-ul safe-update-ului (`RunSafeUpdate.php:31-34`), nu pe site — și oricum nu e folosit.
- Nimic nu împiedică un update simultan cu un restore de backup pe același site (lock-ul de restore e în modulul 12 și nu e consultat aici).
- `RollbackService::executeRollback` (`RollbackService.php:32-58`) nu verifică statusul punctului — un punct `used`/`expired` poate fi re-executat; marchează `used` (`:40`) înainte să se confirme succesul; `success => $result['success'] ?? true` (`:50`) presupune succes by default.

**Ce se întâmplă dacă update-ul moare la mijloc:**

- Endpoint-urile WP de update plugin/theme/core **nu apelează `set_time_limit`** (grep gol pe `class-plugins-endpoint.php`, `class-themes-endpoint.php`, `class-core-endpoint.php`; doar rollback are `@set_time_limit(300)` la `class-rollback-endpoint.php:41`). Pe hosting cu `max_execution_time` mic, `Core_Upgrader`/`Plugin_Upgrader` poate fi ucis mid-copy → fișiere pe jumătate scrise. `.maintenance` expiră automat după 10 min (comportament WP standard), dar un core pe jumătate copiat = site mort, iar managerul **nu face niciun health check** ca să afle.
- Pe partea manager: timeout la 30s (`WordPressApiService.php:78`, `WordPressHttpClient.php:126`; retry doar pe 429 — `:134`), deci pentru core update (1-3 min tipic) managerul raportează eroare deși WP continuă upgrade-ul → inventar divergent, operatorul reîncearcă → concurență pe upgrader.

**Audit logging cine-a-făcut-ce:**

- `UpdateLog` are `user_id` populat cu `auth()->id()` în toate căile din `PluginManagerService` (`:47,278,354,404`) — bun.
- Excepție: `WithPluginManagement::rollbackPlugin` creează `UpdateLog` **fără `user_id`** (`WithPluginManagement.php:162-170`) — rollback-ul (operație distructivă) e neatribuit.
- `ActivityLogger` acoperă activate/deactivate/delete/core, dar **nu și update-urile de plugin/theme** (doar UpdateLog, care are UI în tab-ul History — `SitePlugins.php:186-203`).

---

## Securitate

**Entry points și authz (verificat individual):**

| Entry point | Authz | Verdict |
|---|---|---|
| Ruta `/updates` (`routes/web.php:150`) | grupul `auth` global | OK (autentificat), dar componenta expune date global |
| `UpdatesOverview::updateSingle` | `authorizeSiteModification` (`UpdatesOverview.php:152`) | OK |
| `UpdatesOverview::updateAllForSite` | `authorizeSiteModification` (`:192`) + rate limit 5/h (`:184-189`) | OK |
| `UpdatesOverview::updatePluginAcrossSites` | **doar `isViewer()`** (`:232-234`); niciun `canAccessSite` per site | **DEFECT** (PM-P1-3) |
| `UpdatesOverview` — listele/stats | interogări globale `SitePlugin::where('has_update', true)` (`:31,:57`) fără scope pe user | expune inventarul tuturor site-urilor oricărui user autentificat (inclusiv viewer) |
| `SitePlugins::mount` | `authorizeSiteAccess` (`SitePlugins.php:61`) — doar **acces de citire** | insuficient pentru acțiunile de mai jos |
| `WithPluginManagement::updatePlugin/activatePlugin/deactivatePlugin/deletePlugin/deletePluginDirect/bulkUpdatePlugins` | **nimic** peste mount (`WithPluginManagement.php:18-110`) | **DEFECT** (PM-P1-2) — viewer poate șterge un plugin de pe site live |
| `WithThemeManagement::updateTheme/activateTheme/deleteTheme*/bulkUpdateThemes` | **nimic** (`WithThemeManagement.php:18-121`) | **DEFECT** (PM-P1-2) |
| `SitePlugins::updateCore` | **nimic** (`SitePlugins.php:249-259`) | **DEFECT** (PM-P1-2) |
| `SitePlugins::toggleAutoUpdate`, `assessRisk`, `fetchChangelog` | nimic | toggle scriere fără authz; restul read-only extern |
| `WithPluginManagement::updateLicense`, `rollbackPlugin` | `authorizeSiteModification` (`:124,:138`) | OK — dovada că omisiunea din restul e accidentală |
| `Jobs/RunSafeUpdate`, `CheckPluginVulnerabilities`, `SyncWordPressSite` | doar scheduler/servicii interne | OK |

- **Mass assignment**: toate scrierile folosesc liste explicite de atribute; `SitePlugin::$fillable` include `license_key` dar scrierea trece prin `updateLicense` cu authz. Fără defecte găsite.
- **SSRF**: URL-urile externe fetch-uite sunt fixe (`api.wordpress.org` — `SitePlugins.php:332,338`, `PluginRiskAssessmentService.php:81,129,146`; Wordfence — `VulnerabilityCheckService.php:133`). Slug-ul e interpolat doar în path-ul wp.org, nu în host. OK.
- **Injecții**: căutările Livewire escapează `%_\` pentru `ilike` (`UpdatesOverview.php:304-307`, `SitePlugins.php:134-137`). Pe connector, `validate_plugin_path` blochează traversal/absolute/null-byte și validează contra listei reale de plugin-uri (`class-plugins-endpoint.php:75-98`) — solid. La rollback, `slug`/`version` sanitizate intră în URL-ul de download wp.org (`class-rollback-endpoint.php:71`) — apelabil doar cu HMAC valid; risc rezidual mic.
- **Secrete în loguri**: cheile de licență sunt mascate la sursă, connector-ul trimite doar ultimele 8 caractere (`class-plugins-endpoint.php:505-508`). Cheia API Anthropic vine din `config()` (`PluginRiskAssessmentService.php:71`). Mesajele de eroare logate nu conțin secrete HMAC. OK.
- Cheile de licență introduse manual prin `updateLicense` se stochează plaintext în DB (P3).

---

## Igienă queue/job

| Job | Coadă | tries / timeout / backoff | failed() | Unicitate | Observații |
|---|---|---|---|---|---|
| `SyncWordPressSite` | `sync` | 3 / 120s / [30,60,120] (`SyncWordPressSite.php:28-32`) | da — circuit breaker + JobTracker (`:257-261`) | `ShouldBeUnique` per site | bun; la eșec setează `is_connected=false` (`:249-251`) — un blip de rețea scoate site-ul din toate listele de update până la sync-ul următor |
| `RunSafeUpdate` | default | 1 / 600s | da, cu notificare critică (`RunSafeUpdate.php:41-63`) | per safe-update | **niciodată dispatch-uit** — igiena e irelevantă, e cod mort |
| `CheckPluginVulnerabilities` | `default` | 1 / 300s (`CheckPluginVulnerabilities.php:21-23`) | **nu** | global | toate site-urile serial într-un singur job; la timeout (multe site-uri × cache miss Wordfence 30s/plugin) restul site-urilor rămân neverificate **silențios**, `tries=1` |
| Update-urile de plugin/teme din UI | **niciun job** — sincron în request Livewire | — | — | — | cel mai grav aspect; vezi PM-P0-2 |

**Dacă o coadă Horizon e blocată:** sync-ul îngheață (inventar din ce în ce mai vechi, dar `last_synced_at` previne acumularea — `ShouldBeUnique` limitează la 1 job/site), verificarea de vulnerabilități sare zile fără alertă. Update-urile în sine nu depind de cozi — deci funcționează, dar și backup-ul „pre_update" async (PM-P1-6) rămâne în coadă în timp ce update-ul rulează oricum.

---

## Error handling & observabilitate

- **Eșecul unui update e vizibil doar în sesiunea operatorului** (`updateResults`, flash, `notify`). Nicio notificare pe canale (Slack etc.) pentru update-uri eșuate — singura notificare a modulului, `safe_update_failed`, e în `failed()`-ul unui job mort (`RunSafeUpdate.php:51-62`).
- `PluginManagerService::updateCore` întoarce `['success' => true, 'message' => 'WordPress core update initiated.']` **necondiționat** (`PluginManagerService.php:417`) și scrie `ActivityLogger::coreUpdated` necondiționat (`:415`), chiar când `$result['success']` e `false` (corect doar în `UpdateLog:410`). Operatorul vede „succes" pentru un core update eșuat.
- Mesajele de eroare sunt trunchiate la 200 caractere (`cleanErrorMessage`, `:428-431`) — rezonabil pentru UI, dar `Log::warning` păstrează originalul. OK.
- `SafeUpdate` rămâne blocat etern în `backing_up`/`updating`/`health_checking` dacă workerul moare (SIGKILL nu apelează `failed()`); nu există comandă de cleanup pentru statusuri blocate (comparativ, SEO are `FixStuckSeoAudits`).
- `CheckPluginVulnerabilities` raportează doar în log (`CheckPluginVulnerabilities.php:52`); dacă jobul nu mai rulează deloc, nimeni nu află (fără dead-man's switch).
- Fals negativ sistematic: timeout 30s manager pe update-uri lungi → „Failed" raportat pentru update-uri care de fapt reușesc pe WP (PM-P1-5) → operatorii învață să ignore erorile, cel mai periculos pattern de observabilitate.

---

## Teste

**Ce există azi** (toate mock-uiesc `WordPressApiServiceFactory`; zero teste Livewire/HTTP pentru modul):

- `tests/Feature/Services/PluginManagerServiceTest.php` (8 teste): UpdateLog la update, erori API, activate/deactivate/delete model-sync, bulk count. **Nu testează** `updateCore` (deci nu prinde falsul succes), nici identificatorii trimiși.
- `tests/Feature/Services/SafeUpdateServiceTest.php` (6 teste): tranzițiile de status. Mock-urile acceptă orice argument și întorc succes (`SafeUpdateServiceTest.php:44-46`) — **maschează exact PM-P0-1** (slug vs file și rezultatul ignorat).
- `tests/Feature/Services/RollbackServiceTest.php` (8 teste): create/execute/clean pe `RollbackPoint`. Nu testează `WithPluginManagement::rollbackPlugin` (calea reală din UI) — deci nu prinde PM-P1-1.

**Setul minim viabil (cele mai periculoase regresii):**

1. **Contract identificatori**: `SafeUpdateService::runSafeUpdate('plugin')` trebuie să apeleze `updatePlugins([$plugin->file])` (mock cu `expects($this->once())->with(['dir/file.php'])`) și să marcheze `failed` când rezultatul per-item e `success=false`. Prinde PM-P0-1.
2. **Rollback end-to-end pe calea UI**: `UpdateLog` creat de `performUpdate` (name=display) → `rollbackPlugin` găsește versiunea și apelează `$api->rollback('plugin', slug, from_version)`. Prinde PM-P1-1.
3. **Authz Livewire**: user viewer pe `SitePlugins` → `updatePlugin`/`deletePluginDirect`/`updateCore` → 403 și `Queue/Http` neatinse. Prinde PM-P1-2.
4. **Scoping cross-site**: user non-admin fără acces la site-ul X → `updatePluginAcrossSites(slug)` nu atinge site-ul X. Prinde PM-P1-3.
5. **Backup blocant**: cu `backup_before_updates=true`, `bulkUpdatePlugins` nu apelează API-ul de update înainte ca backup-ul să fie finalizat (sau măcar `dispatchSync`). Prinde PM-P1-6.
6. **`updateCore` propagă eșecul**: `$result['success']=false` → return `success=false`, fără `ActivityLogger::coreUpdated`. Prinde falsul succes.
7. **Sync nu resetează `auto_update`**: payload de connector fără cheia `auto_update` → flag-ul existent rămâne neatins. Prinde jumătate din PM-P1-4.

---

## Model de date

- **Indexuri**: `site_plugins(site_id, has_update)` și `(site_id, is_active)` (`pgsql-schema.sql:7182,7189`), `update_logs(site_id, performed_at)` și `(site_id, type)` (`:7287,7294`), `rollback_points(site_id,status)`, `safe_updates(site_id,status)` — bune pentru query-urile per site. Query-urile **globale** din `UpdatesOverview` (`SitePlugin::where('has_update', true)` fără site_id, `UpdatesOverview.php:31,57`) nu au index dedicat; la scară, un index parțial `WHERE has_update` ar ajuta (P3).
- **N+1**: `UpdatesOverview` folosește `with('site')`, `updateHistory` folosește `with('user')` — curat. `pluginCounts` agregat într-un singur `selectRaw` — bun.
- **Soft-delete**: `SitePlugin`/`SiteTheme`/`UpdateLog` sunt hard-delete (fără `deleted_at` în schema). Sync-ul șterge hard plugin-urile absente (`SyncWordPressSite.php:102-104`) → **metadatele manuale (license_key/status introduse prin `updateLicense`) se pierd definitiv** dacă pluginul lipsește temporar dintr-un răspuns al connectorului (P3).
- **Orfane / drift**: `pending_updates_count` e denormalizat pe `sites` și întreținut manual: `updateSingle` decrementează doar pentru plugin-uri (`UpdatesOverview.php:174-176`), theme-urile nu decrementează deși sync-ul le include în count (`SyncWordPressSite.php:195-197`); `decrement` fără floor poate coborî sub 0 la dublu-click. Se auto-corectează abia la sync (≤6h) — P2.
- `UpdateLog.name` are semantică inconsistentă: nume afișat în căile normale (`PluginManagerService.php:48`), slug în rollback-uri (`RollbackService.php:47`, `WithPluginManagement.php:165`) — cauza directă a PM-P1-1.
- `RollbackPoint` nu stochează plugin file-ul, doar slug (rezolvat pe WP prin `find_plugin_file`, `class-rollback-endpoint.php:201-214`) — OK pentru wp.org, dar rollback-ul e imposibil pentru plugin-uri premium (zip-ul se descarcă de pe `downloads.wordpress.org`, `class-rollback-endpoint.php:71`) — limitare nedocumentată în UI (P3).

---

## Constatări

| ID | Sev. | Fișiere:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| PM-P0-1 | P0 | `app/Services/SafeUpdateService.php:62-67,78-88`; `app/Services/WordPress/Concerns/ManagesPlugins.php:17`; `wordpress-plugin/.../class-plugins-endpoint.php:96-97,217-222`; `app/Services/IncidentResponse/IncidentActionExecutor.php:185-195` | „Safe update" trimite slug în loc de plugin file (respins de connector cu „Invalid plugin path") și ignoră complet rezultatul; scrie `UpdateLog` `success=true` necondiționat și ajunge la status `completed` | Incident response AI decide „update plugin vulnerabil X"; nimic nu se actualizează pe site, dar sistemul înregistrează remedierea ca reușită → site-ul rămâne vulnerabil cu audit trail fals | Trimite `$plugin->file`, verifică `results[$file]['success']` și tratează eșecul ca `failed` înainte de health check; test de contract pe identificator |
| PM-P0-2 | P0 | `app/Livewire/Updates/UpdatesOverview.php:136-297`; `app/Livewire/Traits/WithPluginManagement.php:18-42,102-110`; `app/Services/PluginManagerService.php:27-80` | Fluxul real de update (single, per-site, cross-site) nu folosește pipeline-ul SafeUpdate: fără backup garantat, fără health check post-update, fără rollback point, execuție sincronă serială în request Livewire | „Update all sites" pentru un plugin cu regresie fatală → N site-uri de client whitescreen dintr-un click; nimic nu detectează, nimic nu face rollback; la >10 site-uri request-ul moare la mijloc fără raport | Rutează update-urile prin `SafeUpdateService` (reparat) ca job-uri queue per site, cu health check + auto-rollback; pentru cross-site, dispatch pe coadă cu progres |
| PM-P1-1 | P1 | `app/Livewire/Traits/WithPluginManagement.php:142-147`; `app/Livewire/Sites/Detail/SitePlugins.php:308-312`; `app/Services/PluginManagerService.php:44-55` | Lookup-ul de rollback caută `UpdateLog` cu `where('name', $plugin->slug)`, dar update-urile scriu `name` = numele afișat → nu găsește nimic | Update-ul strică site-ul; operatorul deschide detaliile pluginului: „No previous version found to rollback to" — recuperarea din UI e imposibilă exact când e nevoie | Caută pe `where('slug', $plugin->slug)`; uniformizează semantica `UpdateLog.name` |
| PM-P1-2 | P1 | `app/Livewire/Traits/WithPluginManagement.php:18-110`; `app/Livewire/Traits/WithThemeManagement.php:18-121`; `app/Livewire/Sites/Detail/SitePlugins.php:61,249-275` | Acțiunile distructive Livewire (update/activate/deactivate/delete plugin&theme, bulk update, `updateCore`, `toggleAutoUpdate`) nu apelează `authorizeSiteModification`; `mount` verifică doar acces de citire | Un cont „viewer" (sau user cu acces read la site prin client) invocă `deletePluginDirect(id)`/`updateCore` din devtools → plugin șters / core actualizat pe site live, atribuit unui rol care n-ar trebui să poată | Adaugă `authorizeSiteModification($this->site)` în fiecare mutator din cele două trait-uri (modelul există deja la `updateLicense`/`rollbackPlugin`) |
| PM-P1-3 | P1 | `app/Livewire/Updates/UpdatesOverview.php:230-297,31-48,57-115` | `updatePluginAcrossSites` verifică doar `isViewer()`, nu `canAccessSite` per site; listele/stats din pagină sunt globale, nescopate pe user | Un user non-admin asignat unui singur client rulează „Update All Sites" pentru un slug → actualizează pluginul pe toate site-urile agenției, inclusiv cele la care nu are acces | Filtrează colecția pe `canAccessSite` (sau sari site-urile neautorizate) + scopează listele afișate |
| PM-P1-4 | P1 | `app/Livewire/Sites/Detail/SitePlugins.php:261-275`; `app/Jobs/SyncWordPressSite.php:90,127`; `wordpress-plugin/.../class-plugins-endpoint.php:140-154` | `auto_update` e toggle UI fără niciun consumator backend (grep: zero job/scheduler care aplică flag-ul) și e resetat la `false` la fiecare sync, pentru că connectorul nu trimite câmpul (`?? false`) | Operatorul activează auto-update pe pluginele critice și se bazează pe asta pentru patch-uri de securitate; nu se aplică niciodată niciun update, iar flag-ul dispare în ≤6h — fals sentiment de siguranță | Fie implementează job-ul de auto-update programat, fie scoate toggle-ul; între timp, exclude `auto_update` din payload-ul de sync |
| PM-P1-5 | P1 | `app/Services/WordPressApiService.php:78`; `app/Services/WordPress/WordPressHttpClient.php:126`; `app/Services/WordPress/Concerns/ManagesPlugins.php:17-25`, `ManagesSiteInfo.php:46-52`; connector: fără `set_time_limit` în `class-plugins-endpoint.php`/`class-core-endpoint.php` | Timeout HTTP 30s pe apelurile de update (plugin bulk, core) fără prelungirea limitei de execuție pe WP | Core update durează 1-3 min: managerul raportează „Failed" la 30s în timp ce WP continuă (sau e ucis de `max_execution_time` mid-copy → site stricat); operatorul reîncearcă → doi upgraderi concurenți pe același site | Timeout dedicat (300-600s) pentru endpoint-urile de update + `set_time_limit` în connector + lock per site pe durata update-ului |
| PM-P1-6 | P1 | `app/Livewire/Traits/WithPluginManagement.php:104,182-188`; `WithThemeManagement.php:115` | „Backup before updates" face `CreateBackup::dispatch()` **async** și continuă imediat cu update-ul; backup-ul e doar `database` | Bulk update cu `backup_before_updates=true`: update-ul rulează înainte/în paralel cu backup-ul; „backup-ul pre-update" surprinde starea post-update sau nu există încă atunci când e nevoie de restore | Așteaptă finalizarea backup-ului (dispatchSync într-un job de update, ca în `SafeUpdateService.php:54`) sau chain-uiește update-ul după backup |
| PM-P2-1 | P2 | `app/Services/PluginManagerService.php:410-417` | `updateCore` întoarce `success=true` și scrie `ActivityLogger::coreUpdated` necondiționat, chiar când connectorul răspunde `success=false` | Core update eșuat → operatorul vede flash verde „WordPress core update initiated."; eșecul e vizibil doar în UpdateLog history | Propagă `$result['success']` în return și loghează activitatea doar la succes |
| PM-P2-2 | P2 | `app/Jobs/RunSafeUpdate.php` (tot fișierul) | Job niciodată dispatch-uit → notificarea critică `safe_update_failed` nu poate fi emisă; pipeline-ul asincron de safe update e cod mort | — (cod mort; induce falsa impresie că există alerting pe update-uri eșuate) | Folosește-l ca vehicul pentru PM-P0-2 sau șterge-l |
| PM-P2-3 | P2 | `app/Models/SafeUpdate` (statusuri), `app/Services/SafeUpdateService.php:51,61,91,120` | Fără cleanup pentru `SafeUpdate` blocate în statusuri intermediare la moartea workerului (SIGKILL nu declanșează `failed()`) | Deploy/restart Horizon în timpul unui safe update → înregistrare `updating` pe viață; `ShouldBeUnique` pe id nu blochează, dar starea rămâne mincinoasă | Comandă programată gen `FixStuckSeoAudits` care marchează `failed` după N minute |
| PM-P2-4 | P2 | `app/Services/RollbackService.php:32-58` | `executeRollback` nu verifică statusul punctului (`used`/`expired` re-executabile), marchează `used` înainte de confirmarea succesului, `success ?? true` by default | Playbook-ul AI trimite de două ori același `rollback_point_id` → dublu downgrade; sau rollback eșuat înregistrat ca reușit | Guard pe `status === 'available'` + update status după verificarea rezultatului |
| PM-P2-5 | P2 | `app/Jobs/CheckPluginVulnerabilities.php:21-23,35-53`; `routes/console.php:161-165` | Toate site-urile verificate serial într-un singur job cu `tries=1`, `timeout=300`, fără `failed()` | La 50+ site-uri cu cache Wordfence rece, jobul e ucis la 300s → site-urile de la coadă nu mai sunt verificate de vulnerabilități, zile la rând, silențios | Fan-out per site (job/site) sau timeout mărit + alertă la eșec |
| PM-P2-6 | P2 | `app/Services/PluginConflictService.php:141-144`; grep UI: zero referințe `SitePluginConflict` în `app/Livewire` + `resources/views` | Conflictele detectate se persistă și se notifică, dar nu au nicio pagină/afișare; `dismiss()` fără apelanți | Notificarea de conflict ajunge pe Slack; operatorul intră în aplicație și nu găsește nicăieri lista conflictelor active | Card de conflicte în `SitePlugins` + acțiune dismiss |
| PM-P2-7 | P2 | `app/Livewire/Traits/WithPluginManagement.php:162-170` | `UpdateLog`-ul creat la rollback nu setează `user_id` | Audit: „cine a făcut rollback la WooCommerce pe site-ul X?" — nu se poate răspunde | Adaugă `'user_id' => auth()->id()` |
| PM-P2-8 | P2 | `app/Livewire/Updates/UpdatesOverview.php:174-176,208-210,283`; `app/Livewire/Traits/WithThemeManagement.php:36`; `app/Jobs/SyncWordPressSite.php:195-201` | `pending_updates_count` denormalizat, decrementat inconsistent (theme-urile din UpdatesOverview nu decrementează; fără floor la 0) | Badge-urile de update din dashboard/rapoarte arată numere greșite (inclusiv negative) până la sync-ul următor (≤6h) | Recalculează count-ul din DB după fiecare operație în loc de `decrement` |
| PM-P2-9 | P2 | `app/Livewire/Updates/UpdatesOverview.php:182-228,259-288` (fără lock); modul 12 lock restore neconsultat | Niciun mutex per site între update-uri concurente sau update vs. restore | Doi operatori apasă simultan „Update All" pe același site (sau update în timpul unui restore) → upgraderi concurenți pe același filesystem WP | `Cache::lock("site-update:{$site->id}")` partajat cu lock-ul de restore |
| PM-P3-1 | P3 | `app/Services/PluginConflictService.php:17` | `'developer-developer'` — slug inexistent în categoria Backup a listei de funcționalitate duplicată | Zgomot/no-op la detectare | Înlocuiește cu slug real (ex. `wp-vivid-backuprestore`) |
| PM-P3-2 | P3 | `app/Livewire/Sites/Detail/SitePlugins.php:360-384`; `pgsql-schema.sql:2298-2299` | Risk assessment AI doar on-demand, nepersistat, negating; coloanele `ai_risk_score`/`ai_risk_assessment` moarte | Scorul de risc nu influențează și nu documentează nicio decizie de update | Persistă scorul pe `SafeUpdate` și afișează-l în confirmarea de update |
| PM-P3-3 | P3 | `app/Livewire/Updates/UpdatesOverview.php:31,57`; schema `site_plugins` | Query-uri globale `has_update=true` fără index parțial | Pagina Updates încetinește liniar cu nr. total de plugin-uri | Index parțial `ON site_plugins(site_id) WHERE has_update` |
| PM-P3-4 | P3 | `app/Jobs/SyncWordPressSite.php:102-104`; `WithPluginManagement.php:122-134` | Sync-ul hard-delete șterge metadatele manuale de licență la dispariția temporară a pluginului din payload | O eroare parțială de listare pe WP → cheile de licență introduse manual pierdute | Soft-delete sau păstrarea rândurilor cu flag `is_missing` |
| PM-P3-5 | P3 | `class-rollback-endpoint.php:71,128` | Rollback-ul descarcă exclusiv de pe `downloads.wordpress.org` — imposibil pentru plugin-uri premium; UI nu comunică limitarea | Rollback la Elementor Pro eșuează cu eroare criptică de download | Dezactivează butonul când `is_on_wp_org=false`, cu tooltip |
| PM-P3-6 | P3 | `resources/views/livewire/updates/updates-overview.blade.php` (fără indicator freshness); `DataSyncDispatcher.php:95` | Pagina Updates nu arată cât de vechi e inventarul (≤6h) și nu are buton de sync global | Operatorul actualizează pe baza unei liste de acum 5h; unele update-uri sunt deja aplicate/altele noi lipsesc | Afișează `last_synced_at` minim per grup + acțiune „Sync now" |

**Contoare: P0 = 2, P1 = 6, P2 = 9, P3 = 6.**

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-urile existente

1. **Conectează pipeline-ul SafeUpdate la UI** — toată infrastructura (backup sync, screenshot before/after, health check, visual diff, auto-rollback, model + statusuri) există deja și e testată; e nevoie doar de reparat identificatorul/verificarea rezultatului (PM-P0-1) și de dispatch-uit `RunSafeUpdate` din butoanele de update. Cel mai bun raport valoare/efort din tot modulul.
2. **Repară și expune rollback-ul**: fix pe lookup (PM-P1-1) + listă „Rollback points" per site (datele există în `rollback_points`, `getAvailablePoints` e deja scris) — transformă recuperarea dintr-o loterie într-un buton.
3. **Mută operațiile bulk pe coadă cu progres**: `JobTracker` + `WithJobTracking` sunt deja folosite pentru sync/backup în aceeași componentă (`SitePlugins.php:51-57`) — refolosește-le pentru `updateAllForSite`/`updatePluginAcrossSites`, eliminând moartea la mijloc a request-ului.
4. **Fă backup-ul pre-update blocant și complet** (fișiere + DB pentru update-uri de plugin), cu status vizibil „Backing up… → Updating…".
5. **Afișează conflictele și vulnerabilitățile în contextul update-ului**: badge „vulnerabil — patch disponibil" pe rândul de update (datele din `VulnerabilityAlert` există) ca operatorii să prioritizeze corect.

### (b) Feature-uri noi

1. **Auto-update real, condus de vulnerabilități** — flagul `auto_update` + feed-ul Wordfence + pipeline-ul SafeUpdate există deja; un scheduler nocturn care aplică safe-update doar pluginelor vulnerabile cu patch disponibil ar egala funcționalitatea de bază din WPMU DEV/MainWP. *Efort: M.*
2. **Rollout etapizat (canary)** pentru „Update All Sites": actualizează întâi 1 site (cel mai puțin critic / staging), rulează health check + visual diff, apoi continuă cu restul flotei sau oprește-te. Site-urile și grupurile există; e o mașină de stări peste `updatePluginAcrossSites`. *Efort: M/L.*
3. **Ferestre de mentenanță programate** per client/site (ex. marți 03:00) în care se rulează batch-ul de safe-updates și se generează un raport de update (screenshot-urile before/after există deja pentru secțiunea de raport client). *Efort: M.*
