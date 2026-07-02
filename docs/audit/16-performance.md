# 16 — Performance / PageSpeed

**Data:** 2026-07-02 · **Auditor:** Claude (audit Faza 1) · **Scope:** `app/Services/PageSpeedService.php`, `app/Jobs/RunPerformanceTest.php`, `NotifyPerformanceDrop.php`, `NotifyBudgetViolation.php`, `app/Livewire/Performance/*`, `app/Livewire/Sites/Detail/SitePerformance.php`, traits `WithPerformance*`, modelele `PerformanceMonitor` / `PerformancePage` / `PerformanceTest`, coada `performance`.

---

## Rezumat executiv

1. **Testele programate de performanță nu rulează deloc.** Dispatch-ul orar bazat pe `next_test_at` a fost șters din `routes/console.php` în refactorul `32d65c6` („complete 7-phase refactoring blueprint") și nu a fost mutat nicăieri — `MonitoringDispatcher` gestionează doar uptime/security/DNS (`app/Dispatchers/MonitoringDispatcher.php:21-28`). UI-ul de setări (daily/weekly, oră, `next_test_at` calculat la fiecare rulare în `app/Jobs/RunPerformanceTest.php:278-294`) este un no-op complet. Alertele de drop și datele din rapoartele lunare depind în întregime de rulări manuale. Regresie **silențioasă** — nimic nu semnalează că monitorizarea „daily" nu s-a mai executat (PF-P1-1).
2. **Authz incomplet pe acțiunile Livewire.** `SitePerformance` verifică doar `authorizeSiteAccess` la `mount()` — un utilizator cu rol *viewer* poate rula teste, modifica setări/alerte, șterge pagini monitorizate. `PerformanceOverview::testAllSites` nu are nicio verificare de rol sau de acces per-site și pornește teste pentru **toate** site-urile (PF-P1-2).
3. Două feature-uri pe jumătate moarte: **performance budgets** (backend complet — job, mail, notificări — dar UI-ul de editare a fost șters intenționat în `65aba5b`, deci `budgets` nu poate fi setat de nicăieri → `NotifyBudgetViolation` e cod inaccesibil) și **competitor benchmarking** (UI de adăugare URL-uri există, dar niciun job nu creează vreodată teste cu `is_competitor = true` → tabelul de comparație afișează permanent „—").
4. Gestionarea cotei Google PSI e rudimentară: excepțiile API sunt înghițite per-URL (`RunPerformanceTest.php:97-104`), deci `$tries = 2` și `$backoff` sunt cod mort; 429 e tratat ca orice eroare, fără retry/`Retry-After`; la timeout de conexiune, mesajul cURL cu URL-ul complet (inclusiv `key=...`) ajunge în `error_message` (vizibil în UI) și în loguri.
5. Alertele de drop se bazează pe **o singură măsurătoare** comparată cu precedenta — la variabilitatea naturală PSI (±5-15 puncte pe mobil), pragul implicit de 10 generează false pozitive; media mobilă există doar cosmetic, în grafic.
6. **Zero teste automate** pentru întregul modul.

---

## Inventar & corectitudine

### Ce face de fapt modulul

- **`PageSpeedService`** (`app/Services/PageSpeedService.php`, 154 linii) — client subțire pentru Google PageSpeed Insights v5 (`API_URL` la linia 11). Extrage: scor performance, lab metrics (FCP/LCP/CLS/TBT/SI/TTI), field data CrUX (FCP/LCP/CLS/INP/TTFB — liniile 95-107), page stats (requests, bytes pe tip resursă — liniile 119-153), screenshot final base64 (linia 63). Cheia API e **opțională** (liniile 28-30) — fără `PAGESPEED_API_KEY`, serviciul merge pe cota anonimă (foarte mică), degradare silențioasă.
- **`RunPerformanceTest`** (`app/Jobs/RunPerformanceTest.php`) — pe coada `performance`; testează fie URL-ul site-ului (backward compat, linia 52), fie toate `PerformancePage`-urile monitorului (liniile 54-62), pe mobile+desktop; salvează thumbnail JPEG 800px pe disk `public` pentru cardul site-ului (liniile 206-246, doar desktop + pagina primară); actualizează scorurile cache-uite pe monitor și `next_test_at` (liniile 248-295); verifică drop-uri (liniile 297-335) și bugete (liniile 109-204).
- **`NotifyPerformanceDrop` / `NotifyBudgetViolation`** — joburi subțiri pe coada `notifications`, deleagă către `NotificationService::notifySiteEventSlim` (evenimente `performance_drop` / `budget_violation`, înregistrate în `app/Models/NotificationTemplate.php:50-51`) + mailables (`app/Mail/PerformanceAlertMail.php`, `BudgetViolationMail.php`).
- **UI**: `PerformanceOverview` (listă globală, sortare, „Test All Sites") și `SitePerformance` (gauge-uri, CrUX, trend 30d, grafic istoric cu markere de update-uri, competitor benchmarking, istoric 20 teste, modal setări).
- **Integrare downstream**: `HealthScoreService` folosește `latest_mobile_score` pentru 25 din 100 puncte de health (`app/Services/HealthScoreService.php:45-62`); rapoartele lunare consumă ultimul test + snapshot-uri lunare (`app/Services/Reports/Sections/PerformanceGatherer.php:31-45`); `SiteStatusHelper` îl include în bara de sănătate a cardului (`app/Helpers/SiteStatusHelper.php:22`).

### Cod mort / feature-uri pe jumătate

| Element | Dovadă | Stare |
|---|---|---|
| Programarea testelor (`frequency`, `test_time`, `day_of_week`, `next_test_at`, `interval_minutes`) | niciun consumator: `routes/console.php` (fișier întreg, 219 linii — zero referințe la performance), `MonitoringDispatcher.php:21-28`; dispatch-ul orar șters în `32d65c6` | **mort** → PF-P1-1 |
| Performance budgets UI | `openBudgetModal`/`saveBudgets`/`budgetViolations` (`app/Livewire/Traits/WithPerformanceBudgets.php:67-102`, `13-65`) nu apar în niciun blade (`grep -r "openBudgetModal\|saveBudgets\|budgetViolations" resources/views` → 0); UI șters în commit `65aba5b` „remove budget button and modal" | backend viu, UI **mort** → PF-P2-1 |
| Competitor benchmarking | `WithPerformanceCompetitors.php:55-70` interoghează `is_competitor = true`, dar nimic nu creează astfel de rânduri (`grep -r is_competitor app/` → doar trait + model schema) | jumătate implementat → PF-P2-2 |
| Coloane `performance_tests` nepopulate | `opportunities`, `diagnostics`, `third_party_scripts`, `dom_*`, `unused_js/css_*`, `image_audit`, `wp_health_checks`, `filmstrip`, `accessibility_score`, `best_practices_score` — există în schemă (`database/schema/pgsql-schema.sql:1838-1888`) și în `$fillable`/`$casts` (`app/Models/PerformanceTest.php:67-124`), dar `PageSpeedService::parseResults` (liniile 43-65) nu le mai returnează (eliminate în `32d65c6`: „single PageSpeed category, removed filmstrip/opportunities/diagnostics") și nu sunt citite de niciun view | mort → PF-P2-11 |
| `alert_on_poor_vitals` | doar în model (`PerformanceMonitor.php:53,67`) — niciun cititor | mort → PF-P3-1 |
| `getDomColorAttribute` | `PerformanceTest.php:141-155` — `dom_elements` nu se mai populează și accessor-ul nu e folosit în views | mort |
| `updatingSearch()` gol | `PerformanceOverview.php:30-33` — corp gol, comentariu „Reset when search changes" | mort minor |
| `PerformanceDropPlaybook` | înregistrat în `PlaybookRunner.php:28`, dar nimic nu creează incidente cu `IncidentTriggerType::PerformanceDrop` (grep pe tot `app/` — enum-ul nu e instanțiat nicăieri în afara definiției) | orfan → PF-P3-3 |

### Alte inconsistențe de corectitudine

- Modalul de setări oferă „Manual only" (`resources/views/livewire/sites/detail/site-performance.blade.php:482`), dar validarea cere `in:daily,weekly,monthly` (`app/Livewire/Traits/WithPerformanceSettings.php:34`) → selectarea „Manual only" **eșuează mereu la validare**; invers, `monthly` e acceptat de validare dar nu există în UI și nu e gestionat de `match` din job (`RunPerformanceTest.php:278-282` → cade pe `default => null`). `day_of_week` e salvat (`WithPerformanceSettings.php:42`) dar ignorat la calculul `next_test_at` (weekly = `now()->addWeek()`, `RunPerformanceTest.php:280`).
- Graficul „Score History": etichetele sunt datele unice din mobile+desktop concatenate (`SitePerformance.php:294-299`), dar seturile de date sunt array-uri brute per device (liniile 301-312) — dacă un device are mai puține teste (ex. un test failed), punctele se **decalează față de axele de timp** și ordinea etichetelor nu mai e cronologică.
- `trendSummary` filtrează `whereNull('performance_page_id')` (`SitePerformance.php:231,237`) fără fallback pe pagina primară (spre deosebire de toate celelalte query-uri, ex. liniile 107-111) → după ce configurezi pagini, cardurile „30d avg" dispar definitiv.
- `performance_pages.url` e `varchar(255)` (`pgsql-schema.sql:1808`), dar `addPage` validează `max:500` (`SitePerformance.php:381`) → URL de 256-500 caractere = eroare SQL 22001 (500 în UI).
- `PageSpeedService.php:25`: `'category' => ['PERFORMANCE']` se serializează prin `http_build_query` ca `category[0]=PERFORMANCE` — parametru invalid pentru API; funcționează doar pentru că PSI folosește implicit categoria PERFORMANCE. De aceea `accessibility_score`/`best_practices_score` nu pot fi populate în forma actuală.
- `testAllSites` sare peste site-urile cu `is_connected = false` (`PerformanceOverview.php:70`), deși PSI nu are nevoie de connector — site-urile deconectate rămân netestate fără motiv.

---

## Siguranța operațiilor distructive

Modulul **nu execută operații distructive** pe site-urile clienților (doar citește PSI și scrie local). Note adiacente:

- `wire:confirm` există pe „Test All Sites" (`performance-overview.blade.php:5`) — singura acțiune „în masă".
- **Audit logging cine-a-făcut-ce**: rulările manuale (`runTest`, `testAllSites`) nu înregistrează utilizatorul nicăieri — `ActivityLogger` e folosit doar pentru drop-uri (`app/Services/ActivityLogger.php:221-233`). Pentru un tool intern e acceptabil, dar inconsecvent cu restul aplicației (PF-P3-6).
- **Idempotență/locking**: `ShouldBeUnique` cu `uniqueId = 'perf-test-'.$monitor->id` (`RunPerformanceTest.php:20,37-40`) previne rulări paralele pe același monitor. Dar fără `uniqueFor`, lock-ul nu are expirare: dacă workerul moare brutal (OOM, kill -9) în timpul jobului, lock-ul rămâne **permanent** și monitorul nu mai poate fi testat până la golirea cache-ului (PF-P2-8).
- Atenție tangentă: `PerformanceDropPlaybook` (modulul 14) ar executa `flush_cache` + `db_cleanup` pe site-ul live ca reacție la un drop — azi e orfan, dar dacă cineva îl conectează la alertele de drop actuale (măsurătoare unică, false pozitive), ar declanșa operații pe site-uri live pe baza zgomotului PSI.

---

## Securitate

Entry points și starea authz:

| Entry point | Gate | Verdict |
|---|---|---|
| `GET /performance` (`routes/web.php:136`) | grup `auth, verified, throttle:authenticated` (`routes/web.php:72`) | OK ca acces, dar listează **toate** site-urile fără filtrare pe `canAccessSite` — problemă app-wide (toate overview-urile fac la fel), tratată în `25-security-appwide.md` |
| `GET /sites/{site}/performance` (`routes/web.php:104`) | `mount()` → `authorizeSiteAccess` (`SitePerformance.php:49`, `WithSiteAuthorization.php:13-23`) | OK pentru **citire** |
| Acțiuni Livewire `SitePerformance`: `runTest` (447), `updateSettings` (`WithPerformanceSettings.php:27`), `saveBudgets` (`WithPerformanceBudgets.php:84`), `addPage` (377), `removePage` (403), `setPrimaryPage` (430), `toggleActive` (483), `addCompetitor`/`removeCompetitor` (`WithPerformanceCompetitors.php:14,35`) | doar authz-ul de view din mount; **niciun** `authorizeSiteModification` | **PF-P1-2a** — un *viewer* (`User::isViewer`, blocat explicit în `WithSiteAuthorization.php:33-35`) poate muta tot. Convenția e respectată în module similare: `UptimeOverview.php:63,70,80,87` apelează `authorizeSiteModification` pe fiecare acțiune |
| `PerformanceOverview::testAllSites` (`PerformanceOverview.php:61-78`) | nimic — nici rol, nici acces per-site | **PF-P1-2b** — orice utilizator autentificat (inclusiv viewer sau user restricționat la un singur client, cf. `User::canAccessSite`, `app/Models/User.php:123-137`) pornește teste pentru toate site-urile; fără rate limit (spre deosebire de `runTest`, care are `RateLimiter` 10/oră — `SitePerformance.php:449-453`) |
| Joburi | dispatch doar din UI/scheduler/comenzi; nu deserializare de input extern | OK |

Alte aspecte:

- **SSRF**: URL-urile testate (`PerformancePage.url`, `competitor_urls`) sunt fetch-uite de **Google**, nu de server — nu e vector SSRF. Validare `url|max:500` la addPage (`SitePerformance.php:379-382`) și `url|max:255` la competitori (`WithPerformanceCompetitors.php:16`). OK.
- **Mass assignment**: `$fillable` pe `PerformanceMonitor` include câmpurile de scor cache-uite (`PerformanceMonitor.php:44-62`) — nu e exploatabil azi (nu există `::create($request->all())`), doar igienă.
- **Secrete în loguri**: cheia API e pasată ca query param (`PageSpeedService.php:29`). La `ConnectionException`/timeout Guzzle, mesajul include URL-ul complet cu `key=AIza...`; catch-ul din `RunPerformanceTest.php:97-104` scrie mesajul în `performance_tests.error_message` (afișat în UI la `site-performance.blade.php:37,125`) și îl trimite la `report()`. → PF-P2-5.
- **Injecții**: căutarea din overview escapează LIKE corect (`PerformanceOverview.php:80-83,91-95`). OK.

---

## Igienă queue/job

- Coada `performance` e servită de `supervisor-general` împreună cu `security`, `reports`, `default` (`config/horizon.php:257-269`, `maxProcesses: 2`, balance auto). Un `GenerateReport` lung sau un backlog pe `security` **întârzie** testele de performanță; invers, `testAllSites` pe N site-uri (fiecare job = pagini × 2 device-uri × până la 120 s/apel) poate ocupa ambii workeri zeci de minute și amâna rapoartele. Dacă acest supervisor e blocat, modulul moare complet tăcut (nimic nu monitorizează vechimea testelor).
- **Retries moarte**: `$tries = 2`, `$backoff = [60, 180]` (`RunPerformanceTest.php:26-28`) nu au niciun efect pentru erori API, pentru că `runTestForUrl` prinde `\Exception`, marchează testul `failed` și continuă (liniile 97-104). Jobul „reușește" întotdeauna; singura cale de retry e timeout-ul de job. Nu există `failed()` — dar e aproape inaccesibil oricum.
- **429 / cotă Google**: niciun tratament dedicat — fără citirea `Retry-After`, fără circuit breaker, fără re-încercare. Singura măsură: `sleep(2)` între pagini (`RunPerformanceTest.php:58`), nimic între device-uri sau între joburi paralele. Cu cheie API, cota PSI (~240 req/min) e greu de atins cu 2 workeri; **fără** cheie (config permite null — `PageSpeedService.php:28`), cota anonimă produce 429 în lanț → toate testele `failed` silențios.
- **Timeout**: `timeout = 300` (linia 24) vs. worst case = `pages × 2 device-uri × (până la 120 s API + 2 s sleep)`. De la **2 pagini** în sus, jobul poate depăși 300 s → worker-ul îl omoară la mijloc, attempt 2 reia **de la zero** (rânduri `PerformanceTest` duplicate pentru paginile deja testate) și poate expira din nou. Testele rămase în `status = 'running'` nu sunt curățate niciodată (nu există echivalentul `FixStuckSeoAudits` — `grep -l stuck app/Console/Commands/` → doar SEO/backup), iar `SitePerformance::mount` (liniile 54-62) vede teste `running` → pornește `wire:poll.2s` (`site-performance.blade.php:1`) care nu se mai oprește (`checkTestProgress`, liniile 202-220, cere `activeTests` gol) → **polling infinit la 2 s** pe pagina acelui site. PF-P2-7.
- **Unicitate**: vezi secțiunea anterioară — `ShouldBeUnique` fără `uniqueFor` → lock orfan la crash dur (PF-P2-8).
- Joburile de notificare (`NotifyPerformanceDrop.php:21-25`, `NotifyBudgetViolation.php:23-27`) au tries/timeout/backoff rezonabile pe coada `notifications`. OK.

---

## Error handling & observabilitate

- **Eșecul unui test** e vizibil doar pasiv: rând `failed` cu `error_message` în istoricul din UI + `report($e)` în log. **Nicio notificare** când testele eșuează repetat (ex. cheie API expirată → 100% failed, dar nimeni nu află).
- **Mai rău**: `updateMonitorScores` rulează necondiționat la finalul jobului (`RunPerformanceTest.php:65,248-295`) — chiar dacă **toate** apelurile API au eșuat, `last_tested_at = now()` (linia 292) și scorurile „latest" se re-citesc din ultimul test completat (posibil vechi de luni). Monitorul pare proaspăt testat, scorurile vechi persistă, `previous == latest` → nici alerta de drop nu se declanșează. Eșec perfect silențios.
- **Regresia PF-P1-1 e cazul-școală**: monitorizarea programată s-a oprit la refactorul `32d65c6` și nimic n-a semnalat asta — exact anti-patternul „backupuri care se opresc silențios" din grila de audit, aplicat la performanță. Nu există alertă de staleness („niciun test reușit în ultimele X zile deși frequency=daily").
- `SendDailyDigest` nu include nimic despre performanță (grep pe `app/Jobs/SendDailyDigest.php` → 0 rezultate pentru performance/budget) — deci nici digestul nu ar fi prins oprirea.
- `saveScreenshot` înghite orice excepție fără log (`RunPerformanceTest.php:243-245`) — acceptabil (non-critic), dar comentariul „fail silently" e literal.
- **False pozitive la alerte**: drop-ul compară ultima măsurătoare cu precedenta (`RunPerformanceTest.php:309-334`), fără mediere pe N rulări, fără retest de confirmare, fără cooldown; PSI variază natural ±5-15 puncte pe mobil, deci pragul implicit 10 (`pgsql-schema.sql:1765`) alertează pe zgomot. Bugetele au un debounce corect („doar violări noi" — `array_diff_key`, liniile 184-203), dar sunt inaccesibile (PF-P2-1). Media mobilă pe 7 puncte există **doar în grafic** (`SitePerformance.php:493-506`), nu în logica de alertare.

---

## Teste

**Ce există azi: nimic.** `grep -rn "PerformanceMonitor\|PageSpeed\|PerformanceTest" tests/` → 0 rezultate. Nu există factory-uri folosite, nu există teste Livewire, unit sau de integrare pentru modul.

**Setul minim viabil (6 teste):**

1. **Scheduler dispatch** — un `PerformanceMonitor` activ cu `next_test_at` în trecut primește `RunPerformanceTest` la rularea dispatcher-ului (`Queue::assertPushed`). *Ar fi picat azi și ar fi prins PF-P1-1.*
2. **Authz viewer** — Livewire: user viewer pe `SitePerformance` → `runTest`/`updateSettings`/`removePage` → 403. *Prinde PF-P1-2a.*
3. **Scope testAllSites** — user non-admin cu acces la 1 client → `testAllSites` nu pune în coadă joburi pentru site-urile altor clienți. *Prinde PF-P1-2b.*
4. **Eșec API** — `Http::fake` cu 429: testul e marcat `failed` cu mesaj, `last_tested_at`/scorurile monitorului **nu** maschează eșecul, nu se emite alertă de drop pe date vechi. *Prinde comportamentul silențios.*
5. **Alerta de drop** — `checkAlerts` dispecerizează `NotifyPerformanceDrop` doar când `previous - latest >= threshold`, și nu când unul dintre scoruri e null. Analog pentru debounce-ul bugetelor (`array_diff_key`).
6. **`parseResults` pe fixture JSON PSI reală** — scoruri, conversii ms→s, field data null-safe, page stats.

(+1 opțional: `updateSettings` cu `frequency=manual` trece — azi pică din cauza PF-P2-3.)

---

## Model de date

- **Indexuri**: bune pentru query-urile fierbinți — `performance_tests (performance_monitor_id, tested_at)`, `(site_id, device, tested_at)` (`pgsql-schema.sql:6787-6804`), `performance_monitors (is_active, next_test_at)` (6773-6776, azi nefolosit — vezi PF-P1-1). Query-urile „latest per device + pagina primară" (`RunPerformanceTest.php:256-276`, `SitePerformance.php:100-137`) fac `orWhereHas('page', is_primary)` — subquery pe `performance_pages` per apel; volum mic, acceptabil.
- **N+1**: `PerformanceOverview::render` face eager-loading corect (`with(['site','latestMobileTest','latestDesktopTest'])`, linia 90). `SitePerformance` folosește computed properties cache-uite per request — OK. `competitorComparison` face 2 query-uri per competitor (`WithPerformanceCompetitors.php:56-70`) — max 10 query-uri, tolerabil (și oricum feature-ul e mort).
- **Creștere**: JSON-ul complet PSI **nu** e stocat (doar câmpuri parsate) — bine. În schimb `screenshot_final` (base64, zeci-sute de KB) se salvează în DB **pentru fiecare test**, ambele device-uri, toate paginile (`PageSpeedService.php:63` → `RunPerformanceTest.php:86-89`), deși e folosit o singură dată, pentru thumbnailul desktop al paginii primare. Retenția limitează la 60 de zile implicit (`RetentionPolicyService.php:22-30`, rulată zilnic din `routes/console.php:79-82`), deci creșterea e mărginită, dar bloat inutil (PF-P2-11).
- **Consistență soft-delete**: `Site` e soft-deleted (`app/Models/Site.php:116`); FK `ON DELETE CASCADE` (`pgsql-schema.sql:7766-7802`) acoperă doar hard delete. UI filtrează cu `whereHas('site')`, dar dacă un `RunPerformanceTest` e deja în coadă când site-ul e soft-șters, `$this->monitor->site` întoarce null → `$site->url` aruncă `Error` (`RunPerformanceTest.php:44-52`) → job failed (PF-P3-8).
- **Orfane**: retenția șterge după `created_at` necondiționat — pentru un monitor „manual" netestat de >60 zile dispare tot istoricul, dar scorurile cache-uite de pe `performance_monitors` rămân, deci UI afișează scoruri pentru care nu mai există niciun test (inconsistență cosmetică, PF-P3-7). `performance_page_id` are `ON DELETE SET NULL` — testele paginilor șterse redevin „primary implicit" în query-urile `whereNull(...)`, poluând ușor istoricul paginii principale.

---

## Constatări

| ID | Sev. | Fișiere:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| PF-P1-1 | P1 | `routes/console.php` (absență, tot fișierul); `app/Dispatchers/MonitoringDispatcher.php:21-28`; `app/Jobs/RunPerformanceTest.php:278-294`; `app/Services/ModuleConfigService.php:306-311`; commit `32d65c6` | Dispatch-ul programat al testelor a fost șters la refactor și nemutat nicăieri; `frequency`/`test_time`/`next_test_at` sunt scrise dar niciodată consumate. | Agenția crede că are monitorizare zilnică pe toate site-urile; în realitate scorurile din dashboard/health score/rapoarte lunare sunt de la ultima rulare manuală (posibil de acum luni), iar alertele de drop nu se declanșează niciodată singure. | Adaugă în `MonitoringDispatcher` un `dispatchPerformanceTests()` pe modelul `dispatchSecurityScans` (`is_active` + `next_test_at <= now()`), plus o alertă de staleness. |
| PF-P1-2 | P1 | `app/Livewire/Sites/Detail/SitePerformance.php:447-474,483-491,377-445`; `app/Livewire/Traits/WithPerformanceSettings.php:27-50`; `WithPerformanceBudgets.php:84-102`; `WithPerformanceCompetitors.php:14-45`; `app/Livewire/Performance/PerformanceOverview.php:61-78` | Nicio acțiune mutating din modul nu apelează `authorizeSiteModification` (convenția din `UptimeOverview.php:63-87`); `testAllSites` nu verifică nici rol, nici acces per-site și nu are rate limit. | Un cont *viewer* (sau un user limitat la clientul A) apelează `testAllSites`/`updateSettings`/`removePage` prin Livewire și modifică monitorizarea ori consumă cota API PSI pentru toate site-urile, inclusiv ale clienților la care nu are acces. | `authorizeSiteModification($this->site)` în fiecare acțiune mutating; în `testAllSites`, filtru pe site-urile accesibile + verificare de rol + rate limit. |
| PF-P2-1 | P2 | `app/Livewire/Traits/WithPerformanceBudgets.php:67-102`; `app/Jobs/RunPerformanceTest.php:109-204`; `app/Jobs/NotifyBudgetViolation.php`; commit `65aba5b` | Feature-ul „performance budgets" nu are UI (șters intenționat), deci `monitor->budgets` nu poate fi setat → tot lanțul de verificare/alertare e inaccesibil; trait-ul e cod mort fără validare numerică. | Utilizatorul vede „Budget Violation" ca eveniment configurabil în canalele de notificare (`channel-form.blade.php:102`) dar nu poate defini niciun buget; promisiunea din UI nu se poate onora. | Decizie de produs: reintrodu editorul de bugete (backend-ul e gata) sau șterge trait-ul + `checkBudgets` + mail + eveniment. |
| PF-P2-2 | P2 | `app/Livewire/Traits/WithPerformanceCompetitors.php:47-82`; `resources/views/livewire/sites/detail/site-performance.blade.php:267-343`; `pgsql-schema.sql:1886-1887` | Competitor benchmarking: se pot adăuga max 5 URL-uri, dar niciun job nu creează teste `is_competitor = true` → scorurile competitorilor sunt permanent „—". | Utilizatorul adaugă competitori și așteaptă comparația; tabelul rămâne gol pe vecie, colorând „—" cu roșu/verde pe baza `?? 0`. | Job (săptămânal) care rulează `PageSpeedService::analyze` pe `competitor_urls` și scrie teste cu `is_competitor`; sau elimină secțiunea din UI. |
| PF-P2-3 | P2 | `app/Livewire/Traits/WithPerformanceSettings.php:34,42`; `site-performance.blade.php:482-485`; `app/Jobs/RunPerformanceTest.php:278-282` | Nepotrivire UI/validare/logică: „Manual only" din UI pică validarea (`in:daily,weekly,monthly`); `monthly` validat dar inexistent în UI și negestionat de job; `day_of_week` salvat dar ignorat. | Utilizatorul alege „Manual only" și primește eroare de validare fără explicație; alege „Weekly + Friday" și (dacă PF-P1-1 se repară) testul rulează în orice zi. | Aliniază lista: `in:manual,daily,weekly`, folosește `day_of_week` la calculul `next_test_at`. |
| PF-P2-4 | P2 | `app/Jobs/RunPerformanceTest.php:26-28,97-104`; `app/Services/PageSpeedService.php:32-38` | Erorile API sunt înghițite per-URL → `$tries`/`$backoff` fără efect; 429 netratat (fără retry, fără `Retry-After`, fără circuit breaker); fără cheie API configurată, serviciul rulează silențios pe cota anonimă. | Google răspunde 429 la un vârf (`testAllSites`); toate testele acelei runde sunt marcate `failed`, nu se reîncearcă nimic, nimeni nu e notificat, scorurile rămân vechi. | Tratează 4xx/429 distinct în `PageSpeedService` (excepție tipizată), lasă excepțiile de rată să propage ca jobul să facă retry cu backoff; loghează warning la boot dacă lipsește cheia. |
| PF-P2-5 | P2 | `app/Services/PageSpeedService.php:29,32`; `app/Jobs/RunPerformanceTest.php:98-103`; `site-performance.blade.php:37,125` | Cheia API e în query string; la `ConnectionException` (timeout cURL) mesajul conține URL-ul complet cu `key=...` și e persistat în `performance_tests.error_message` (afișat în UI) și trimis la `report()`. | Un timeout de rețea → `PAGESPEED_API_KEY` apare în clar în loguri și în banner-ul de eroare din pagina Performance, vizibil oricărui utilizator. | Sanitizează mesajul (strip query string) înainte de persist/report, sau prinde `ConnectionException` separat cu mesaj generic. |
| PF-P2-6 | P2 | `app/Jobs/RunPerformanceTest.php:297-335`; `pgsql-schema.sql:1765` | Alerta de drop compară o singură măsurătoare cu precedenta; fără mediere pe N rulări, retest de confirmare sau cooldown — la variabilitatea PSI mobile (±5-15 pct), pragul implicit 10 alertează pe zgomot. | Un test rulat într-un moment de load pe serverul WP scade scorul de la 78 la 66 → alertă Slack + email + activity log „warning"; testul următor revine la 77. Echipa învață să ignore alertele. | Confirmă drop-ul cu o a doua rulare (sau compară media ultimelor 3 teste cu media precedentelor 3) înainte de notificare. |
| PF-P2-7 | P2 | `app/Jobs/RunPerformanceTest.php:24,58,70-107`; `SitePerformance.php:47-63,202-220`; `site-performance.blade.php:1` | Worst-case (≥2 pagini × 2 device-uri × până la 120 s/apel) depășește `timeout=300`; jobul omorât lasă teste `running` pe veci (fără comandă de cleanup gen `FixStuckSeoAudits`), retry-ul duplică testele deja rulate, iar pagina site-ului rămâne cu `wire:poll.2s` infinit. | Monitor cu 3 pagini pe un site lent: jobul expiră de 2 ori, rămân 2-4 rânduri `running`; pagina Performance a acelui site face request Livewire la fiecare 2 secunde pentru orice utilizator care o deschide, permanent. | Mărește/calculează timeout-ul din numărul de pagini sau dispecerizează un job per pagină; comandă programată care marchează `failed` testele `running` mai vechi de 30 min. |
| PF-P2-8 | P2 | `app/Jobs/RunPerformanceTest.php:20,37-40` | `ShouldBeUnique` fără `uniqueFor` → dacă workerul moare brutal în timpul jobului, lock-ul de unicitate rămâne permanent. | După un OOM pe `supervisor-general`, monitorul X nu mai primește niciodată joburi (dispatch-urile sunt aruncate silențios) până la `cache:clear`. | `public int $uniqueFor = 600;` (≥ timeout-ul jobului). |
| PF-P2-9 | P2 | `app/Livewire/Sites/Detail/SitePerformance.php:294-312` | Graficul Score History: etichete = datele unice mobile⊕desktop, dar seriile sunt array-uri brute per device → decalaj puncte/axe când numărul de teste diferă între device-uri, și etichete necronologice. | Un test desktop failed în mijlocul lunii → toate punctele desktop ulterioare apar cu o zi mai devreme pe grafic; clientul/echipa citește trenduri greșite. | Construiește seriile pe o axă comună de timp (mapă dată→scor, null pentru lipsă, `spanGaps`). |
| PF-P2-10 | P2 | `app/Livewire/Sites/Detail/SitePerformance.php:229-239` | `trendSummary` filtrează `whereNull('performance_page_id')` fără fallback pe pagina primară. | Imediat ce adaugi pagini monitorizate, cardurile „Mobile/Desktop 30d avg" dispar definitiv (toate testele noi au `performance_page_id` setat). | Refolosește filtrul standard `whereNull(...)->orWhereHas('page', is_primary)`. |
| PF-P2-11 | P2 | `app/Models/PerformanceTest.php:67-124`; `pgsql-schema.sql:1865-1888`; `app/Services/PageSpeedService.php:43-65`; `app/Jobs/RunPerformanceTest.php:86-89` | ~15 coloane moarte (opportunities, diagnostics, dom_*, unused_*, filmstrip etc.) + `screenshot_final` base64 stocat în DB pentru fiecare test/device/pagină deși e folosit o singură dată pentru thumbnail. | DB bloat constant (zeci-sute de KB per test × device-uri × pagini × 60 zile); confuzie pentru dezvoltatori (coloanele sugerează feature-uri care nu există). | Golește `screenshot_final` după `saveScreenshot`; migrare de drop pentru coloanele moarte sau repopulare deliberată. |
| PF-P2-12 | P2 | `tests/` (absență) | Zero teste automate pentru modul (job, service, Livewire, alerte). | Orice regresie (ca PF-P1-1, care chiar s-a produs) trece neobservată. | Implementați „setul minim viabil" din secțiunea Teste. |
| PF-P3-1 | P3 | `app/Models/PerformanceMonitor.php:53,67`; `ModuleConfigService.php:307-311` | `alert_on_poor_vitals` și `interval_minutes` sunt scrise dar niciodată citite. | Cod/DB mort; `ModuleConfigService` setează interval 10080 fără efect. | Șterge sau implementează. |
| PF-P3-2 | P3 | `SitePerformance.php:381`; `pgsql-schema.sql:1808` | `addPage` validează `max:500` dar coloana e `varchar(255)`. | URL de 300 caractere → SQLSTATE 22001, eroare 500 în loc de mesaj de validare. | `max:255` (sau lărgește coloana). |
| PF-P3-3 | P3 | `app/Services/IncidentResponse/Playbooks/PerformanceDropPlaybook.php`; `app/Enums/IncidentTriggerType.php:12` | Playbook-ul `performance_drop` e înregistrat dar niciun cod nu creează incidente cu acest trigger. | Feature IR promis (flush cache + db cleanup la drop) inexistent în practică. | Conectează `NotifyPerformanceDrop`/`checkAlerts` la crearea unui incident — doar după rezolvarea PF-P2-6 (altfel execută operații pe site live pe zgomot). |
| PF-P3-4 | P3 | `PerformanceOverview.php:70` | `testAllSites` sare site-urile `is_connected = false`, deși PSI nu depinde de connector. | Site-uri valide fără connector nu sunt testate în bulk fără vreun motiv. | Elimină condiția sau documenteaz-o. |
| PF-P3-5 | P3 | `PerformanceOverview.php:30-33` | `updatingSearch()` gol — cod mort. | — | Șterge. |
| PF-P3-6 | P3 | `SitePerformance.php:447-474`; `ActivityLogger.php:221-233` | Rulările manuale nu înregistrează utilizatorul în activity log. | Nu se poate răspunde la „cine a consumat cota API azi-noapte". | `ActivityLogger` la `runTest`/`testAllSites` cu user. |
| PF-P3-7 | P3 | `RetentionPolicyService.php:22-30`; `RunPerformanceTest.php:289-294` | Retenția șterge toate testele >60 zile, dar scorurile cache-uite pe monitor rămân → UI afișează scoruri fără niciun test-sursă. | Monitor „manual" netestat 3 luni: overview arată scor 85, istoricul e gol. | Păstrează ultimul test completat per monitor/device la purge, sau invalidează scorurile cache-uite. |
| PF-P3-8 | P3 | `RunPerformanceTest.php:44-52`; `app/Models/Site.php:116` | Site soft-deleted cu job deja în coadă → `$this->monitor->site` = null → `Error` neelegant. | Job failed cu `Call to a member function on null` în failed_jobs. | Early return dacă `site === null`. |
| PF-P3-9 | P3 | `PageSpeedService.php:22-26` | `'category' => ['PERFORMANCE']` se serializează ca `category[0]=` — parametru invalid, merge doar pentru că PERFORMANCE e default-ul API. | Adăugarea categoriei ACCESSIBILITY nu ar avea niciun efect și ar deruta. | `Http::get(url, [...])` cu query string construit manual pentru parametri repetați. |

**Total: 2 × P1 · 12 × P2 · 9 × P3.** (P0: niciunul — modulul nu poate pierde date de client și nu atinge site-urile live.)

Neverificat: comportamentul exact al cotei Google PSI fără cheie API (documentat public, dar nu am testat live); dacă în producție există monitoare cu `budgets` setate istoric (înainte de ștergerea UI-ului) — ar necesita acces la DB-ul de producție.

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-urile existente

1. **Repornește testarea programată + alertă de staleness** (PF-P1-1) — `dispatchPerformanceTests()` în `MonitoringDispatcher` după modelul security scans, plus notificare „monitor daily fără test reușit de >48h". Aceasta e diferența dintre „avem monitorizare de performanță" și realitatea actuală.
2. **Alerte pe medie/confirmare, nu pe măsurătoare unică** (PF-P2-6) — retest automat la 10 minute înainte de a alerta, sau compararea mediilor pe 3 rulări; benchmark: niciun tool serios (SpinupWP, MainWP Lighthouse extension) nu alertează pe un singur run Lighthouse.
3. **Reînvie editorul de bugete** (PF-P2-1) — backend-ul (verificare, debounce „doar violări noi", mail, canale) e complet; lipsesc ~70 de linii de blade șterse în `65aba5b`. Valoare mare, efort minim.
4. **Finalizează competitor benchmarking** (PF-P2-2) — un job săptămânal care testează `competitor_urls` (max 5 × 2 device-uri = 10 apeluri API/site/săptămână); diferențiator real în rapoartele către clienți.
5. **Igienizare stocare** (PF-P2-11) — golește `screenshot_final` după generarea thumbnailului și droppează coloanele moarte; reduce dimensiunea DB și timpul de backup al aplicației.

### (b) Propuneri de feature-uri noi

1. **CrUX/INP trend tracking cu alertă pe tranziții good→needs-improvement→poor** — field data (FCP/LCP/CLS/INP/TTFB) e deja parsată și stocată per test (`PageSpeedService.php:95-107`); lipsesc doar un grafic istoric pe field data și praguri de alertă pe percentila reală a utilizatorilor — mai stabilă și mai relevantă pentru SEO decât scorul lab. *Rațiune: datele există deja în DB, iar clienții întreabă de Core Web Vitals „oficiale", nu de scorul Lighthouse.* **Efort: M**
2. **Corelare automată drop ↔ update-uri** — markerele de `UpdateLog` sunt deja pe grafic (`SitePerformance.php:140-163`); extinde `NotifyPerformanceDrop` să includă „update-uri în ultimele 48h înainte de drop" (plugin X 1.2→1.3), transformând alerta din zgomot în diagnostic acționabil. *Rațiune: ManageWP/MainWP nu fac asta; datele sunt deja în aceleași tabele.* **Efort: S**
3. **Secțiune „Recomandări de optimizare" în raportul lunar** — repopulează `opportunities` jsonb (top 5 audituri Lighthouse cu economii estimate, deja suportate de schemă) și randează-le în `PerformanceGatherer`; agenția vinde astfel lucrări de optimizare pe baza rapoartelor. *Rațiune: coloana și pipeline-ul de raport există; doar parsarea a fost scoasă la refactor.* **Efort: M**
