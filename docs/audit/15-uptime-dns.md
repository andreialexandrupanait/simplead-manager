# 15 — Uptime & DNS Monitoring

**Data:** 2026-07-02 · **Auditor:** Claude (audit modul, Faza 1) · **Scope:** `Models/UptimeMonitor|UptimeCheck|UptimeIncident|DnsMonitor|DnsChange`, `Jobs/CheckUptime.php`, `Jobs/CheckDns.php`, `Services/DnsSelectorDiscoveryService.php`, `Livewire/Uptime/*`, `Livewire/Dns/*`, `Livewire/Sites/Detail/SiteUptime.php`, `Livewire/Components/UptimeBar.php|UptimeStatsCard.php`, `Commands/BackfillMonitors.php|BackfillDnsMonitors.php`, `Dispatchers/MonitoringDispatcher.php` — ~1.450 LOC nucleu.

---

## Rezumat executiv

1. **Un site care „atârnă" (răspunde lent, >~30s) nu este detectat niciodată ca down.** Timeout-ul HTTP al monitorului (default 30s, configurabil până la 120s — `app/Livewire/Forms/MonitorFormData.php:21`) este ≥ timeout-ul job-ului `CheckUptime` (30s — `app/Jobs/CheckUptime.php:29`) și al supervisorului Horizon `supervisor-uptime` (30s — `config/horizon.php:216`). Horizon omoară job-ul înainte ca Guzzle să dea timeout → nu se salvează niciun check, starea monitorului îngheață pe „up", fără nicio alertă. Exact scenariul pentru care există uptime monitoring eșuează silențios.
2. **Circuit breaker-ul altor module oprește silențios uptime monitoring-ul.** Eșecuri repetate la sync WP / Analytics / SEO / backup (ex. token Google expirat) deschid circuitul și pot seta `is_monitoring_disabled=true` permanent; `MonitoringDispatcher` sare peste acei monitori. În plus, site-urile fără rând `site_health_states` (create prin CLI `BulkAddSites`) nu sunt dispecerate deloc — nici uptime, nici DNS, nici security.
3. **Anti-flapping incomplet:** incidentul se creează la primul eșec, dar notificarea „recovered" se trimite pentru orice incident, inclusiv cele care n-au atins pragul de alertare → spam de „recovered after 5m" fără vreun „down" corespunzător la site-uri care oscilează.
4. **Authz lipsă pe majoritatea acțiunilor de scriere Livewire** (creare/editare monitor, ferestre de mentenanță, DNS): un utilizator cu rol Viewer poate modifica monitori, inclusiv să seteze URL-uri arbitrare — primitivă SSRF (oracle boolean prin keyword check) în rețeaua Docker internă.
5. **DNS: un eșec tranzitoriu de rezolvare este raportat ca „înregistrări șterse"** → alertă falsă de schimbare DNS + a doua alertă la revenire.
6. Un singur punct de observație (`primary`, același VPS Hetzner), fără suprimare de furtună de alerte; retenția default de 45 zile face `uptime_365d` (și potențial `uptime_30d`) fictive; zero teste pentru întreg modulul.

---

## Inventar & corectitudine

**Ce face modulul azi:**
- **Uptime:** `MonitoringDispatcher` (rulează pe minut din `routes/console.php:17-21`) selectează monitori activi cu `next_check_at` scadent (`app/Dispatchers/MonitoringDispatcher.php:30-41`) și dispecerizează `CheckUptime` pe coada `uptime` (2 workeri, timeout 30s, tries 3 — `config/horizon.php:208-218`). Job-ul face un request HTTP (GET/HEAD/POST) cu UA propriu (`app/Jobs/CheckUptime.php:107-137`), verifică status code contra `accepted_status_codes` (default `[200,201,202,203,204,301,302]` — linia 144), opțional keyword exists/not_exists (157-174), salvează un rând `uptime_checks`, actualizează starea (Up/Degraded/Down după `alert_after_failures`), recalculează statistici 24h/7d/30d/365d printr-un agregat SQL (226-266), gestionează incidente și sincronizează `sites.is_up` + `uptime_percentage` (69-72).
- **DNS:** `CheckDns` (coada `default`, tries 1, timeout 90s — `app/Jobs/CheckDns.php:24-34`) rezolvă A/AAAA/MX/NS/CNAME/TXT prin `dns_get_record` (105-144), plus DMARC (`_dmarc.<domain>`) și DKIM prin selectori descoperiți (manual + Cloudflare + Postmark + listă fallback de 22 selectori comuni — `app/Services/DnsSelectorDiscoveryService.php:12-18`). Diff normalizat (case-insensitive, sortat, `json_encode`) între `previous_records`/`current_records`, persistă `DnsChange` și notifică `dns_changed` severitate `info` + `ActivityLogger` (64-94). Interval default 6h (`app/Services/ModuleConfigService.php:110`).
- Monitori se creează automat la crearea site-ului prin plan (`app/Models/Site.php:204-215` → `ModuleConfigService::createModuleRecord`, `app/Services/ModuleConfigService.php:287-292,326-333`), din UI (`ConfigureMonitor`, `addMonitorsForAllSites`) sau backfill CLI (`dns:backfill-monitors`).

