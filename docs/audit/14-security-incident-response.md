# 14 — Security scanning & incident response

**Data:** 2026-07-02 · **Auditor:** agent senior Laravel/securitate · **Scope:** modulul 14 din `00-module-map.md` — scanare de securitate, hardening, useri WP, incident response cu remediere AUTOMATĂ (distructivă) + agent AI. Cod comis + necomis. Evaluare pe dovezi; numerele de linie verificate în fișiere.

---

## Rezumat executiv

Modulul este surprinzător de bine gardat pe partea de siguranță a operațiilor distructive: incident response are cooldown, rate-limit orar, plafon de acțiuni/apeluri AI și backup-forțat-înainte-de-distructiv, iar prompt-ul de sistem interzice explicit ștergerea de plugin-uri/teme. Nu am găsit un P0 de tipul „AI șterge un site de client fără om în buclă". Totuși există trei probleme serioase:

1. **`RunIncidentResponse` are `tries=1` + `timeout=900` și rulează job-uri distructive SINCRON (`dispatchSync`) în interiorul lui.** Dacă workerul e ucis (OOM, deploy, timeout) la mijloc, incidentul rămâne blocat non-terminal, iar `ShouldBeUnique` cu lock implicit poate bloca reîncercările — remedierea se poate opri silențios la jumătate (P1).
2. **Autorizarea pe acțiunile distructive din UI e doar „acces la site", NU „modificare".** Un `viewer` care poate accesa site-ul poate declanșa scanări, ștergerea de useri spam (`DeleteSpamUsersJob`), aplicarea de setări de hardening și push la plugin — `authorizeSiteModification()` există dar NU e apelat în modulul de securitate (P1).
3. **Agentul AI expune un tool `apply_security_fix` cu `key` string arbitrar, fără allowlist pe partea Laravel;** validarea reală există doar în plugin-ul WP. Combinat cu faptul că playbook-urile trimit chei (`disable_debug`, `reinstall_core`) care NU sunt implementate în plugin, `SecurityCriticalPlaybook` eșuează întotdeauna silențios (P1 pentru injecția de chei; P2 pentru bug-ul de corectitudine).

Feed de vulnerabilități: Wordfence Intelligence v2, cache 24h — sursă bună, dar fără fallback și fără notificare la eșecul fetch-ului (degradare silențioasă). Alerting pe incidente există (Slack/notificări), dar `DeleteSpamUsersJob`, `PushSecuritySettings` și integritatea temelor au puncte oarbe. Modelul AI hard-codat în config e `claude-sonnet-4-20250514`, deprecat (retragere 2026-06-15).

---

## Inventar & corectitudine — ce face de fapt modulul

### Tipuri de scanare și ce detectează REAL
`SecurityScanService::runChecks()` (`app/Services/SecurityScanService.php:99-109`) rulează patru verificări:
- **Versiune WP** — comparație `version_compare` cu `config('security.wordpress.recommended_version')` (6.4) / `minimum_version` (6.0), din `env(WP_RECOMMENDED_VERSION/WP_MIN_VERSION)` (`config/security.php:14-15`). Praguri hard-codate, se învechesc singure (WP e la 6.8+ azi).
- **Vulnerabilități plugin** — deleagă la `VulnerabilityCheckService::check()` (`SecurityScanService.php:135-146`).
- **Integritate core** — NU scanează live; citește ultimul `CoreFileCheck` deja existent (`SecurityScanService.php:156`), deci scorul depinde de un job separat care poate fi vechi.
- **Debug mode** — apel live `getInfo()` la connector (`SecurityScanService.php:190-203`).

Integritatea temelor (`ThemeIntegrityService`, `CheckThemeIntegrity`) și integritatea core stau pe baseline/checksums oficiali WP.org (`CoreFileIntegrityService::fetchOfficialChecksums`, `.php:19-31`).

### Feed de vulnerabilități — sursă & prospețime
`VulnerabilityCheckService::fetchPluginVulnerabilities()` (`app/Services/VulnerabilityCheckService.php:129-145`): `https://www.wordfence.com/api/intelligence/v2/vulnerabilities/software/plugin/{slug}`, `Cache::remember` 86400s (24h). CVSS→severitate în `.php:147-160`. Doar plugin-uri; teme și core WP NU sunt verificate pentru CVE. La eșec HTTP întoarce `[]` fără să marcheze diferența dintre „curat" și „nu am putut verifica" (fals negativ silențios).

