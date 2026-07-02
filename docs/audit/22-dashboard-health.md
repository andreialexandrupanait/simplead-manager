# 22 — Dashboard, Health Scores, Snapshots lunare, Activity Timeline

**Data:** 2026-07-02 · **Auditor:** Claude (Fable 5) · **Scope:** `DashboardService`, `HealthScoreService`, `DatabaseHealthService`, `WordPressEolService`, `ActivityLogger`, `Livewire/Dashboard/GlobalDashboard`, `Livewire/Activity/ActivityTimeline`, Jobs `RecordHealthScores` / `AggregateMonthlySnapshots` / `CheckDatabaseHealthJob`, Models `DashboardWidget` / `HealthScoreHistory` / `SiteMonthlySnapshot` / `ActivityLog` + `SiteStatusHelper` (calculează scorul afișat în rândurile dashboard-ului). Working tree-ul necomis nu atinge acest modul (`git status`: doar fișiere de backup/export local).

---

## Rezumat executiv

1. **Coloana `sites.health_score` nu este scrisă NICĂIERI în aplicație** (verificat prin grep pe tot repo-ul și prin istoricul git complet — niciun writer, doar factories/seeders). Totuși pe ea se bazează filtrul Healthy/Warning/Critical din dashboard, sortarea „Health ↑/↓", API-ul `/v1/sites`, scope-urile `HasSiteScopes` și metoda `getHealthDistribution()`. În producție coloana e permanent `NULL` → filtrul „Healthy" returnează 0 site-uri, sortarea pe health e un no-op (D-P1-1).
2. **Componenta de securitate a health score-ului e mereu 12/25 („unknown")**: `HealthScoreService` citește `$site->securityMonitor?->hardening_score`, dar tabela `security_monitors` nu are această coloană — valoarea reală e pe `sites.security_hardening_score`. Istoricul zilnic scris de `RecordHealthScores` e deci sistematic greșit (D-P1-2).
3. **Activity timeline NU este un audit trail complet pentru operațiile distructive**: `RestoreBackup`, `PushConnectorPlugin`, `RunSafeUpdate` și `RollbackService` nu scriu niciun `ActivityLog`; ștergerea/redenumirea site-urilor (inclusiv bulk delete) nu e logată; iar `ActivityLogger::log()` atribuie `user_id` prin `auth()->id()`, care e `null` în orice job din coadă → „cine-a-făcut-ce" se pierde pentru aproape tot ce e queued (D-P1-3).
4. **Coloanele `cloudflare_*` și `seo_*` din `site_monthly_snapshots` nu sunt populate de nimeni** — `AggregateMonthlySnapshots` nu are agregator pentru ele → secțiunea Cloudflare din rapoartele lunare trimise clienților afișează permanent „N/A" (D-P1-4).
5. Există **trei definiții divergente ale health score-ului** (coloana NULL, `HealthScoreService` 4×25, `SiteStatusHelper` bazat pe culori) cu praguri diferite (90/70 hardcodat vs. 75/50 din `HealthLevel`) — bara de health din dashboard și filtrul de health folosesc surse diferite.
6. Eșecurile joburilor `RecordHealthScores` și `AggregateMonthlySnapshots` sunt **silențioase** (fără `failed()`, fără notificare) — un eșec pe 1 ale lunii la 02:00 lasă rapoartele generate la 05:00 fără date, fără ca cineva să afle.
7. **Zero teste** pentru întreg modulul (verificat: niciun fișier din `tests/` nu menționează Dashboard/HealthScore/ActivityLog/MonthlySnapshot).
8. Vestea bună: dashboard-ul e bine optimizat pentru scară — eager loading corect (12 relații + 6 `withCount`), cache 60s pe stats, fără N+1 detectat în hovercards.

---

## Inventar & corectitudine

### Ce face de fapt modulul

| Piesă | Rol real | Stare |
|---|---|---|
| `GlobalDashboard` (`app/Livewire/Dashboard/GlobalDashboard.php`) | Pagina `/` — 5 stat-cards, listă site-uri cu 12 indicatori/rând, bulk actions, drag&drop reorder, rename/delete | funcțional, dar filtrul health e mort (D-P1-1) |
| `DashboardService` (`app/Services/DashboardService.php`) | Stats agregate (cache 60s), trends, alerts, lista paginată de site-uri | ~40% cod mort (vezi mai jos) |
| `HealthScoreService` (`app/Services/HealthScoreService.php`) | Scor 0-100 din 4 componente ×25p | componenta security ruptă (D-P1-2) |
| `SiteStatusHelper` (`app/Helpers/SiteStatusHelper.php:16-61`) | Cei 12 indicatori colorați + un AL DOILEA health score derivat din culori | funcțional; sursa barei de health din rând |
| `RecordHealthScores` (`app/Jobs/RecordHealthScores.php`) | Zilnic 01:00 (`routes/console.php:64`) → upsert în `health_score_history` | rulează, dar scrie scoruri greșite (security mereu 12) |
| `AggregateMonthlySnapshots` (`app/Jobs/AggregateMonthlySnapshots.php`) | Lunar, 1 la 02:00 (`routes/console.php:70-73`) → `site_monthly_snapshots` (uptime, backups, updates, security, performance, analytics, GSC, incidente) | funcțional pentru 8 din 10 familii de coloane |
| `DatabaseHealthService` + `CheckDatabaseHealthJob` | Verificare sănătate DB WordPress la cerere (doar din UI, `app/Livewire/Sites/Detail/SiteDatabaseCleanup.php:111`) | funcțional; NU e programat |
| `WordPressEolService` (`app/Services/WordPressEolService.php`) | Clasificare versiune WP (EOL/outdated) + notificare la sync | funcțional; constante hardcodate învechite |
| `ActivityLogger` + `ActivityTimeline` | 19 tipuri de evenimente; pagina `/activity` cu filtre tip/severitate/dată/search, paginare 50 | funcțional; incomplet ca audit trail |
| `SiteMonthlySnapshot` | Consumat de `ReportGeneratorService::getSnapshot()` (`app/Services/ReportGeneratorService.php:205-221`) și de toate `Reports/Sections/*Gatherer` | da, e sursa rapoartelor lunare |

### Cod mort (verificat — zero consumatori în afara definiției)

- **`DashboardService`**: 7 metode nefolosite, ~230 linii: `getUptimeOverview()` (:297-320), `getRecentActivity()` (:322-329), `getSummaryStats()` (:353-368), `getHealthDistribution()` (:370-399), `getSitesNeedingAttention()` (:401-421), `getSitesWithIssues()` (:428-504), `getBackupStatus()` (:506-527). `invalidateCache()` (:159-167) șterge inclusiv cheile de cache ale metodelor moarte. Singurii consumatori reali: `GlobalDashboard` (`getStats`, `getTrends`, `getSitesOverview`) și `NotificationDropdown` (`getAlerts`, `app/Livewire/Components/NotificationDropdown.php:31`).
- **`DashboardWidget`** (`app/Models/DashboardWidget.php`): model + tabelă (`pgsql-schema.sql:924`, index `idx_user_sort`:6678) + relația `User::dashboardWidgets()` (`app/Models/User.php:101`) — feature-ul „widgets configurabile" a fost eliminat; ruta `/dashboard/widgets` e doar un redirect (`routes/web.php:76`). **Răspuns la întrebarea de benchmark: NU există widgets configurabile azi.**
- **`SitesList`** (`app/Livewire/Sites/SitesList.php`): componentă neruotată și nereferențiată (`/sites` redirecționează la dashboard, `routes/web.php:79`); conține propriile praguri 90/70.
- Coloanele `cloudflare_requests/bandwidth_bytes/cache_hit_ratio`, `seo_score`, `seo_issues_count` din `SiteMonthlySnapshot` — scrise de nimeni (D-P1-4).

### Feature-uri pe jumătate

- Health score-ul ca sistem: trei implementări paralele, niciuna nu alimentează coloana pe care rulează filtrele (D-P1-1, D-P2-1).
- `SiteStatusHelper::seo()` returnează hardcodat „SEO: Module removed" (`SiteStatusHelper.php:205-208`) — dimensiune moartă în calculul scorului din rând.

### Ce EXISTĂ azi în tabela de site-uri (pentru benchmark SpinupWP)

- **Search**: nume + URL, `ilike` cu escape corect (`DashboardService.php:259-265`, `escapeLike`:331-334).
- **Filtre**: client, health (rupt), status custom (`SiteStatus` cu culoare) — pills cu dropdown.
- **Sortare**: manual (drag&drop persistat în `sort_order`, global scope `ordered` pe `Site`, `Site.php:188`), name A-Z/Z-A, health ↑/↓ (no-op din cauza D-P1-1). **Fără sortare pe coloane individuale, fără coloane configurabile, fără saved views.**
- **Per-page**: setare globală `dashboard_per_page` (default 30, `GlobalDashboard.php:66`); în modul reorder încarcă până la 10.000 de rânduri.
- **Bulk**: set status, move to client, sync, backup, check uptime, delete (admin-only).

---

## Siguranța operațiilor distructive

Modulul nu execută direct operații pe site-urile WP, dar **declanșează** unele și **este** stratul de audit al tuturor:

- `runBackup`/`bulkBackup`/`bulkSync` — dispatch de joburi cu rate limiting per user (`WithRateLimiting.php:11-21`: 5/h implicit) și `authorize('update')` per site (`GlobalDashboard.php:170-181`). OK.
- **Ștergerea site-urilor**: `deleteSite()` (`GlobalDashboard.php:265-280`) — policy `delete` = admin-only (`app/Policies/SitePolicy.php:36-42`), confirmare prin modal, soft-delete (`Site.php:116` `SoftDeletes`). `bulkDelete()` (`app/Livewire/Traits/WithBulkSiteActions.php:109-118`) — gate `canDeleteResources()` (admin-only, `app/Enums/UserRole.php:32-35`), modal cu lista numelor. Notă: `bulkDelete` folosește `Site::whereIn(...)->delete()` direct, ocolind `scopedSiteQuery()` folosit de restul acțiunilor bulk (:16-20) — azi inofensiv pentru că gate-ul e admin-only, dar fragil dacă gate-ul se relaxează vreodată.
- **Audit logging cine-a-făcut-ce**: INCOMPLET — vezi D-P1-3. Nici ștergerea (single sau bulk), nici redenumirea, nici reorder-ul nu produc `ActivityLog`. Restore-ul de backup — cea mai distructivă operație din aplicație — nu apare deloc în timeline (verificat: zero referințe `ActivityLog` în `app/Jobs/RestoreBackup.php`, `app/Jobs/PushConnectorPlugin.php`, `app/Jobs/RunSafeUpdate.php`, `app/Services/RollbackService.php`).
- Idempotență/locking: nerelevant aici (joburile declanșate au propriile mecanisme, auditate în modulele 11/12).

---

## Securitate

- **Rute**: `/` și `/activity` sub `['auth', 'verified', 'throttle:authenticated']` (`routes/web.php:72-76, 153`). OK.
- **Acțiuni Livewire**: toate acțiunile mutante din `GlobalDashboard` au `authorize('update'|'delete')` sau `abort_unless(canManageSites())` (`GlobalDashboard.php:166, 178, 191, 208, 220, 246, 268, 307`; `WithBulkSiteActions.php:24, 34, 43, 52, 69, 86, 111`). Verificat individual — nu am găsit acțiune mutantă negardată.
- **Vizibilitate**: lista de site-uri din dashboard NU e scoped per user (`DashboardService.php:249` — `Site::with(...)` fără filtru `user_id`), deci și un Viewer vede toate site-urile și tot activity log-ul (`ActivityTimeline` nu are niciun filtru de autorizare pe site). Pentru un tool intern de agenție e probabil intenționat, dar e inconsecvent cu `scopedSiteQuery()` din bulk actions și cu `canAccessSite()` din policy (D-P3-7).
- **Mass assignment**: `ActivityLog::$fillable` include `user_id`/`metadata` dar e populat doar server-side; `renameSite` validează `max:255` și scrie doar `name` (`GlobalDashboard.php:239-247`). OK.
- **SSRF**: singurul fetch extern e `WordPressEolService::getLatestVersion()` către URL fix `https://api.wordpress.org/...` (`WordPressEolService.php:118`) — fără input utilizator. OK.
- **Injecții**: `getHealthDistribution()` interpolează în `selectRaw` doar constante de clasă int (`DashboardService.php:377-386`) — sigur (și mort); search-urile folosesc binding + escape LIKE (`ActivityTimeline.php:98`, `DashboardService.php:331-334`). OK.
- **Secrete în loguri**: `ActivityLogger::userLogin/userLoginFailed` stochează IP + user agent + email în `metadata` (`ActivityLogger.php:346-411`) — PII în tabelă cu retenție configurabilă; acceptabil intern, de menționat la GDPR.
- **XSS**: `$trendArrow` e construit server-side din valori enum controlate și emis cu `{!! !!}` (`global-dashboard.blade.php:39-49, 92`) — fără input utilizator. OK.

---

## Igienă queue/job

| Job | tries | timeout | backoff | unic | `failed()` | Observații |
|---|---|---|---|---|---|---|
| `RecordHealthScores` | 1 (`:22`) | 300s | — | `ShouldBeUnique` (`:18`) | **nu** | eșec total = zi lipsă în istoric, vizibil doar în Horizon; erori per-site doar `Log::warning` (`:63-68`) |
| `AggregateMonthlySnapshots` | 3 (`:23`) | 600s | [300, 900] (`:25`) | nu (ok, rulează lunar) | **nu** | eșec = rapoartele lunii afișează N/A silențios (vezi mai jos) |
| `CheckDatabaseHealthJob` | 2 (`:22`) | 120s | [30, 60] (`:26`) | `ShouldBeUnique` per site (`:32-35`) | da → `JobTracker::fail` (`:50-53`) | cel mai corect din trio |

- Toate rulează pe coada `default`. **Dacă coada `default` e blocată**: dashboard-ul continuă să afișeze date (citește direct din DB + cache), dar istoricul health capătă găuri, iar dacă blocajul prinde noaptea de 1 ale lunii, snapshot-urile nu există când `ReportDispatcher` generează rapoartele.
- **Cuplaj temporal fragil**: snapshots 1 la 02:00 (`routes/console.php:70-73`) → rapoarte 1 la 05:00 (commit `0cc8864`); nicio verificare de dependență — `ReportGeneratorService::getSnapshot()` returnează pur și simplu `null` (`:205-211`) și gatherer-ele degradează la „N/A" fără eroare (D-P2-3).
- Scheduler: ambele intrări au `onOneServer()` dar nu `withoutOverlapping()` — irelevant practic la frecvențele astea.

---

## Error handling & observabilitate

- **Silențios**: `RecordHealthScores` înghite orice `Throwable` per site cu `Log::warning` (`RecordHealthScores.php:63-68`) — un bug sistematic (exact cazul D-P1-2, care nu aruncă, doar calculează greșit) nu ar fi fost prins nici cu alerting.
- **Silențios**: eșecul `AggregateMonthlySnapshots` după 3 încercări ajunge doar în `failed_jobs`/Horizon; nicio notificare pe canalele existente (`NotificationService` e folosit în alte module, nu aici).
- `WordPressEolService::getLatestVersion()` — dacă API-ul WP e jos, loghează `info` și **cache-uiește fallback-ul '6.7.2' pentru 24h** (`WordPressEolService.php:126-132`), ceea ce poate marca temporar site-uri la zi drept „behind" sau invers.
- `DatabaseHealthService` loghează start/finish + `ActivityLogger` cu status (`DatabaseHealthService.php:20, 101-117`) — bun.
- Trend-ul `pending_updates` e de facto mereu „neutral": comentariul spune „refreshed hourly", dar valoarea anterioară e suprascrisă la fiecare recalculare a cache-ului de 60s (`DashboardService.php:113-127`), iar cache-ul e invalidat la orice `Site::saved` (`Site.php:196-198`) — deci delta compară cu acum ~1 minut (D-P3-1).

---

## Teste

**Astăzi: zero.** Niciun fișier din `tests/` nu atinge modulul (verificat: `grep -rln "GlobalDashboard|DashboardService|ActivityTimeline|RecordHealthScores|AggregateMonthly" tests/` → gol; `tests/` conține 23 fișiere, toate pe backup/security/plugins).

**Setul minim viabil (6 teste):**
1. `HealthScoreService::calculate()` cu un site care are `security_hardening_score` setat → componenta security ≠ 12 (ar fi prins D-P1-2 imediat).
2. `RecordHealthScores` → creează rând în `health_score_history` cu scorurile componente corecte; rulat de 2× în aceeași zi → un singur rând (upsert).
3. `AggregateMonthlySnapshots` cu date seed pe o lună → `uptime_percentage`, `backups_total/successful/failed` corecte; lună fără date → nu creează rând.
4. `GlobalDashboard`: filtrul `healthy` returnează site-urile cu scor ≥ prag (azi ar pica — documentează D-P1-1); `deleteSite` ca non-admin → 403; `bulkDelete` ca editor → 403.
5. `getSitesOverview()` nu emite N+1 (assertie pe query count cu 10 site-uri cu toate relațiile).
6. `ActivityTimeline`: filtru pe tip + severitate + search returnează doar evenimentele potrivite; evenimentele cu `user_id` afișează numele.

---

## Model de date

- **Indexuri**: `activity_logs` are exact ce trebuie pentru query-urile din timeline: `created_at`, `severity`, `type`, `(site_id, created_at)` (`pgsql-schema.sql:6349-6370`). `health_score_history` are UNIQUE `(site_id, recorded_at)` (`:5466-5470`) — acoperă și query-ul de trend din `SiteOverview.php:231`. `site_monthly_snapshots` UNIQUE `(site_id, year, month)` (`:6058-6062`) — acoperă lookup-ul rapoartelor. OK.
- **N+1**: `getSitesOverview()` eager-încarcă tot ce consumă `site-row.blade.php` + hovercards (verificat fiecare hovercard — folosesc doar colecții eager: `resources/views/components/hovercards/*.blade.php`); `uptimeMonitor.incidents` cu `limit(10)` per-parent e suportat nativ de Laravel 11 (`composer.json`: `^11.31`). Singura excepție minoră: modalul de bulk-delete face un query inline în blade (`global-dashboard.blade.php:532`). **Concluzie la 50+ site-uri: OK** — paginat la 30, stats în cache 60s; punctul slab e modul reorder (până la 10.000 rânduri, `GlobalDashboard.php:66`).
- **Soft-delete**: `Site` e soft-deleted; `whereHas('site')` din `DashboardService` exclude corect site-urile șterse; FK-urile `health_score_history`/`site_monthly_snapshots` au `ON DELETE CASCADE` (`pgsql-schema.sql:7650, 8202`) dar cascada nu se declanșează la soft-delete → snapshot-urile site-urilor soft-deleted rămân (corect pentru rapoarte istorice). `ActivityLog.site_id` fără cascadă vizibilă în model — evenimentele site-urilor șterse rămân cu relația `site` null (blade-ul tratează null). OK.
- **Retenție**: `activity_logs` și `database_health_checks` sunt acoperite de `RetentionPolicyService` (`app/Services/RetentionPolicyService.php:58, 76`); `health_score_history` NU — creștere ~365 rânduri/site/an, neglijabil dar nelimitat (D-P3-5). Atenție: retenția pe `activity_logs` șterge singurul audit trail — de corelat cu D-P1-3.
- **Orfane**: `dashboard_widgets` — tabelă vie pentru un feature mort.

---

## Cum se calculează health score-ul (răspuns la întrebarea cheie)

`HealthScoreService::calculate()` (`app/Services/HealthScoreService.php:11-65`), ponderi fixe 25/25/25/25:

| Componentă | Formulă | Probleme |
|---|---|---|
| Uptime | `uptime_30d / 100 × 25`; fără monitor → **25** („assume ok", :21) | liniară și indulgentă: 90% uptime (≈3 zile down/lună) = 22.5/25; lipsa monitorului e premiată cu punctaj maxim |
| Security | `hardening_score / 100 × 25`; necunoscut → 12 | **mereu 12** — citește o coloană inexistentă (D-P1-2) |
| Updates | 0→25, ≤3→20, ≤10→12, >10→5 (:36-43) | singura componentă cu trepte acționabile |
| Performance | `latest_mobile_score / 100 × 25`; lipsă → 12 (:47-52) | OK |

**E acționabil?** Parțial: breakdown-ul pe componente există doar pe pagina site-ului (`SiteOverview.php:213-216` + `_health-bar.blade.php`), cu trend 30 zile din istoric (:229-241). Dar dashboard-ul global afișează ALT scor (`SiteStatusHelper.php:28-42`, derivat din culorile a 6 dimensiuni, dintre care una — SEO — e moartă), iar filtrarea folosește a treia sursă (coloana NULL). Un utilizator care filtrează „Critical" și se uită la bara de health a unui rând vede două realități diferite.

---

## Constatări

| ID | Sev | Fișiere:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| D-P1-1 | P1 | `app/Services/DashboardService.php:267-292`, `app/Livewire/Sites/SitesList.php:33-36`, `app/Models/Traits/HasSiteScopes.php:19-30`, `routes/api.php:28-29`; writer inexistent (verificat grep repo + istoric git) | `sites.health_score` nu e scris de niciun cod; filtrele/sortarea/API-ul/health-distribution operează pe NULL | Operatorul filtrează „Healthy" ca să vadă ce site-uri sunt ok → 0 rezultate deși toate sunt sănătoase; „Critical" arată doar site-urile down; sortarea Health ↑/↓ nu schimbă nimic (toate COALESCE la 0); API-ul `/v1/sites` raportează `health_score: null` către integrări | `RecordHealthScores` (sau un listener la sync) să persiste `HealthScoreService::calculate()['total']` în `sites.health_score` |
| D-P1-2 | P1 | `app/Services/HealthScoreService.php:26`; coloana reală: `app/Models/Site.php:127` (`security_hardening_score`, scrisă în `app/Services/SecuritySettingsService.php:165`); `security_monitors` fără coloană (`pgsql-schema.sql`, CREATE TABLE security_monitors) | Componenta security citește `securityMonitor?->hardening_score` — atribut inexistent → mereu `null` → mereu 12/25 | Un site complet hardenizat (score 100) și unul dezastruos (score 10) primesc identic 12/25; `health_score_history` acumulează zilnic date greșite; trend-urile de securitate din breakdown sunt plate | Înlocuire cu `$site->security_hardening_score` |
| D-P1-3 | P1 | `app/Services/ActivityLogger.php:25` (`auth()->id()` null în workers); absență totală de logging în `app/Jobs/RestoreBackup.php`, `app/Jobs/PushConnectorPlugin.php`, `app/Jobs/RunSafeUpdate.php`, `app/Services/RollbackService.php` (verificat grep); ștergere/rename site nelogate (`app/Livewire/Traits/WithBulkSiteActions.php:109-118`, `app/Livewire/Dashboard/GlobalDashboard.php:239-280`) | Timeline-ul nu poate răspunde la „cine a restaurat site-ul clientului X aseară?" — restore/push plugin/safe-update lipsesc complet, iar tot ce trece prin cozi are `user_id = null` (ex. `DeleteSpamUsersJob.php:156-165` loghează ștergerea de useri WP fără inițiator) | Joburile distructive să primească `?int $initiatorUserId` în constructor și `ActivityLogger::log()` să accepte user explicit; adăugat logging la restore/push/delete site |
| D-P1-4 | P1 | `app/Jobs/AggregateMonthlySnapshots.php:39-56` (niciun agregator cloudflare/seo); consumatori: `app/Services/Reports/Sections/CloudflareGatherer.php:36-54`, `app/Livewire/Traits/WithReportGeneration.php:537-546`; coloane: `app/Models/SiteMonthlySnapshot.php` (fillable) | `cloudflare_requests/bandwidth_bytes/cache_hit_ratio`, `seo_score`, `seo_issues_count` nu sunt populate de nimeni | Fiecare raport lunar trimis clientului cu secțiunea Cloudflare activă afișează „not available" la requests/bandwidth/cache-hit, lună de lună, fără nicio eroare vizibilă intern | Adăugat `aggregateCloudflare()` (din datele `SyncCloudflareZone`) sau eliminat secțiunea/coloanele |
| D-P2-1 | P2 | `app/Services/DashboardService.php:267-274` și `app/Livewire/Sites/SitesList.php:33-36` (praguri 90/70 hardcodate) vs. `app/Enums/HealthLevel.php:14-16` (75/50) vs. `app/Helpers/SiteStatusHelper.php:28-42` (scor separat din culori) | Trei definiții de health cu praguri diferite; bara din rând și filtrul folosesc surse diferite | După fix-ul D-P1-1, un site cu scor 80 e „Healthy" în distribuție (75) dar exclus din filtrul „healthy" (90) | O singură sursă: `HealthScoreService` → coloană; praguri doar din `HealthLevel` |
| D-P2-2 | P2 | `app/Jobs/AggregateMonthlySnapshots.php:160-212` | Analytics/GSC în snapshot = „ultimul cache 28d la momentul agregării", nu luna calendaristică; re-rularea jobului pentru o lună trecută (constructor `year/month`, :27-37) ar suprascrie cu date de ACUM | Backfill pentru aprilie rulat în iulie → snapshot-ul lui aprilie conține traficul din iunie; rapoartele regenerate mint | Agregat din date brute pe interval sau marcat coloanele ca „approximative, no backfill" |
| D-P2-3 | P2 | `routes/console.php:70-73` (02:00) vs. rapoarte la 05:00 (commit `0cc8864`); `app/Services/ReportGeneratorService.php:205-211` (null tolerat); fără `failed()` în `AggregateMonthlySnapshots` | Dependența snapshots→rapoarte e doar un decalaj de 3h, fără verificare și fără alertă la eșec | Horizon blocat/deploy în noaptea de 1 → toate rapoartele lunare pleacă spre clienți cu secțiuni goale; nimeni nu află până nu se plânge un client | `failed()` cu `NotificationService` + `ReportDispatcher` să verifice existența snapshot-ului înainte de generare |
| D-P2-4 | P2 | `app/Jobs/RecordHealthScores.php:22` (tries=1), `:63-68` (catch per site), fără `failed()` | Eșecul (total sau per site) al snapshot-ului zilnic de health e invizibil | O excepție de conexiune DB la 01:00 → găuri permanente în graficul de trend, nedetectate | tries=3 + `failed()` cu notificare |
| D-P2-5 | P2 | `app/Services/DashboardService.php:297-329, 353-527` (7 metode moarte), `app/Models/DashboardWidget.php` + tabelă + `routes/web.php:76`, `app/Livewire/Sites/SitesList.php` | ~400 linii de cod mort cu logică de business (praguri, agregări) care derutează și diverge de codul viu | Un dev „repară" pragurile în `getHealthDistribution()` (mort) și nu în filtrul real | Ștergere + migrare drop `dashboard_widgets` |
| D-P2-6 | P2 | `app/Services/WordPressEolService.php:19` (`EOL_BEFORE='6.0'`), `:131` (fallback `'6.7.2'`) | Pragul EOL și versiunea fallback sunt constante hardcodate din ~2025; în 2026 WP a avansat — clasificările „End of Life"/„current" derivă în timp | Un site pe WP 6.1 (real EOL în 2026) e clasificat „ok" pentru că 6.1 ≥ 6.0; notificările critice nu pleacă | Praguri în config sau derivate din API (`offers` conține branch-urile active) |
| D-P2-7 | P2 | `CheckDatabaseHealthJob` dispatch doar din `app/Livewire/Sites/Detail/SiteDatabaseCleanup.php:111`; consumat de `app/Services/Reports/Sections/DatabaseHealthGatherer.php:27-29` (ia ultimul check, oricât de vechi) | Health-ul DB nu e programat niciodată | Raportul lunar include un „Database Health" din urmă cu 6 luni, prezentat ca actual (doar `checked_at` mic în subsol) | Rulare programată (săptămânal) pentru site-urile conectate |
| D-P2-8 | P2 | `tests/` (verificat exhaustiv) | Zero teste pe modul | Oricare din P1-urile de mai sus reapare la refactor fără să pice nimic | Setul minim viabil de 6 teste (secțiunea Teste) |
| D-P3-1 | P3 | `app/Services/DashboardService.php:113-127` + `app/Models/Site.php:196-201` | Trend-ul „pending updates" compară cu valoarea de la ultima recalculare (~60s, nu 1h ca în comentariu) → săgeată aproape mereu neutră | Indicator decorativ, informație zero | Snapshot separat cu cheie orară (`prev_updates:YYYY-MM-DD-HH`) |
| D-P3-2 | P3 | `resources/views/livewire/dashboard/global-dashboard.blade.php:168` | Cardul „Alerts" afișează săgeata de trend a `pending_updates`, nu a alertelor | Alerte în creștere + updates în scădere → săgeată verde în jos pe cardul roșu de alerte | Trend dedicat pe `total_alerts` |
| D-P3-3 | P3 | `app/Livewire/Activity/ActivityTimeline.php:24-36` vs. tipurile emise real (19, verificat grep: lipsesc `user`, `database`, `dns`, `error_log`, `incident_response`, `webhook`, `seo`, `seo_fix`, `connection_error`) | 9 tipuri de evenimente nu pot fi filtrate în timeline (vizibile doar la „All") | Cauți „cine a șters useri WP" → tipul `user` nu există în dropdown | Generat dinamic din `ActivityLog::distinct('type')` |
| D-P3-4 | P3 | `app/Livewire/Dashboard/GlobalDashboard.php:183-198` | `checkNow()` pe site fără monitor consumă rate-limit-ul și nu afișează nimic | Utilizatorul apasă de 10 ori, apoi primește „Too many requests" fără să fi rulat vreun check | Mesaj „No uptime monitor configured" |
| D-P3-5 | P3 | `app/Services/RetentionPolicyService.php` (lipsă `health_score_history`) | Istoricul health nu are politică de retenție | Creștere nelimitată (mică: ~18k rânduri/an la 50 site-uri) | Adăugat în categoria monitoring |
| D-P3-6 | P3 | `app/Helpers/SiteStatusHelper.php:186-189` | Tooltip „WP x.y (latest)" doar pe baza absenței `core_update_version`, ignorând `WordPressEolService` | Site nesincronizat de 3 luni afișează „(latest)" pe o versiune veche | Folosit `WordPressEolService::classify()` (deja cached) |
| D-P3-7 | P3 | `app/Services/DashboardService.php:249` (fără scoping), `app/Livewire/Activity/ActivityTimeline.php:92-106` (fără scoping) | Dashboard-ul și timeline-ul arată toate site-urile/evenimentele oricărui rol, deși acțiunile și bulk-ul sunt scoped | Un Viewer extern (dacă ar exista vreodată) vede tot portofoliul | Decizie explicită: ori scoping consecvent, ori documentat că vizibilitatea e globală by design |

**Total: 0×P0, 4×P1, 8×P2, 7×P3.**

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-urile existente

1. **Un singur health score, persistat** — fix D-P1-1 + D-P1-2 + D-P2-1 împreună: `RecordHealthScores` scrie și `sites.health_score`, pragurile vin doar din `HealthLevel`, `SiteStatusHelper` afișează același scor. Deblochează instant filtrarea, sortarea și API-ul. (Efort: S)
2. **Audit trail complet cu atribuire** — user inițiator propagat în joburi + logare restore/push/safe-update/delete site; timeline-ul devine sursa de adevăr pentru „cine-a-făcut-ce-pe-ce-site" cerută de natura distructivă a platformei. (S/M)
3. **Alerting pe joburile de agregare** — `failed()` + `NotificationService` pe `RecordHealthScores` și `AggregateMonthlySnapshots`; un banner pe dashboard când snapshot-ul lunii precedente lipsește. (S)
4. **Tabela de site-uri: sortare pe coloane + coloane configurabile** — azi indicatorii sunt ficși și sortarea e doar globală; SpinupWP/MainWP oferă sort per coloană și show/hide columns; `dashboard_widgets` (sau o coloană `jsonb` pe users) poate stoca preferințele. (M)
5. **Export CSV pe Activity timeline + filtre pe user și site** — datele există (`user_id`, `site_id` indexat); util pentru postmortem-uri și pentru clienți. (S)

### (b) Feature-uri noi propuse

1. **Client rollup dashboard** — vedere per client (site-urile, uptime agregat, backup-uri, health mediu) construită pe `site_monthly_snapshots` + `clients` existente; benchmark: WPMU DEV „Clients & Billing", ManageWP client view. Agenția gândește în clienți, nu în site-uri. (Efort: M)
2. **Health regression alerts** — `health_score_history` există deja zilnic; un check în `RecordHealthScores` care notifică la scădere >N puncte în 7 zile transformă scorul dintr-un număr pasiv într-un semnal operațional. (S)
3. **„Month in review" digest intern pe 1 ale lunii** — după `AggregateMonthlySnapshots`, un e-mail/Slack cu top regresii, site-uri fără backup reușit, incidente — infrastructura există (`SendDailyDigest`, `NotificationService`); închide și gap-ul de observabilitate D-P2-3. (S/M)