**Cod mort / feature-uri fantomă (verificate prin grep pe tot `app/` + `resources/`):**
- `type = 'ping'` e acceptat de validare (`MonitorFormData.php:15,47`) dar `performCheck()` face întotdeauna HTTP — nu există nicio ramură ping (`CheckUptime.php:89-182`). Opțiune înșelătoare în UI.
- **Monitorizare SSL inexistentă în modul:** `uptime_checks.ssl_expires_at` există în schemă (`database/schema/pgsql-schema.sql:4192`) dar nu e scris nicăieri; docblock-ul modelului declară `check_ssl`/`ssl_expiry_threshold` (`app/Models/UptimeMonitor.php:34-35`) care **nu există în DDL-ul `uptime_monitors`** (schema 4258-4301) — docblock stale.
- `check_locations` + `require_all_locations_down` (schema 4300-4301, fillable `UptimeMonitor.php:118-119`) — complet nefolosite; `LOCATIONS` conține doar `primary` (`UptimeMonitor.php:78-80`).
- `alert_contacts` e citit (`app/Jobs/NotifyIncident.php:64`) dar nu e scris de nimic — nu există UI; rutarea per-monitor a alertelor e de facto moartă.
- `accepted_status_codes`, `http_headers`, `http_body`, `auth_type/username/password/token` — funcționale în job dar fără niciun UI de configurare (formularul acoperă doar url/type/interval/timeout/method/redirects/keyword/prag — `ConfigureMonitor.php:55-66`); editabile doar prin DB.
- `UptimeOverview::updatedSearch()` e goală (`UptimeOverview.php:34-37`) — nu resetează pagina (spre deosebire de `DnsOverview.php:173-176`); căutarea de pe pagina >1 poate afișa rezultate goale.
- `BackfillMonitors` (`app:backfill-monitors`) creează azi **doar performance monitors** (`app/Console/Commands/BackfillMonitors.php:25-46`) — numele comenzii și plasarea în modulul de uptime sunt înșelătoare.
- Fix anterior relevant: `da6d254` a rezolvat false-positive-uri DNS din ordonarea cheilor JSONB; clasa de false-positive-uri din eșec de rezolvare (vezi D-P1-5) rămâne.

**TODO-uri:** niciun `TODO/FIXME` în fișierele modulului (grep negativ). Comentariul „External locations will be added as probe integrations are built" (`UptimeMonitor.php:75-77`) marchează feature-ul multi-locație ca promis, nelivrat.

---

## Siguranța operațiilor distructive