### Cod mort / feature pe jumătate
- **`app/Livewire/Sites/Detail/Security/SecurityComingSoon.php`** — NU e referit în `routes/web.php` și în nicio blade (grep doar `vendor/` + fișierul propriu). Cod mort.
- **`CheckThemeIntegrity` / `ThemeIntegrityService`** — nu sunt dispecerizate de nicăieri (grep: doar definiția lor). Feature complet, dar neconectat la UI sau scheduler — mort funcțional.
- **`PerformanceDropPlaybook` și `DatabaseCriticalPlaybook`** — există și sunt înregistrate în `PlaybookRunner`, dar `IncidentResponseDispatcher` dispecerizează DOAR `Vulnerability` și `SecurityCritical` (`app/Dispatchers/IncidentResponseDispatcher.php:27-28`). Triggerele `PerformanceDrop`/`DatabaseCritical` nu sunt emise de nicăieri (grep pe `RunIncidentResponse::dispatch` → doar 3 puncte: uptime `SiteDown`, + cele două din dispatcher). Deci două playbook-uri sunt neacoperite.
- **`SecurityRecommendationService::fix()`** (`.php:78-115`) — aplică fix-uri de securitate dar NU e apelat din niciun component Livewire (grep pe `RecommendationService` în `app/Livewire` → doar `ReportRecommendationService`, altă clasă). Calea de auto-fix a recomandărilor pare orfană din UI.

### TODO-uri
Nu am găsit markere `TODO`/`FIXME` în fișierele modulului.

---

## Siguranța operațiilor distructive

### Ce e bine
- **Backup forțat înainte de distructiv:** `IncidentActionExecutor::ensureBackupIfDestructive()` (`.php:92-100`) rulează `createBackup` dacă acțiunea e în `config('incident-response.safety.destructive_actions')` (`deactivate_plugin`, `rollback_plugin`, `update_plugin`, `update_core`, `db_cleanup`) și `always_backup_before_destructive=true`.
- **Prompt-ul AI interzice ștergerea:** „Never delete plugins or themes — only deactivate" (`app/Services/IncidentResponse/AiAgentService.php:194`). Toolset-ul AI NU expune `delete_plugin` (`.php:241-365`) — deci limitarea e și structurală, nu doar în prompt.
- **Plafoane:** `max_actions_per_incident=10`, `max_ai_calls_per_incident=5`, `cooldown_minutes=30`, `max_incidents_per_site_per_hour=3` (`config/incident-response.php:36-49`), verificate în `IncidentResponderService::isInCooldown/hasExceededHourlyLimit` (`.php:118-135`) și `hasReachedActionLimit/AiCallLimit`.
- **Locking incident:** `RunIncidentResponse implements ShouldBeUnique`, `uniqueId = incident-response-{site}-{trigger}` (`app/Jobs/RunIncidentResponse.php:37-40`) — două job-uri pe același site+trigger nu rulează simultan.
- **Protecție last-admin la ștergere useri:** plugin-ul WP refuză ștergerea ultimului administrator, atât în `delete_user` cât și `bulk_delete_users` (`wordpress-plugin/.../class-users-endpoint.php:214-217, 287-293`), și decrementează `admin_count` corect în buclă.
- **Audit logging cine-a-făcut-ce:** `ActivityLogger::log()` setează `user_id => auth()->id()` (`app/Services/ActivityLogger.php:25`). `IncidentResponseAction` persistă `action_type`, `tier` (`playbook`/`ai_agent`), `parameters`, `result`, `status`, `duration_ms`, `sequence` (`IncidentActionExecutor.php:75-85`) — trail bun pe partea de incident.