Modulul nu execută operații pe site-urile WP (marcat corect „N" în harta modulelor). Riscurile de „distructivitate" sunt indirecte:

- **`SiteWentDown` declanșează `RunIncidentResponse`** (`app/Listeners/TriggerIncidentResponse.php:14-26`, gated pe `config('incident-response.enabled')`) — adică un fals-pozitiv de uptime poate porni playbook-uri AI de remediere pe un site sănătos. Fiabilitatea detecției down (vezi U-P1-1, P2-1) este deci și o problemă de siguranță pentru modulul 14.
- **`deleteMonitor` șterge istoric ireversibil fără confirmare:** FK-urile `uptime_checks`/`uptime_incidents` sunt `ON DELETE CASCADE` (schema 8398-8410); un click în UI (`UptimeOverview.php:84-89`) șterge tot istoricul de uptime al site-ului. Nu există soft-delete, confirmare server-side sau audit log.
- **Audit logging:** inexistent pentru create/edit/delete/pause monitor, ferestre de mentenanță, editare selectori DKIM. Singura urmă e `ActivityLogger` la schimbări DNS detectate (`CheckDns.php:86-93`). Nu se poate răspunde la „cine a pus monitorul X pe pauză înainte de incidentul de weekend".
- **Locking/idempotență:** `ShouldBeUnique` pe ambele job-uri previne rulări concurente pe același monitor (`CheckUptime.php:23,39-42`; `CheckDns.php:20,37-40`) — corect. Dar `saveCheck()` rulează înaintea restului pipeline-ului (`CheckUptime.php:54`): dacă `updateUptimeStats()` aruncă (ex. deadlock), retry-ul (tries=3) duplică rândul de check — idempotență parțială, impact minor (statistici ușor distorsionate).

---

## Securitate

**Rute:** ambele pagini globale sunt în grupul `auth`+`verified` (`routes/web.php:71`, 147, 159), la fel `sites/{site}/uptime` (107). Nicio rută publică — OK.

**Authz pe acțiuni Livewire — inconsistent, majoritatea scrierilor neprotejate:**

| Entry point | Authz | Dovadă |
|---|---|---|
| `UptimeOverview::pauseMonitor/resumeMonitor/testMonitor/deleteMonitor` | ✅ `authorizeSiteModification` | `UptimeOverview.php:63,70,80,87` |
| `UptimeOverview::openMaintenanceModal/setMaintenanceWindow/clearMaintenanceWindow` | ❌ niciuna | `UptimeOverview.php:91-122` |
| `UptimeOverview::addMonitorsForAllSites` | ❌ niciuna | `UptimeOverview.php:129-144` |
| `ConfigureMonitor::save` (create + edit monitor) | ❌ niciuna | `ConfigureMonitor.php:45-80` |
| `SiteUptime::pauseMonitor/resumeMonitor/testNow` | ❌ doar `authorizeSiteAccess` la mount (Viewer trece) | `SiteUptime.php:32,62-73` |
| `DnsOverview::acknowledge/recheckAll/rediscoverSelectors/saveSelectors` | ❌ niciuna (componenta nu folosește trait-ul deloc) | `DnsOverview.php:74-171` |

Rolul Viewer există explicit (`app/Models/User.php:152-155`, `WithSiteAuthorization.php:33-35` — „Viewers cannot modify sites"), deci intenția e read-only; toate metodele de mai sus o încalcă.

- **SSRF:** `url` e validat doar `required|url|max:2048` (`MonitorFormData.php:12`) — orice utilizator autentificat (inclusiv Viewer, via `ConfigureMonitor::save` fără authz) poate crea un monitor spre `http://redis:6379`, `http://pgbouncer:...`, metadata endpoints etc. Cu `keyword_type=exists` obține un **oracle boolean asupra corpului răspunsului** (`CheckUptime.php:157-174`), iar `status_code`/`failure_reason` sunt afișate în UI. Platformă internă, dar rețeaua Docker (redis, pgbouncer) devine sondabilă din browser. Nu există blocklist de IP-uri private/loopback.
- **Mass assignment:** `UptimeMonitor::$fillable` include tot, inclusiv `consecutive_failures`, `current_state`, statisticile (`UptimeMonitor.php:82-120`) — dar intrările din Livewire trec prin array-uri construite explicit (`ConfigureMonitor.php:55-66`), deci nu e exploatabil azi; e doar fragil.
- **Secrete:** `auth_password`/`auth_token` sunt `encrypted` la nivel de cast (`UptimeMonitor.php:131-132`) — corect. `sanitizeErrorMessage()` scoate căile din mesajele de eroare înainte de persistare (`CheckUptime.php:318-324`) — bun; dar regex-ul `/\/[^\s]+/` maschează și URL-ul țintă, nu doar căi locale (cosmetic).
- **Injecții:** căutările folosesc `escapeLike` + bindings (`UptimeOverview.php:152-155`, `DnsOverview.php:194`) — OK. Selectorii DKIM sunt validați strict `^[a-z0-9._-]+$` ≤63 chars înainte de a fi interpolați în query-ul DNS (`DnsOverview.php:146`) — OK.
- **`http_method` dinamic:** `$request->$method(...)` (`CheckUptime.php:134-137`) e apel de metodă arbitrar pe clientul HTTP, dar valoarea e restrânsă la `GET|HEAD|POST` de validare (`MonitorFormData.php:24`); rândurile scrise direct în DB nu sunt validate — risc doar teoretic.

---

## Igienă queue/job

- **`CheckUptime`:** `tries=3, timeout=30, backoff=[30,60,120]` (`CheckUptime.php:27-31`), `ShouldBeUnique`, coadă dedicată `uptime` cu prag `waits` de 30s (`config/horizon.php:102`). **Dar:** tot `performCheck()` e într-un `catch (\Exception)` (176-179) — eșecurile HTTP nu declanșează niciodată retry-ul de job; retry-urile există doar pentru erori de infrastructură (DB/Redis). Nu există re-verificare imediată înainte de a înregistra un check eșuat — „confirmarea" down-ului e exclusiv `alert_after_failures` × `interval_minutes` (default 3×5 = ~15 min până la alertă).
- **Conflict timeout job vs timeout HTTP (U-P1-1):** vezi Rezumat; `$this->monitor->timeout` (5-120s) alimentează direct `Http::timeout()` + `connectTimeout()` (`CheckUptime.php:107-108`) în timp ce job-ul și supervisorul au 30s fix.
- **`CheckDns`:** `tries=1, timeout=90` (`CheckDns.php:24-26`). Cu până la ~25 selectori DKIM + 6 tipuri + DMARC = ~32 apeluri `dns_get_record` sincron; un resolver care face timeout (~5s+retry per apel) depășește ușor 90s → SIGKILL înainte ca `next_check_at` să fie avansat (update-ul e în interiorul `try`, linia 56-62) → re-dispatch pe minut, worker `general` ars la nesfârșit, monitor blocat silențios.
- **`ShouldBeUnique` fără `uniqueFor`** pe ambele job-uri: la un crash dur (OOM/SIGKILL în afara ciclului normal de fail), lock-ul unic din Redis nu are TTL → monitorul respectiv **nu mai e verificat niciodată**, fără niciun semnal. (Comportament Laravel standard: lock-ul se eliberează doar la complete/fail explicit.)
- **Coadă blocată:** dacă Horizon moare, `horizon:health-check` alertează la 5 min cu dedup 1h (`app/Console/Commands/HorizonHealthCheckCommand.php:19-42`) — bun. Dacă doar coada `uptime` e saturată (nu moartă), `next_check_at` rămâne în trecut și dispatcher-ul redepune (dedupat de `ShouldBeUnique`), deci sistemul se auto-recuperează — corect proiectat.
- **Maintenance window:** early-return fără avansarea `next_check_at` (`CheckUptime.php:46-48`) → pe toată durata mentenanței, job dispecerizat + no-op **în fiecare minut** per monitor. Zgomot, nu pericol.
- **`failed()`:** doar `JobTracker::fail(...)` (`CheckUptime.php:84-87`) — vizibil numai dacă cineva are pagina site-ului deschisă; `CheckDns` nu are `failed()` deloc.

---

## Error handling & observabilitate

- **Eșecul verificării site-ului este vizibil** (check `is_up=false`, incident, alertă la prag) — dar **eșecul mecanismului de verificare este invizibil**: job killed la timeout → niciun check scris, stare înghețată (U-P1-1); circuit breaker deschis → monitor sărit de dispatcher (`MonitoringDispatcher.php:36-39`) fără nicio alertă când `is_monitoring_disabled` devine `true` — doar `Log::warning` (`app/Services/CircuitBreakerService.php:79,94`). Nu există niciun mecanism de tip „monitor X nu a mai fost verificat de >N×interval" (dead-man's switch), deși `uptime_monitors.last_checked_at` are index (schema 7343).
- **CheckDns eșuat = `Log::warning` + avansare `next_check_at`** (`CheckDns.php:95-102`): un domeniu care nu se mai rezolvă (NS stricat — exact ce ar trebui să prindă monitorizarea DNS!) nu produce nicio alertă; UI arată la nesfârșit ultimele înregistrări cunoscute, fără câmp `last_error`.
- **Alertare down:** `SiteWentDown` → `NotifyIncident('down')` severitate `critical` (`app/Jobs/NotifyIncident.php:40`) → ocolește quiet-hours (`NotificationService.php:46-48`) — corect. **Recovery** are severitate `success` → **suprimat în quiet hours**: down noaptea (alertat), recuperare noaptea (nealertat) → dimineața echipa crede că site-ul e încă jos până se uită în UI.
- Dedup notificări 5 min per event+site (`NotificationService.php:20`) — atenuează parțial spamul, dar nu flapping-ul cu perioadă >5 min.
- `sites.is_up` devine `false` de la primul check eșuat (starea Degraded nu e `Up` — `CheckUptime.php:69-72`), iar dashboard-ul numără `Site::where('is_up', false)` ca „sites down" (`app/Services/DashboardService.php:32`) → dashboard-ul contrazice logica de prag a alertelor.

---

## Teste

**Ce există azi:** zero teste pentru modul. Singura atingere e `tests/Feature/Services/IncidentResponderServiceTest.php` (folosește `IncidentTriggerType::SiteDown` — modulul 14). Există factories nefolosite: `database/factories/UptimeMonitorFactory.php`, `UptimeIncidentFactory.php`. Nimic pentru `CheckUptime`, `CheckDns`, `MonitoringDispatcher`, componentele Livewire. (Notă: sesiunile anterioare de testing menționate în memoria proiectului nu se regăsesc în `tests/` pentru acest modul — verificat prin listare directă.)

**Setul minim viabil (6):**
1. `CheckUptime` cu HTTP 500 fake (`Http::fake`): la al `alert_after_failures`-lea eșec se dispecerizează `SiteWentDown` **exact o dată** și starea devine Down; sub prag → Degraded, fără event.
2. Recuperare după incident **ne-alertat** (1 eșec → up): nu se trimite notificare de recovery (azi ar pica — prinde U-P1-3 și orice regresie la fix).
3. Monitor cu `timeout` 60s + răspuns care durează: job-ul nu depășește timeout-ul Horizon / checkul e totuși înregistrat (prinde U-P1-1).
4. `CheckDns` cu `dns_get_record` eșuat tranzitoriu (mock): nu se creează `DnsChange` și nu se notifică „records deleted" (prinde D-P1-5).
5. Livewire: `ConfigureMonitor::save` și `DnsOverview::saveSelectors` ca Viewer → 403 (prinde U-P1-4).
6. `MonitoringDispatcher`: site fără `SiteHealthState` → monitorul e totuși dispecerizat (azi ar pica; documentează comportamentul dorit — prinde U-P1-2b).

---

## Model de date

- **Indexuri:** `uptime_checks (monitor_id, checked_at)` + `(monitor_id, checked_at, is_up)` (schema 7301, 7308) acoperă perfect agregatul din `updateUptimeStats` și `UptimeBar`; `idx_dns_monitors_active (is_active, next_check_at)` (6601) acoperă dispatcher-ul DNS. Lipsește un index compus `uptime_monitors (status, next_check_at)` pentru query-ul dispatcher-ului — irelevant la sute de monitori, dar gratuit de adăugat. `dns_changes` a primit indexuri în `2026_05_15_000001`.
- **Volum & retenție:** la interval 5 min → ~105k checks/an/monitor; la minimul de 3 min → ~175k. Retenția `uptime` default **45 zile** (min 7, max 365 — `app/Services/RetentionPolicyService.php:13-21`, rulată zilnic la 03:00, `routes/console.php:79-82`) ține tabela sub control, **dar** `updateUptimeStats` calculează `uptime_365d` pe fereastra de 365 zile (`CheckUptime.php:231`) → cu retenția default, „365d" e de fapt „45d"; dacă un admin coboară retenția sub 30 zile (permis, min 7), **și `uptime_30d` — cifra raportată clienților și sincronizată în `sites.uptime_percentage` — devine fictivă**, fără niciun avertisment în UI-ul de retenție.
- **Fără retenție:** `dns_changes` și `uptime_incidents` nu apar în `RetentionPolicyService::CATEGORIES` — cresc nemărginit (volum mic, dar inconsecvent).
- **Agregatul pe fiecare check:** `updateUptimeStats` scanează toate check-urile din 365 zile la **fiecare** rulare (pe minut la interval minim, per monitor) — index-only scan, acceptabil azi, dar cost O(istoric) per check; un snapshot incremental ar fi mai sănătos la scară.
- **N+1 / query-uri per rând:** `UptimeOverview::render` face eager-load `site` (OK), dar fiecare rând montează `<livewire:components.uptime-bar>` (`resources/views/livewire/uptime/uptime-overview.blade.php:196`) care rulează propriul query pe 24h de checks (`app/Livewire/Components/UptimeBar.php:19-22`) → **50 query-uri + 50 componente copil per pagină**. `DnsOverview::stats()` încarcă toți monitorii activi cu tot JSONB-ul la fiecare render (`DnsOverview.php:32`) plus încă 3 query-uri de count (59-65).
- **Soft-delete / orfani:** `sites` are soft-delete; monitorii site-urilor soft-deleted rămân (FK `ON DELETE CASCADE` se declanșează doar la ștergere hard) dar sunt corect excluși de dispatcher (`whereNull('deleted_at')`, `MonitoringDispatcher.php:35,68`) și de UI (`whereHas('site')`). Consistent.
- `dns_monitors.site_id` e `UNIQUE` (schema 5422) — 1:1 cu site-ul, domeniul derivat din `site.url` fără `www.` (`ModuleConfigService.php:327-328`); subdomeniile/domeniile secundare nu sunt acoperite.

---

## Constatări

| ID | Sev | Fișier:linii | Descriere |
|---|---|---|---|
| U-P1-1 | P1 | `app/Jobs/CheckUptime.php:29,107-108`; `app/Livewire/Forms/MonitorFormData.php:21`; `config/horizon.php:216` | Timeout HTTP al monitorului (default 30s, max 120s) ≥ timeout job/worker (30s). **Scenariu:** site-ul clientului acceptă conexiunea dar nu răspunde (PHP-FPM saturat); Guzzle așteaptă 30s, Horizon omoară job-ul la 30s înainte de excepție → niciun check salvat, `consecutive_failures` neincrementat, stare rămasă „up", `next_check_at` stale → buclă kill/redispatch pe minut, **fără alertă, la nesfârșit**. **Remediere:** timeout HTTP plafonat la `min(monitor->timeout, job_timeout - 5)` și/sau `$timeout` de job derivat din monitor; validare `timeout < 25`. |
| U-P1-2 | P1 | `app/Dispatchers/MonitoringDispatcher.php:36-39`; `app/Services/CircuitBreakerService.php:77-95`; `app/Jobs/FetchAnalyticsData.php:117`, `app/Jobs/RunSeoAudit.php:75` etc. | Circuit breaker-ul e deschis de eșecuri ale job-urilor de sync/analytics/SEO/backup, iar dispatcher-ul sare monitorii cu circuit `open` sau `is_monitoring_disabled`. **Scenariu:** token GA expirat → 3 eșecuri `FetchAnalyticsData` → circuit open 60 min (uptime suspendat); 3 deschideri în 24h → `is_monitoring_disabled=true` **permanent**, doar `Log::warning` — site-ul poate cădea zile întregi nealertat. **Remediere:** exceptarea `CheckUptime` de la filtrarea pe circuit (uptime e exact sonda care trebuie să ruleze când restul eșuează) + alertă `critical` la `is_monitoring_disabled=true`. |
| U-P1-2b | P1 | `app/Dispatchers/MonitoringDispatcher.php:36-39`; `app/Livewire/Sites/CreateSiteWizard.php:171`; `app/Console/Commands/BulkAddSites.php` (fără healthState — grep negativ) | `whereHas('site.healthState', ...)` exclude complet site-urile fără rând `site_health_states` (create prin CLI sau alte căi decât wizard-ul). **Scenariu:** site adăugat cu `BulkAddSites` → nici uptime, nici DNS (prin `DataSyncDispatcher` analog, liniile 39,58,89), nici security nu rulează vreodată, silențios. **Remediere:** `whereDoesntHave` OR-uit (tratarea lipsei ca „closed") sau backfill `SiteHealthState` la `Site::created`. |
| U-P1-3 | P1 | `app/Jobs/CheckUptime.php:272-291,294-315`; `app/Listeners/NotifySiteRecovered.php:13-15`; `app/Jobs/NotifyIncident.php:33-86` | Incidentul se creează la **primul** eșec, dar `handleRecovery` dispecerizează `SiteRecovered` pentru orice incident ongoing, fără să verifice că down-ul a fost vreodată notificat (`notified_at`). **Scenariu:** site care oscilează (1-2 eșecuri sub pragul 3, apoi up, repetat) → ploaie de notificări „✅ recovered after Xm" fără niciun „🔴 down", plus incidente-fantomă în istoricul folosit de `UptimeStatsCard` (downtime umflat). **Remediere:** notifică recovery doar dacă `incident->notified_at !== null`; opțional marchează incidentele sub prag drept `blip`. |
| U-P1-4 | P1 | `app/Livewire/Uptime/ConfigureMonitor.php:45-80`; `app/Livewire/Uptime/UptimeOverview.php:91-144`; `app/Livewire/Sites/Detail/SiteUptime.php:62-73`; `app/Livewire/Dns/DnsOverview.php:74-171`; `app/Livewire/Forms/MonitorFormData.php:12` | Lipsă totală de authz pe acțiunile de scriere enumerate (contrast: `pauseMonitor` etc. din `UptimeOverview:63-87` sunt protejate). Un Viewer poate crea/edita monitori, seta ferestre de mentenanță (mascând downtime), edita selectori DKIM. Combinat cu validarea `url` fără blocklist → **SSRF cu oracle boolean** (keyword check) către rețeaua Docker internă. **Remediere:** `authorizeSiteModification()` pe toate metodele de scriere + respingerea URL-urilor care rezolvă în IP-uri private/loopback. |
| D-P1-5 | P1 | `app/Jobs/CheckDns.php:119-125,229-249,74-84` | `dns_get_record() === false` (eșec resolver) e tratat identic cu „zero înregistrări" → diff-ul raportează tot setul ca șters. **Scenariu:** timeout tranzitoriu al resolverului VPS → alertă „DNS Records Updated: A, MX, NS, TXT" (toate „șterse"), apoi la următoarea rulare reușită a doua alertă (toate „re-adăugate"); `DnsChange` poluat cu schimbări false. **Remediere:** la `false`, marchează tipul drept „unknown" și exclude-l din diff (sau abandonează comparația rundei). |
| U-P2-1 | P2 | `app/Jobs/CheckUptime.php:69-72`; `app/Services/DashboardService.php:32` | `sites.is_up=false` de la primul check eșuat (Degraded ≠ Up) → dashboard-ul „sites down" și `overall_status` afișează down înainte de confirmarea pragului — false-pozitive vizibile pentru toată echipa. Remediere: `is_up = current_state !== Down`. |
| U-P2-2 | P2 | `app/Services/RetentionPolicyService.php:13-21`; `app/Jobs/CheckUptime.php:231-251` | `uptime_365d` calculat pe fereastră de 365 zile cu retenție default 45 zile = statistică fictivă; retenție <30 zile (permisă, min 7) corupe și `uptime_30d` raportat clienților. Remediere: plafonează ferestrele afișate la retenția efectivă sau agregă snapshot-uri zilnice înainte de purge. |
| U-P2-3 | P2 | `app/Models/UptimeMonitor.php:78-80`; `app/Jobs/CheckUptime.php:191` | Un singur punct de observație (același VPS). O problemă de rețea Hetzner/resolver local marchează down toate site-urile simultan → furtună de alerte critice + incidente false în masă; nu există suprimare de tip „>N monitori down în același minut = problemă locală". |
| U-P2-4 | P2 | `app/Jobs/CheckUptime.php:23,39-42`; `app/Jobs/CheckDns.php:20,37-40` | `ShouldBeUnique` fără `uniqueFor`: lock orfan după crash dur (OOM/SIGKILL în afara fluxului normal de fail) → monitorul nu se mai verifică niciodată, silențios. Remediere: `public int $uniqueFor = 300;`. |
| D-P2-5 | P2 | `app/Jobs/CheckDns.php:95-102` | Eșecul verificării DNS = `Log::warning` + avans `next_check_at`; fără `last_error` pe monitor, fără alertă. Un domeniu cu NS mort (exact incidentul-țintă al monitorizării DNS) rămâne „verde" cu date stale la nesfârșit. |
| D-P2-6 | P2 | `app/Jobs/CheckDns.php:24-26,105-202` | Până la ~32 lookup-uri DNS sincrone într-un job cu `timeout=90, tries=1`; resolver lent → SIGKILL înainte de update-ul `next_check_at` (linia 56) → redispatch pe minut, worker `general` consumat continuu, monitor blocat. |
| U-P2-7 | P2 | `app/Livewire/Uptime/UptimeOverview.php:84-89`; schema `8398-8410` | `deleteMonitor` șterge cascadă tot istoricul (checks+incidents) fără confirmare server-side și fără audit log — pierdere ireversibilă de date SLA la un click greșit. |
| U-P2-8 | P2 | `resources/views/livewire/uptime/uptime-overview.blade.php:196`; `app/Livewire/Components/UptimeBar.php:19-22`; `app/Livewire/Dns/DnsOverview.php:32,59-65` | 50 componente `UptimeBar` per pagină, fiecare cu query propriu pe 24h de checks; `DnsOverview::stats()` încarcă toate JSONB-urile la fiecare render. Funcțional, dar scump per request. |
| U-P2-9 | P2 | `app/Services/Notifications/NotificationService.php:46-48`; `app/Jobs/NotifyIncident.php:40` | Recovery (severity `success`) suprimat în quiet hours în timp ce down (`critical`) trece → stare percepută greșit dimineața. Remediere: recovery pentru incidente notificate ar trebui să ocolească quiet hours. |
| U-P3-1 | P3 | `app/Livewire/Forms/MonitorFormData.php:15,47`; `app/Jobs/CheckUptime.php:89-182` | Tipul `ping` acceptat în formular dar neimplementat (face HTTP). De eliminat sau implementat. |
| U-P3-2 | P3 | `app/Models/UptimeMonitor.php:34-35,118-119`; schema `4192` | Feature-uri fantomă: `check_ssl`/`ssl_expiry_threshold` în docblock dar nu în schemă; `ssl_expires_at` niciodată scris; `check_locations`/`require_all_locations_down` moarte; `alert_contacts` citit (`NotifyIncident.php:64`) dar nescris nicăieri. |
| U-P3-3 | P3 | `app/Jobs/CheckUptime.php:46-48` | Early-return în maintenance window fără avans `next_check_at` → dispatch + no-op pe minut pe toată durata mentenanței. |
| U-P3-4 | P3 | `app/Livewire/Uptime/UptimeOverview.php:77-82` vs `app/Livewire/Sites/Detail/SiteUptime.php:78-84` | `testMonitor` din overview fără rate limit (pagina de detaliu are 10/h) — inconsecvent. |
| U-P3-5 | P3 | `app/Livewire/Uptime/UptimeOverview.php:34-37` | `updatedSearch()` gol — nu resetează paginarea la căutare. |
| U-P3-6 | P3 | `app/Jobs/CheckUptime.php:147-150,158` | Doar `cf-mitigated: challenge` e tratat ca fals-403; alte blocări Cloudflare (bot fight mode, rate limit al UA-ului de monitor) produc fals down. Body-ul întreg e încărcat în memorie pentru keyword check (worker de 64MB). |
| D-P3-7 | P3 | `app/Services/RetentionPolicyService.php:12-112` (absență); `app/Models/DnsChange.php` | `dns_changes` și `uptime_incidents` fără politică de retenție — creștere nemărginită (volum mic). |
| U-P3-8 | P3 | `app/Livewire/Forms/MonitorFormData.php:18` vs `app/Services/ModuleConfigService.php:87` | Formularul permite `interval_minutes` min 1; `MIN_INTERVALS['uptime']=3` e aplicat doar pe fluxul de plan — inconsecvență. |
| U-P3-9 | P3 | `app/Console/Commands/BackfillMonitors.php:12-16` | Comanda `app:backfill-monitors` creează doar performance monitors — nume înșelător; uptime backfill trăiește în `UptimeOverview::addMonitorsForAllSites`. |

**Total: 0×P0 · 6×P1 · 9×P2 · 9×P3.**

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-urile existente

1. **Dead-man's switch pe pipeline-ul de monitoring** — un task programat care alertează `critical` când `last_checked_at < now() - 3×interval_minutes` pentru monitori activi (indexul pe `last_checked_at` există deja, schema 7343). Închide dintr-o lovitură clasa de eșecuri silențioase din U-P1-1/2/2b/P2-4. (Efort S)
2. **Expune în UI câmpurile deja funcționale** — `accepted_status_codes`, `http_headers`, auth basic/bearer și `alert_contacts` (rutare per-monitor a alertelor) sunt implementate în job dar neconfigurabile; formularul din `ConfigureMonitor` acoperă ~60% din model. (Efort S-M)
3. **Monitorizare SSL reală** — coloanele și pragurile sunt pe jumătate schițate (`ssl_expires_at` nescris); un `stream_socket_client` cu captură de certificat la fiecare N checks + alertă la <14 zile ar completa feature-ul fantomă — standard la SpinupWP/ManageWP. (Efort M)
4. **Câmp `last_error` + badge de „stale" pe DnsMonitor** — diferențiază „verificat, neschimbat" de „nu am putut verifica de 3 zile" (D-P2-5); include alerta la eșec repetat de rezolvare. (Efort S)
5. **Snapshot zilnic de uptime** (tabelă mică `uptime_daily_stats`: monitor_id, date, up_count, total, avg_rt) populată înainte de retenție → `uptime_365d` corect, grafice istorice ieftine, `updateUptimeStats` O(1). (Efort M)

### (b) Feature-uri noi

1. **A doua locație de sondare (probe extern)** — un worker minimal (Cloudflare Worker / VPS ieftin în alt datacenter) interogat prin HTTP; schema are deja `check_locations` + `require_all_locations_down` și `uptime_checks.location`. Elimină clasa întreagă de false-pozitive dintr-un singur punct de observație (U-P2-3) — diferențiatorul de bază al UptimeRobot/ManageWP față de soluțiile self-hosted naive. **Efort M.**
2. **Corelare down ↔ context „ce s-a schimbat"** — la `SiteWentDown`, atașează în alertă ultimele evenimente din `ActivityLogger`/`update_logs` pentru site (update de plugin acum 20 min, push de securitate etc.); datele există deja în platformă, e doar un join în `NotifyIncident`. Scurtează dramatic MTTR-ul agenției. **Efort S.**
3. **Alertă de expirare domeniu (RDAP/WHOIS)** — `DnsMonitor` are deja domeniul normalizat și un ciclu de 6h; un lookup RDAP săptămânal cu prag 30/14/7 zile acoperă incidentul cel mai jenant posibil pentru o agenție (domeniul clientului expiră) — feature standard în MainWP/WPMU DEV. **Efort S-M.**