### Probleme (vezi și Constatări)
- **Idempotență/atomicitate la eșec de worker** — vezi S-P1-1.
- **`DeleteSpamUsersJob` NU are `ShouldBeUnique`** (`app/Jobs/DeleteSpamUsersJob.php:20`). Două declanșări rapide din UI pot rula în paralel pe același site, procesând chunk-uri suprapuse. Reasignarea conținutului merge la primul admin; ștergerea e idempotentă pe partea WP (user deja șters → „User not found"), dar `tries=1` fără unicitate lasă loc de curse pe progres/logging (P2).
- **Audit al ACTORULUI uman pentru incident lipsește parțial** — `IncidentResponse` nu stochează cine a declanșat manual (nu există declanșare manuală din UI oricum); dispecerizarea e automată (scheduler/listener), deci „cine" = sistemul. Acceptabil, dar `ActivityLogger` din `IncidentResponderService::respond` NU e apelat cu user (rulează în job, `auth()->id()` = null) — trail-ul arată acțiune „de sistem", corect dar de documentat.

### Clasificarea acțiunilor de playbook după reversibilitate
| Playbook | Acțiuni | Reversibil? |
|---|---|---|
| `SiteDownPlaybook` | `check_site_up`, `run_diagnostic`, `deactivate_plugin`, `fix_elementor`, `flush_cache` | Deactivate = reversibil (activate); backup forțat înainte |
| `VulnerablePluginPlaybook` | `update_plugin` (via SafeUpdate, cu backup + health check) | Update = greu reversibil, dar SafeUpdateService creează rollback point |
| `SecurityCriticalPlaybook` | `apply_security_fix('disable_debug'/'reinstall_core')` | **Chei neimplementate în plugin → eșuează mereu** (vezi S-P1-3 / S-P2-1) |
| `PerformanceDropPlaybook` | `flush_cache`, `db_cleanup` | `db_cleanup` = DISTRUCTIV ireversibil (șterge revizii, spam, transient, orphaned meta) — backup forțat; playbook neconectat la trigger |
| `DatabaseCriticalPlaybook` | `db_cleanup` | idem — distructiv, backup forțat, neconectat |

Nicio acțiune de playbook nu șterge plugin/temă/user; cea mai distructivă e `db_cleanup`, cu backup forțat înainte. Corect proiectat.

---

## Securitate — authz pe fiecare entry point

### Rute UI (`routes/web.php:83-93, 142-144`)
Toate sub `['auth','verified','throttle:authenticated']`. Componentele apelează `authorizeSiteAccess($site)` în `mount()` — verificat în toate cele 8 pagini `Sites/Detail/Security/*` (grep: 8/8 au `authorizeSiteAccess`). `security.presets` are în plus `middleware('role:admin')` (`web.php:144`). `security.index` (dashboard global) NU are middleware de rol, dar `SecurityDashboard::scopedSiteQuery()` filtrează pe `user_id` pentru non-admini (`app/Livewire/Security/SecurityDashboard.php:31-35`) — corect.

### Acțiuni Livewire distructive — authz insuficient (S-P1-2)
`authorizeSiteAccess()` verifică doar `canAccessSite()` (`app/Livewire/Traits/WithSiteAuthorization.php:12-23`). Există `authorizeSiteModification()` care refuză viewerii (`.php:25-38`), dar **NU e apelat în niciun component de securitate** (grep: folosit doar în Updates/Seo/Uptime/Backups/PluginManagement). Prin urmare un `viewer` cu acces la site poate:
- `SecurityUsers::deleteSpamUsers()` → `DeleteSpamUsersJob` (ștergere useri WP remote) (`.php:269-287`)
- `SecurityUsers::deleteUser()`, `createUser()`, `updateUser()` (`.php:125-223`)
- `SecurityHardening::save()` → push setări la plugin (`.php:175-191`)
- `SecurityIpManagement::addIp/removeIp/unbanIp/saveFirewallSettings` (`.php:89-180`)
- `SecurityScanning::scanNow/checkCoreIntegrityNow` (rate-limitate, dar declanșabile)

Depinde dacă rolul „viewer" e efectiv folosit pentru clienți/staff junior; dacă da, e escaladare de privilegii pe operații distructive pe site-uri live.

### API agent HMAC (`routes/api.php:36-44`, `app/Http/Middleware/AuthenticateAgent.php`)
- **Auth:** `site_token` în URL = `Site.api_key`, căutat cu `Site::where('api_key', $siteToken)` (`AuthenticateAgent.php:23`). Coloană `encrypted` cast (`app/Models/Site.php:177`). **Atenție:** cu Laravel encryption, `where('api_key', ...)` pe o coloană criptată NU funcționează determinist (fiecare criptare produce alt ciphertext cu IV random) — dacă `api_key` chiar e criptat la rest, lookup-ul ar eșua. Fie coloana nu e criptată în practică (cast aplicat după seed vechi), fie funcționează accidental. **Neverificat pe DB reală** — merită investigat (S-P2-2). Dacă lookup-ul funcționează, înseamnă că encryption e efectiv un no-op pentru căutare.
- **HMAC:** `X-Signature = hash_hmac('sha256', timestamp.'.'.body, api_secret)`, fereastră 5 min pe timestamp, `hash_equals` (`.php:30-49`). Corect implementat, anti-replay pe timestamp.
- **Entropie token:** neverificat — nu am găsit generarea `api_key`/`api_secret` în cod (`BulkAddSites`/wizard nu au fost în scope). Dacă sunt `Str::random(32)` sau mai mult, ok.
- **Rate limiting:** `throttle:agent` = 120/min pe `agent:{site_token}` (`AppServiceProvider.php:72-76`), plus `throttle:agent-activity-logs` separat pe logs.
- **Cross-site injection:** un site compromis cunoaște DOAR propriul `api_key`+`api_secret`, iar `SecurityAgentController` operează exclusiv pe `$site` rezolvat din token (`commandResults` face `$site->securityCommands()->find(...)`, `.php:54`). Nu poate injecta rezultate pe alt site. **Bine izolat.** Poate însă injecta loguri false/rezultate false pentru PROPRIUL site (`activityLogs` → `ingestLogs` fără validare de conținut dincolo de trunchiere, `SecurityActivityService::ingestLogs`, `.php:14-49`) — dar asta afectează doar propriul site și e inerentă modelului agent.

### Mass assignment
`Site` are `api_key`, `api_secret`, `ai_context` în `$fillable` (`app/Models/Site.php:130-131, 159`). `SecurityAgentController` nu face `Site::create/update` din input agent, deci fără risc aici. `SecuritySettingsService::applySetting` validează `category/key` contra `VALID_SETTING_KEYS` (`.php:80-90`) înainte de `updateOrCreate` — bun.

### SSRF pe URL fetch-uite
- `IncidentActionExecutor::checkSiteUp()` face `Http::get($site->url)` (`.php:117-141`) — URL controlat de admin la înregistrarea site-ului, nu de input runtime. Risc SSRF scăzut, dar `$site->url` nu e re-validat.
- `SecurityRecommendationService::checkHttpHeaders` (`.php:135`) și `CoreFileIntegrityService` fetch la `api.wordpress.org` — host fix.
- `AiAgentService::callClaude` la `api.anthropic.com` — host fix.
Fără SSRF exploatabil din input de user negrijuliu.

### Injecție cheie AI (S-P1-3)
Tool-ul `apply_security_fix` acceptă `key` string liber de la modelul AI (`AiAgentService.php:320-329`); `IncidentActionExecutor::applySecurityFix` îl trimite neschimbat la plugin (`.php:254-262`), fără allowlist Laravel. Plugin-ul validează printr-un `switch` cu 5 chei (`class-security-endpoint.php:284-303`), deci damage-ul e limitat la ce implementează plugin-ul — dar principiul „defense in depth" lipsește pe partea Laravel, iar AI-ul poate cere chei arbitrare.

### Secrete în loguri
`PushSecuritySettings::buildPayload` decriptează `captcha.secret_key` (`.php:139-146`) și îl trimite la plugin — nu îl loghează. Dar la eșec, `Log::warning('PushSecuritySettings failed', ['body' => $response->body()])` (`.php:66-70`) — dacă plugin-ul ecoează payload-ul înapoi, secretul CAPTCHA ar putea ajunge în log. Neverificat ce conține `body` la eșec (P3).

---

## Igienă queue/job

| Job | tries | timeout | backoff | failed() | unique |
|---|---|---|---|---|---|
| `RunIncidentResponse` | 1 | 900 | — | da (JobTracker::fail) | da |
| `RunSecurityScan` | 2 | 300 | [60,180] | da (circuit breaker) | da |
| `CheckCoreFileIntegrity` | 2 | 120 | [30,60] | da | da |
| `CheckThemeIntegrity` | 2 | 120 | [30,60] | da | da (nedispecerizat) |
| `PushSecuritySettings` | 3 | 60 | [10,30,60] | ❌ lipsă `failed()` | da |
| `PullSecurityActivityLogs` | 2 | 30 | — | ❌ lipsă `failed()` | ❌ |
| `DeleteSpamUsersJob` | 1 | 600 | — | da | ❌ |

- **`RunIncidentResponse tries=1`** + rulează `dispatchSync` pentru backup/update/rollback în interior. La kill de worker (deploy, OOM), incidentul rămâne blocat non-terminal, iar `respond()` prinde excepția și marchează `markFailed` DOAR dacă workerul supraviețuiește (`IncidentResponderService.php:64-71`). Dacă e ucis, nu (S-P1-1).
- **Coada `incident-response`:** supervisor dedicat, `tries=1`, `timeout=900`, `maxProcesses=2` (`config/horizon.php:270-281, 305-307`). Dacă e blocată (2 job-uri lungi de 15 min), incidentele noi așteaptă — dar cooldown-ul de 30 min oricum le rărește. Impact acceptabil.
- **`PushSecuritySettings` fără `failed()`:** la epuizarea celor 3 tries, `markAllFailed` din `handle` nu se mai apelează (excepția aruncată la `.php:80` sare peste); setările rămân în stare „aplicate în DB dar neconfirmate", iar utilizatorul nu vede eroarea decât prin scorul recalculat. Degradare silențioasă (P2).
- **`RunSecurityScan tries=2`** dar `SecurityScanService::scan` face `SecurityScan::create` + `SecurityIssue::update` — la retry după eșec parțial se pot crea scanări duplicate. Neidempotent, dar `ShouldBeUnique` reduce ferestrele.

---

## Error handling & observabilitate

### Alerting existent (bun)
- Scor securitate <50 → `notifySiteEventSlim('security_score_critical', severity: critical)` cu deep-link (`SecurityScanService.php:60-69`).
- Vulnerabilitate critical/high nouă → notificare Slack-slim (`VulnerabilityCheckService.php:91-101`).
- Integritate core modificată → `notifySiteEvent('core_files_modified', severity: critical)` (`CoreFileIntegrityService.php:122-136`).
- Rezultatul incidentului (resolved/escalated/failed) → `NotificationService::notifySiteEvent` (`IncidentResponderService.php:137-167`).
- Comandă de securitate critică eșuată → `Log::critical` (`SecurityCommandService.php:77-84`).

### Puncte oarbe (backupuri/remedieri care se opresc silențios)
1. **`RunSecurityScan::failed()` → doar `CircuitBreaker::recordFailure` + `JobTracker::fail`** (`.php:55-59`). NU trimite notificare. Dacă scanările de securitate se opresc (circuit breaker deschis, connector picat), nimeni nu e alertat — scorul rămâne înghețat la ultima valoare. Un site care devine vulnerabil între scanări e invizibil (S-P1-4, „backupuri care se opresc silențios sunt mai rele decât lipsa lor").
2. **`PullSecurityActivityLogs` fără `failed()`** — logurile de securitate (login-uri eșuate, brute-force) pot înceta să vină fără alertă.
3. **`ThemeIntegrityService`/`CheckThemeIntegrity`** — cod complet, neconectat; integritatea temelor nu e monitorizată deloc în practică.
4. **`VulnerabilityCheckService::fetchPluginVulnerabilities`** — la eșec Wordfence întoarce `[]`, tratat identic cu „fără vulnerabilități". Fals negativ silențios; niciun contor „ultima verificare reușită".

---

## Teste

### Ce există AZI (`tests/`)
- `Feature/Services/IncidentResponderServiceTest.php` — 7 teste: config dezactivat, cooldown, limită orară, rezolvare playbook, escaladare, creare record, triggere diferite.
- `Feature/Services/IncidentActionExecutorTest.php` — 11 teste: limită acțiuni, acțiune necunoscută, run_diagnostic/flush_cache, înregistrare acțiune, increment count, excepție→failed, secvență, check_site_up, plugin_id lipsă.
- `Unit/Services/IncidentResponse/AiAgentServiceTest.php` — 8 teste.
- `Unit/Services/IncidentResponse/PlaybookRunnerTest.php` — 9 teste.
- `Feature/Services/SecurityCommandServiceTest.php` — 9 teste.
- `Feature/Jobs/DeleteSpamUsersJobTest.php` — 2 teste (șterge record local la succes; păstrează la eșec WP).

**Lipsă total:** zero teste pentru `SpamUserDetectionService` (euristica de scoring!), `SecuritySettingsService`, `PushSecuritySettings`, `SecurityScanService`, `VulnerabilityCheckService`, componentele Livewire (authz, deleteSpamUsers), `AuthenticateAgent` (HMAC), `SecurityAgentController`.

### Setul minim viabil (cele 3-7 teste care prind cele mai periculoase regresii)
1. **`SpamUserDetectionService::detect` — teste de fals-pozitiv:** un `subscriber` real (never logged in + no orders + no posts = scor 4) NU trebuie flagat (prag 5); un admin NU e niciodată flagat; un client WooCommerce cu comenzi NU e flagat. **Cel mai important test din modul** — un fals pozitiv = user real șters de pe site-ul clientului.
2. **Authz distructiv:** un `viewer` care apelează `SecurityUsers::deleteSpamUsers` / `SecurityHardening::save` primește 403 (după fix-ul S-P1-2).
3. **`AuthenticateAgent`:** semnătură HMAC invalidă → 401; timestamp expirat (>5min) → 401; token de site A + semnătură cu secret B → 401; cross-site (token A, încearcă command_id al site B) → ignorat.
4. **`RunIncidentResponse` idempotent:** re-rulare după eșec parțial nu dublează acțiunile/backupurile.
5. **`SecurityScanService::calculateScore`:** penalitățile pe severitate produc scorul așteptat; scor<50 declanșează notificarea.
6. **Plugin `bulk_delete_users`:** nu poate șterge ultimul admin nici în masă.
7. **`DeleteSpamUsersJob` chunking:** 120 useri → 3 chunk-uri, contorii cumulativi corecți, cache `spam_scan_{id}` invalidat la final.

---

## Model de date

### Indexuri pe query-uri fierbinți (bine acoperit)
`pgsql-schema.sql`: `security_activity_logs` are 5 indexuri (event_type, ip, site+category, site+event_type, site+occurred_at — `:6895-6923`); `security_commands` (site+category+action, site+status, status+picked_up — `:6944-6958`); `security_issues` (site+severity+is_fixed+is_ignored — `:6972`); `security_monitors` (is_active+next_scan_at — `:6979`); `vulnerability_alerts` (severity — `:7364`). Query-urile fierbinți (`getPendingCommands` ORDER BY priority — `SecurityCommandService.php:19-24`; dispatcher `is_active + next_scan_at` — `MonitoringDispatcher.php:45-47`) sunt acoperite.

### Inconsistență de coloană `status` (S-P1-5)
`ContextGatherer::securityIssues()` (`.php:90-97`) și `IncidentResponseDispatcher::dispatchSecurityCriticalResponses()` (`.php:66-67`) filtrează `SecurityIssue` pe `where('status', '!=', 'fixed')` / `'ignored'`, DAR tabela `security_issues` **NU are coloană `status`** — are `is_fixed` și `is_ignored` boolean (`pgsql-schema.sql:2556-2572`; model `app/Models/SecurityIssue.php:46-47, 69-71` cu scope `active()` pe `is_fixed=false AND is_ignored=false`). Un `where('status', ...)` pe o coloană inexistentă în PostgreSQL aruncă `SQLSTATE 42703 column does not exist`. **Aceasta rupe atât `IncidentResponseDispatcher` (dispecerizarea SecurityCritical — întreaga cale de incident response pentru probleme de securitate) cât și `ContextGatherer` (contextul AI).** Verificat: `SecurityScanService` folosește corect `->active()` (`.php:56-58, 236-240`), deci scanarea merge; dar întregul flux de incident-response pentru securitate crapă la primul query. Similar, `ContextGatherer::vulnerabilities()` selectează `plugin_slug`, `cvss_score` (`.php:99-104`) dar tabela are `software_slug`, fără `cvss_score` (`pgsql-schema.sql:4371-4388`) — al doilea query rupt. **P1, posibil P0 funcțional** (dacă `incident-response.enabled=true`, întreaga remediere de securitate e moartă la runtime).

### N+1 în componente Livewire
- `SecurityDashboard::sites()` folosește `withCount` + subquery corelat pentru `last_security_sync` (`.php:76-91`) — fără N+1.
- `SecurityUsers::render` paginează direct — ok. `roleCounts`/`availableRoles` sunt `#[Computed]` cache-uite.
- `ContextGatherer::gather` face `loadMissing(['sitePlugins','siteThemes'])` (`.php:14-18`) — eager, bun.

### Soft-delete / orfani
`DeleteSpamUsersJob` șterge `SiteUser` local doar pt userii confirmați ștersi de WP (`.php:110-112`) — consistent. Nu am găsit soft-delete pe entitățile de securitate; ștergerile sunt hard, cu invalidare cache. `security:maintenance expired-bans/prune-logs` curăță retenția (`SecurityMaintenanceCommand.php:26-29`).

---

## Constatări

| ID | Sev | Fișier:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| **S-P1-5** | **P1** (posibil P0 funcțional) | `app/Dispatchers/IncidentResponseDispatcher.php:67`, `app/Services/IncidentResponse/ContextGatherer.php:94,103` | Query-uri pe coloane inexistente: `SecurityIssue.status` (tabela are `is_fixed`/`is_ignored`), `VulnerabilityAlert.plugin_slug`/`cvss_score` (tabela are `software_slug`, fără `cvss_score`) | Cu `incident-response.enabled=true`, `dispatchSecurityCriticalResponses` aruncă `42703 column does not exist` → întreaga remediere automată de securitate e moartă; `ContextGatherer` la fel pentru contextul AI | Înlocuiește `where('status','!=','fixed')...` cu scope-ul `->active()`; corectează `plugin_slug`→`software_slug` și elimină `cvss_score` din `select` |
| **S-P1-1** | P1 | `app/Jobs/RunIncidentResponse.php:23-25`, `app/Services/IncidentResponse/IncidentActionExecutor.php:220` (`dispatchSync`) | `tries=1` + acțiuni distructive rulate SINCRON în job; la kill de worker la mijloc, incidentul rămâne non-terminal, remedierea se oprește la jumătate fără marcare `failed` | Deploy/OOM în timpul unui `update_plugin` sincron → plugin actualizat parțial, incident „diagnosing" pe veci, `failed()` marchează doar JobTracker nu și `IncidentResponse.status` | Marchează `IncidentResponse` failed în `RunIncidentResponse::failed()`; ia în calcul `tries=1` intenționat + heartbeat/checkpoint pe acțiuni |
| **S-P1-2** | P1 | `app/Livewire/Sites/Detail/Security/SecurityUsers.php:63,269`, `SecurityHardening.php:46,175`, `SecurityIpManagement.php:40`, `WithSiteAuthorization.php:25-38` | Operații distructive (ștergere useri spam, push hardening, ban IP, scanări) protejate doar cu `authorizeSiteAccess` (viewer permis), nu `authorizeSiteModification` | Un `viewer` cu acces la site declanșează `DeleteSpamUsersJob` (ștergere useri WP live) sau push setări la plugin | Apelează `authorizeSiteModification($site)` în acțiunile de mutație (nu în `mount`); ideal o `SitePolicy` |
| **S-P1-3** | P1 | `app/Services/IncidentResponse/AiAgentService.php:320-329`, `IncidentActionExecutor.php:254-262` | Tool AI `apply_security_fix` acceptă `key` arbitrar; Laravel îl trimite fără allowlist; validarea există doar în plugin | Model AI (sau prompt-injection prin loguri/context de la un site compromis) cere o cheie neintenționată; singura barieră e switch-ul plugin-ului | Allowlist explicit de chei permise în `IncidentActionExecutor::applySecurityFix` înainte de apel |
| **S-P1-4** | P1 | `app/Jobs/RunSecurityScan.php:55-59`, `app/Jobs/PullSecurityActivityLogs.php` (fără `failed()`) | Eșecul persistent al scanării de securitate / pull-ului de loguri nu alertează pe nimeni; scorul îngheață | Connector picat + circuit breaker deschis → scanările se opresc silențios; un site devine vulnerabil fără nicio notificare | Notificare la `failed()` pentru scanări; contor „ultima scanare reușită" + alertă la stale |
| **S-P2-1** | P2 | `app/Services/IncidentResponse/Playbooks/SecurityCriticalPlaybook.php:50,60`, `wordpress-plugin/.../class-security-endpoint.php:284-303` | Playbook-ul trimite cheile `disable_debug`/`reinstall_core`, dar plugin-ul implementează doar `disable_file_editor`/`disable_xmlrpc`/`hide_wp_version`/`prevent_php_uploads`/`disable_trackbacks` → `SecurityCriticalPlaybook` eșuează întotdeauna | Orice incident de securitate cade prin playbook direct la AI/escaladare; remedierea deterministă promisă nu funcționează | Aliniază cheile: implementează `disable_debug`/`reinstall_core` în plugin sau mapează la chei existente |
| **S-P2-2** | P2 | `app/Http/Middleware/AuthenticateAgent.php:23`, `app/Models/Site.php:177` | `Site::where('api_key', $token)` pe coloană cu cast `encrypted` — căutarea pe ciphertext cu IV random e neîntemeiată; ori encryption e no-op efectiv, ori lookup-ul e fragil | Rotația cheii de app sau schimbarea encryption ar putea rupe autentificarea agentului; neverificat pe DB reală | Verifică comportamentul real; dacă e nevoie de căutare, folosește hash determinist separat (blind index) pentru lookup |
| **S-P2-3** | P2 | `app/Jobs/PushSecuritySettings.php:20,74-81` (fără `failed()`) | La epuizarea celor 3 tries, setările rămân „aplicate în DB, neconfirmate", fără eroare vizibilă utilizatorului | Push eșuează persistent (plugin down) → UI arată setări active dar site-ul nu le aplică; discrepanță tăcută | Adaugă `failed()` care apelează `markAllFailed` + notificare |
| **S-P2-4** | P2 | `app/Jobs/DeleteSpamUsersJob.php:20` | Fără `ShouldBeUnique` — două declanșări rapide pot rula chunk-uri suprapuse pe același site | Double-click pe „delete spam" → curse pe progres/logging (ștergerea WP e idempotentă, dar UX/contorii se corup) | `implements ShouldBeUnique`, `uniqueId = spam-delete-{site}` |
| **S-P2-5** | P2 | `app/Livewire/Sites/Detail/Security/SecurityComingSoon.php`, `app/Services/ThemeIntegrityService.php` + `app/Jobs/CheckThemeIntegrity.php`, `PerformanceDropPlaybook.php`, `DatabaseCriticalPlaybook.php`, `SecurityRecommendationService::fix()` | Cod mort / feature-uri pe jumătate: componenta ComingSoon nerutată, integritate teme nedispecerizată, 2 playbook-uri fără trigger, auto-fix recomandări neapelat din UI | Efort de mentenanță pe cod neexecutat; integritatea temelor pare o funcție „livrată" dar inactivă | Conectează sau elimină; documentează statusul |
| **S-P2-6** | P2 | `config/incident-response.php:25`, `AiAgentService.php:98` | Model AI hard-codat `claude-sonnet-4-20250514` — model deprecat (retragere 2026-06-15, deja trecut la data auditului?); ID cu sufix de dată | La retragerea modelului, apelurile Claude vor da 404 → tot incident-response AI cade la escaladare | Actualizează la un model curent (ex. `claude-sonnet-4-5` sau superior) via env |
| **S-P3-1** | P3 | `app/Services/SpamUserDetectionService.php:12,100` | Prag spam=5 cu euristici cumulabile; un subscriber inactiv legitim ajunge la 4 (subscriber+1, never login+2, no orders+1). Un abonat de newsletter fără comenzi/postări + username „numeric" (ex. `john2024`) trece pragul | Fals pozitiv teoretic pe abonați reali cu username-uri parțial numerice; ștergerea e confirmată manual în UI (checkbox), deci risc atenuat | Reponderează; loghează scorurile; test unitar dedicat |
| **S-P3-2** | P3 | `app/Jobs/PushSecuritySettings.php:66-70` | `Log::warning(... 'body' => $response->body())` la eșec — dacă plugin-ul ecoează payload-ul, `captcha.secret_key` decriptat ar putea ajunge în log | Secret CAPTCHA în logurile Laravel; neverificat ce conține body-ul de eroare | Redactează secretele înainte de logare |
| **S-P3-3** | P3 | `config/security.php:14-15` | Praguri versiune WP hard-codate (6.0/6.4) via env cu default vechi | Toate site-urile pe WP 6.5-6.8 apar „la zi" contra unui prag de 6.4 învechit; scanarea de versiune devine inutilă în timp | Fetch versiunea recomandată din WP.org API sau actualizează defaults |

**Total:** P0: 0 · P1: 5 · P2: 6 · P3: 3

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-uri EXISTENTE
1. **Dry-run / preview pentru remedierea AI** — înainte ca incident response să execute acțiuni distructive, afișează în UI un „plan propus" (ce plugin-uri se dezactivează, ce db_cleanup) cu buton de aprobare pentru site-uri marcate „high-value". Codul are deja `IncidentResponseAction` cu `tier` — se poate adăuga un tier `pending_approval`. Fricțiune eliminată: adminii nu au vizibilitate pre-execuție.
2. **Contor „ultima verificare reușită" pe vulnerabilități + scanare** — distinge „curat" de „nu am putut verifica" (`VulnerabilityCheckService`, `RunSecurityScan`). Un badge „scanat acum 2h" vs „scanare eșuată acum 3 zile" în `SecurityDashboard`. Automatizare lipsă azi.
3. **Aliniere chei fix + feedback per-cheie** — `SecurityRecommendationService::fix()` există dar e orfan din UI; expune butoane de auto-fix per recomandare în `SecurityScanning`/`SecurityOverview`, cu mapare corectă a cheilor (rezolvă și S-P2-1). Valoarea remedierii cu-un-click e mare, efortul mic.
4. **Vizualizare failed-login / brute-force** — `SecurityActivityService::getFailedLoginStats` calculează deja top-IP-uri și unique usernames (`.php:60-90`), dar nu am confirmat un grafic; un mini-dashboard „atacuri în ultimele 7 zile" cu buton „ban IP" (leagă la `SecurityIpManagement`) ar fi o automatizare naturală.
5. **Notificare pe discrepanță setări** — când `PushSecuritySettings` sau `verifySettings` detectează mismatch (`SecurityHardening::verifySettings`, `.php:157-159`), trimite o notificare, nu doar flash message. Multe mismatch-uri trec neobservate.

### (b) Feature-uri NOI (ancorate în cod)
1. **Malware/backdoor scanning (semnături în uploads/)** — `S/M`. Modulul are deja infrastructura de integritate (checksums core, baseline teme); extinde cu scanarea directorului `uploads/` pentru fișiere PHP suspecte (pattern `eval(base64_decode`). Rațiune: integritatea core/teme prinde modificări, dar backdoor-urile clasice stau în uploads. Benchmark: Wordfence/MalCare oferă asta; modulul e la un pas.
2. **Baseline de reputație IP + auto-ban geografic** — `M`. `SecurityBannedIp` + `SecurityIpList` + `getFailedLoginStats` există deja; adaugă enrichment (AbuseIPDB) și reguli de auto-ban pe rate de eșec. Rațiune: azi ban-urile vin doar de la plugin; managerul ar putea decide proactiv. Benchmark: MainWP/WPMU DEV Defender.
3. **Scoruri de risc agregate + raport lunar de securitate per client** — `M/L`. `security_hardening_score` + `SecurityScan.score` + `VulnerabilityAlert` există; agregă într-un „security posture report" per client (leagă la modulul Reports existent). Rațiune: agenția vinde întreținere; un raport lunar de securitate e valoare directă pentru client. Benchmark: ManageWP/SpinupWP client reports.
