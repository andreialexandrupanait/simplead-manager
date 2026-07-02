# 27 — Horizon + Scheduler Laravel

**Data:** 2026-07-02 · **Auditor:** audit tehnic (read-only) · **Scope:** `routes/console.php`, `config/horizon.php`, `config/queue.php`, `app/Dispatchers/*`, `app/Jobs/*` (proprietăți de coadă/timeout/unicitate), `app/Console/Commands/*` programate, `docker-compose.prod.yml` (servicii `horizon`, `scheduler`), `deploy.sh`, listeneri de monitorizare din `app/Providers/AppServiceProvider.php` și `app/Listeners/TrackScheduledTaskFailures.php`. Include codul necomis (`ExportBackupForLocal`).

## Rezumat executiv

Arhitectura de cozi este peste media proiectelor de această dimensiune: 9 cozi denumite, 6 supervisori Horizon cu timeouts/tries per clasă de muncă, dispatchere centralizate cu circuit breaker, recuperare automată a backup-urilor blocate (`BackupDispatcher::recoverStuckBackups`), tracking al eșecurilor de scheduler (`TrackScheduledTaskFailures`) și alerte `LongWaitDetected`. Totuși, există o gaură gravă exact pe operația cea mai distructivă: **un restore de site live ucis de deploy sau de OOM rămâne blocat pentru totdeauna în `restore_status = in_progress`, fără recuperare, fără alertă, cu site-ul clientului posibil pe jumătate suprascris** — `stop_grace_period: 300s` este de 12× mai mic decât timeout-ul de restore (3600s), iar comentariul din `docker-compose.prod.yml:64` („Must exceed longest job timeout") contrazice propria valoare. Al doilea defect structural: **întregul lanț de alertare depinde de componentele pe care ar trebui să le supravegheze** — alerta „Horizon is down" se livrează printr-o coadă procesată de Horizon, iar comanda de health-check rulează în scheduler-ul care nu are, la rândul lui, niciun monitor real (healthcheck-ul Docker e `php -r 'echo 1;'`). Al treilea: **niciun task programat nu folosește `runInBackground()`**, deci comenzile lungi inline (verificarea săptămânală de backup descarcă 3 arhive complete în procesul scheduler-ului) opresc dispatch-ul de uptime/backup pe toată durata lor.

Constatări: **1×P0, 4×P1, 7×P2, 4×P3**.

---

## Inventar complet scheduler

Toate cele **30 de intrări** din `routes/console.php` (218 linii). Coloana „rIB" = `runInBackground()` — **niciuna nu îl are**; toate rulează secvențial în procesul `schedule:work` (`docker-compose.prod.yml:95`). Un singur container scheduler există, deci `onOneServer()` este azi un no-op inofensiv (dar corect defensiv).

| # | Nume (`name()`) | Linie | Tip | Frecvență | `withoutOverlapping` | `onOneServer` | rIB | Risc suprapunere / notă |
|---|---|---|---|---|---|---|---|---|
| 1 | monitoring-dispatcher | 17–21 | call | everyMinute | ✅ | ✅ | ❌ | mutex orfan la SIGKILL → blocaj până la 24h (QS-04) |
| 2 | data-sync-dispatcher | 24–28 | call | everyMinute | ✅ | ✅ | ❌ | idem QS-04 |
| 3 | backup-dispatcher | 31–35 | call | everyMinute | ✅ | ✅ | ❌ | idem QS-04 |
| 4 | report-dispatcher | 38–42 | call | every 5 min | ✅ | ✅ | ❌ | claim atomic intern — bun |
| 5 | incident-response-dispatcher | 45–49 | call | every 5 min | ✅ | ✅ | ❌ | — |
| 6 | seo-audit-dispatcher | 51 | call | every 5 min | ✅ | ✅ | ❌ | — |
| 7 | broken-resource-dispatcher | 54 | call | daily 02:00 | ✅ | ✅ | ❌ | — |
| 8 | keyword-rankings-fetch | 57–61 | call (closure) | daily 04:00 | ❌ | ✅ | ❌ | doar dispatch — risc mic; job `FetchKeywordRankings` e `ShouldBeUnique` |
| 9 | daily-health-scores | 64 | job `RecordHealthScores` | daily 01:00 | ❌ (n/a la `Schedule::job`) | ✅ | n/a | job unic (`RecordHealthScores.php` implements `ShouldBeUnique`) |
| 10 | monthly-aggregation | 70–73 | job `AggregateMonthlySnapshots` | monthlyOn(1, 02:00) | ❌ | ✅ | n/a | job NE-unic; frecvență lunară → risc practic nul |
| 11 | retention-cleanup | 79–82 | job `RetentionCleanup` | daily 03:00 | ❌ | ✅ | n/a | job NE-unic, timeout 900 (`RetentionCleanup.php:24`); zilnic → risc mic |
| 12 | horizon-snapshot | 89–92 | command | every 5 min | ❌ | ✅ | ❌ | rapid; risc neglijabil (QS-13) |
| 13 | backup-temp-cleanup | 95–98 | command | daily 04:30 | ❌ | ✅ | ❌ | nu curăță `restore-*` (QS-09) |
| 14 | database-dump | 101–104 | command `db:dump --keep=7` | daily 02:30 | ❌ | ✅ | ❌ | **inline, blochează scheduler-ul** pe durata pg_dump (QS-03, QS-13) |
| 15 | vacuum-analyze | 107–113 | command | weekly Sun 03:00 | ✅ | ✅ | ❌ | **inline, coincide cu #19** duminică 03:00 (QS-03) |
| 16 | favicon-backfill | 116–120 | command | daily 00:00 | ✅ | ✅ | ❌ | inline, N site-uri HTTP — blochează scheduler-ul (QS-03) |
| 17 | scheduled-app-backups | 123–127 | command | every 15 min | ✅ | ✅ | ❌ | doar dispatch `CreateAppBackup` (`ScheduledAppBackupCommand.php:30`) — ok |
| 18 | expired-app-backup-cleanup | 130–134 | command | daily 04:00 | ✅ | ✅ | ❌ | — |
| 19 | backup-verify-restore-weekly | 137–141 | command `backup:verify-restore --count=3` | weeklyOn(0=Sun, 03:00) | ✅ | ✅ | ❌ | **descarcă 3 arhive complete inline în scheduler** (`VerifyBackupRestoreCommand.php:81–83`) — QS-03 |
| 20 | horizon-health-check | 144–148 | command | every 5 min | ✅ | ✅ | ❌ | alerta trece prin coada Horizon (QS-02) |
| 21 | php-error-log-fetch | 151–158 | call (closure) | every 6h | ✅ | ✅ | ❌ | doar dispatch cu jitter — ok |
| 22 | daily-vulnerability-check | 161–164 | job `CheckPluginVulnerabilities` | daily 05:00 | ❌ | ✅ | n/a | job unic, tries=1, timeout 300 pt. TOATE site-urile (`CheckPluginVulnerabilities.php:21–23`) |
| 23 | validate-external-connections | 167–170 | job `ValidateExternalConnections` | daily 06:00 | ❌ | ✅ | n/a | NE-unic, tries=1, timeout 300, toate site-urile |
| 24 | process-notification-batch | 173–177 | job `ProcessNotificationBatch` | everyMinute | ✅ (inutil la `Schedule::job` — protejează doar dispatch-ul, nu execuția) | ✅ | n/a | job NE-unic; drain cu `LPOP` atomic → suprapunerea nu duplică, doar sparge grupurile |
| 25 | process-notification-escalations | 179–183 | job `ProcessNotificationEscalations` | every 5 min | ✅ (idem, inutil) | ✅ | n/a | job NE-unic, read-then-update ne-atomic → **dublă escaladare posibilă** (QS-11) |
| 26 | daily-digest | 186–189 | job `SendDailyDigest` | daily 07:00 | ❌ | ✅ | n/a | — |
| 27 | security-stale-commands-cleanup | 196–200 | command | every 15 min | ✅ | ✅ | ❌ | — |
| 28 | security-activity-log-prune | 203–206 | command | daily 03:30 | ❌ | ✅ | ❌ | — |
| 29 | security-expired-bans-cleanup | 209–212 | command | hourly | ❌ | ✅ | ❌ | risc mic (QS-13) |
| 30 | security-score-recalculation | 215–218 | command | daily 06:00 | ❌ | ✅ | ❌ | inline, recalculează toate site-urile — blochează scheduler-ul (QS-03) |

**Task-uri care pot suprapune rulări (execuție, nu dispatch):** #10, #11, #23, #24, #25, #26 — `withoutOverlapping` pe `Schedule::job` protejează doar dispatch-ul (milisecunde); protecția reală trebuie să fie pe job (`ShouldBeUnique`), pe care #24, #25, #23, #11, #10 NU o au. Practic riscant doar #25 (vezi QS-11).

**Aglomerarea nocturnă:** 00:00 favicon (inline) → 01:00 health scores → 02:00 broken-resources (+lunar agregare) → 02:30 pg_dump (inline) → 03:00 duminică: vacuum-analyze + verify-restore (ambele inline, secvențial, potențial >30 min cumulat) → 03:30, 04:00, 04:30, 05:00, 06:00 ×2, 07:00. În fiecare fereastră inline, dispatcher-ele de la #1–#3 **nu rulează** (vezi QS-03).

---

## Inventar Horizon

### Cozi (9) și acoperirea lor

`config/horizon.php:99–109` definește praguri `waits` pentru exact 9 cozi: `default`, `notifications`, `uptime`, `backups`, `sync`, `performance`, `reports`, `security`, `incident-response`. Toate cozile folosite prin `onQueue()` în `app/Jobs/*` sunt acoperite de un supervisor. **Excepție de rutare:** `ExportBackupForLocal` (necomis) nu are `onQueue()` (`ExportBackupForLocal.php:37` — constructor gol) → aterizează pe `default`, nu pe `backups` (QS-07).

### Supervisori (6) — `config/horizon.php:207–282`, producție `:284–308`

| Supervisor | Cozi | balance | Procese (prod, default env) | memory (MB/worker) | tries | timeout (s) | Joburi reprezentative (timeout propriu) |
|---|---|---|---|---|---|---|---|
| supervisor-uptime | uptime | simple | 2 (`HORIZON_UPTIME_WORKERS`) | 64 | 3 | 30 | CheckUptime 30s/3 tries |
| supervisor-sync | sync | auto/time | 3 | 256 | 3 | 300 | SyncWordPressSite 120, FetchAnalytics/SearchConsole 120, SyncCloudflareZone 60, FetchKeywordRankings 120 |
| supervisor-backups | backups | simple | 3 | 1024 | 2 | 3600 | CreateBackup 2700/2, CreateIncrementalBackup 2700/2, **RestoreBackup 3600/1**, ReplicateBackup 1800/3, CreateAppBackup 1800/1 |
| supervisor-notifications | notifications | simple | 3 | 64 | 3 | 30 | SendNotificationJob 30/3, Notify* 30/3, ProcessNotificationBatch |
| supervisor-general | security, performance, reports, default | auto/time | 3 | 512 | 3 | 600 | **CrawlSitePages 900** (> supervisor 600), RunSeoAudit 60, GenerateReport 300/2, RunSafeUpdate 600/1, PushConnectorPlugin 180/1, DeleteSpamUsersJob 600/1, **ExportBackupForLocal 1800** (necomis, > supervisor 600) |
| supervisor-incident-response | incident-response | simple | 2 | 512 | 1 | 900 | RunIncidentResponse 900/1 — coerent perfect |

### Verificări de coerență

- **`retry_after` vs timeout:** `config/queue.php:70` → `retry_after = (int) env('REDIS_QUEUE_RETRY_AFTER', 7200)`. Default 7200s > 3600s (cel mai mare timeout, RestoreBackup) → **fără dublă execuție** la valorile default. **Neverificat:** valoarea reală din `.env` de producție (fișierul nu a putut fi citit în acest audit); dacă cineva a setat `REDIS_QUEUE_RETRY_AFTER` sub 3600, un backup/restore lung ar fi livrat a doua oară unui al doilea worker în timp ce primul încă rulează. De confirmat manual (QS-15).
- **Job timeout > supervisor timeout:** `CrawlSitePages` (900, `CrawlSitePages.php:33`, coada `performance`) și `ExportBackupForLocal` (1800, necomis, coada `default`) depășesc timeout-ul 600 al `supervisor-general` (`config/horizon.php:267`). Proprietatea `$timeout` a jobului are prioritate asupra opțiunii worker-ului, deci funcțional nu se ucide la 600 — dar intenția de configurare a supervisorului e încălcată și un singur export/crawl ține un worker general ocupat 15–30 min (QS-07, QS-10).
- **Memorie:** containerul `horizon` e limitat la **1024M total** (`docker-compose.prod.yml:82–86`), dar numai `supervisor-backups` are 3 workeri cu prag `memory: 1024` fiecare (`config/horizon.php:240,294–296`), iar `RestoreBackup::handle()` își setează `ini_set('memory_limit', '1G')` (`RestoreBackup.php:58`). Plus ~13 alte procese worker + master. Un singur restore/backup mare poate împinge cgroup-ul peste limită → **OOM-kill (SIGKILL) al unui worker mid-backup/restore** (QS-05), care e exact declanșatorul scenariului P0.
- **`trim`** (`config/horizon.php:122–129`): recent/pending/completed 60 min, failed 10080 min (7 zile). Backup-urile eșalonate cu delay `index*180s` (`BackupDispatcher.php:53`) depășesc 60 min de la ~21 de site-uri simultane → dispar din lista „pending" a UI-ului Horizon deși sunt încă în coadă (cosmetic, QS-14).
- **Gate Horizon:** `HorizonServiceProvider.php:32` — `viewHorizon` doar pentru `isAdmin()`. Corect.
- **`fast_termination: true`** (`config/horizon.php:181`) + `deploy.sh:44–45` (`horizon:terminate` + `sleep 5`): terminate returnează imediat, workerii încearcă să-și termine jobul curent, dar recrearea containerului de la `deploy.sh:64` îi lasă doar `stop_grace_period: 300s` (`docker-compose.prod.yml:65`) — vezi QS-01.

---

## Separarea priorităților

- **Backups/restores au coadă și supervisor dedicate** (3 workeri, memorie 1024) — un audit SEO lent **nu** poate întârzia un restore. Corect.
- **Uptime are supervisor dedicat** (2 workeri, timeout 30) — detecția de downtime nu concurează cu nimic altceva la nivel de worker (dar vezi QS-03: concurează la nivel de *dispatch* cu comenzile inline din scheduler).
- **`supervisor-general` amestecă `security`, `performance`, `reports`, `default`** (`config/horizon.php:259`) cu doar 3 workeri. Ordinea listei dă prioritate (security primul), dar prioritatea operează doar când un worker se eliberează: lanțul SEO `Bus::chain([CrawlSitePages, AnalyzeSeoPages, CalculateSeoScores])` pe `performance` (`RunSeoAudit.php:69`) are joburi de până la 900s; 2–3 crawl-uri simultane ocupă toți workerii generali până la 15 min. Consecințe concrete: `GenerateReport` (reports), `PushConnectorPlugin` (default, 180s — operație pe site live), `RunSafeUpdate` (security, 600s — update pe site live) și `PullSecurityActivityLogs` așteaptă în spatele crawling-ului SEO (QS-10). `ExportBackupForLocal` (necomis) agravează: 30 min pe `default` (QS-07).
- **`incident-response` separat cu tries=1** — decizie bună pentru operații AI potențial distructive (fără re-execuție automată).

---

## Monitorizarea scheduler-ului însuși

Ce există:
1. `horizon:health-check` la 5 min (`routes/console.php:144–148`, `HorizonHealthCheckCommand.php:24–35`) — verifică `MasterSupervisorRepository::all()` și notifică `severity: critical` o dată pe oră (cache `horizon_stopped_notified`, TTL 3600).
2. `TrackScheduledTaskFailures` (`AppServiceProvider.php:120–121`, `app/Listeners/TrackScheduledTaskFailures.php:24–51`) — alertă după 3 eșecuri consecutive ale unui task, cooldown 1h. Bun.
3. `LongWaitDetected` → notificare (`AppServiceProvider.php:124–131`).
4. Healthcheck Docker pe horizon: `php artisan horizon:status | grep -q running` (`docker-compose.prod.yml:58`) — real; `restart: unless-stopped` repornește containerul căzut.

De ce NU e suficient:
- **Circularitate 1:** alerta „Horizon Is Not Running" se trimite prin `NotificationService::notifyAppEvent` → `SendNotificationJob::dispatch(...)` pe coada `notifications` (`NotificationService.php:237–241`) — coadă consumată **de Horizon, care e picat**. Alerta zace în Redis și se livrează abia când Horizon revine, adică exact când nu mai e utilă (QS-02).
- **Circularitate 2:** `horizon:health-check` rulează **în scheduler**. Dacă scheduler-ul moare sau se blochează, nu mai verifică nimeni nici Horizon, nici scheduler-ul.
- **Healthcheck-ul scheduler-ului e un placebo:** `php -r 'echo 1;'` (`docker-compose.prod.yml:97`) trece chiar dacă `schedule:work` a murit în container sau e blocat de 40 de minute într-o comandă inline. Docker nu repornește nimic pentru că procesul PID 1 încă există.
- **Nimeni nu ascultă `ScheduledTaskSkipped`** — un mutex `withoutOverlapping` orfan (QS-04) face dispatcher-ele să fie „skipped" silențios; `TrackScheduledTaskFailures` nu se declanșează pentru că task-ul nu „eșuează".
- **Nu există heartbeat extern** (healthchecks.io / Better Uptime / cron extern care să pingă un endpoint la fiecare tick reușit al `monitoring-dispatcher`). Pentru o platformă care monitorizează uptime-ul altora, propria monitorizare e un single point of failure intern.

---

## Job-uri moarte (failed_jobs)

- Driver: `database-uuids` pe PostgreSQL (`config/queue.php:106–110`).
- **Nu există `queue:prune-failed` programat** — grep pe `routes/console.php` și `app/Console` nu găsește nicio referință. Tabela `failed_jobs` crește nelimitat; retenția de 7 zile (`config/horizon.php:126–127`, 10080 min) se aplică **doar** copiilor din Redis pentru UI-ul Horizon, nu tabelei Postgres (QS-08).
- Cine se uită: alertă `Queue::failing` doar când aceeași clasă de job atinge **exact** al 3-lea eșec într-o oră (`AppServiceProvider.php:141` — `if ($failures === 3)`). Un `RestoreBackup` (tries=1) care eșuează o singură dată — cel mai grav caz posibil — **nu produce nicio alertă de infrastructură**; utilizatorul vede doar starea din UI. Joburile cu `tries=1` care eșuează sporadic (RunSafeUpdate, DeleteSpamUsersJob, RunIncidentResponse) sunt invizibile pentru alerting (QS-12).
- Bine: eșecurile de backup au canal dedicat (`NotifyBackupFailed` + `recoverStuckBackups` → `markBackupFailed`, `BackupDispatcher.php:264–296`).

---

## Unicitate & idempotență

- **30 din 51 de joburi implementă `ShouldBeUnique`** (listă completă verificată prin grep) — acoperire bună pe joburile per-site.
- `CreateBackup`: `uniqueFor = 2700` (`CreateBackup.php:42`) + `releaseUniqueLock()` static (`BackupJobTrait.php:22`) apelat din recovery — proiectare corectă, lock-ul nu poate rămâne orfan mai mult de 45 min.
- **`RestoreBackup` NU are `uniqueFor`** (`RestoreBackup.php:28–54`): lock-ul `restore-{backupId}` trăiește până job-ul e procesat sau eșuează. La SIGKILL, lock-ul persistă până când jobul rezervat expiră (`retry_after` = 7200s) și e re-livrat → atempt 2 > tries 1 → `MaxAttemptsExceeded` → abia atunci se eliberează. **Fereastră de ~2h în care „Restore" din UI setează `restore_status='pending'` în DB (`RestoreConfirmation.php:278`) dar dispatch-ul e aruncat silențios de lock** (`RestoreConfirmation.php:285`). `backup:release-lock` acoperă doar lock-urile `CreateBackup`/`CreateIncrementalBackup`, nu și restore (`BackupReleaseLock.php:35–36`).
- Idem `CheckUptime` (`CheckUptime.php:23`, fără `uniqueFor`): worker ucis → monitorul respectiv nemonitorizat până la 2h, fără alertă (QS-06).
- **Deploy cu `horizon:terminate` mid-backup — `stop_grace_period` NU e suficient:** comentariul de la `docker-compose.prod.yml:63–64` afirmă „Must exceed longest job timeout (backups: 2700s = 45min)", dar valoarea de la linia 65 e **300s**. Timeout-urile reale: CreateBackup 2700s, RestoreBackup 3600s. Fluxul `deploy.sh:44` (`horizon:terminate`) → `:45` (`sleep 5`) → `:64` (`up -d --force-recreate ... horizon`) dă unui backup/restore în zbor 5s + 300s înainte de SIGKILL. Pentru backup există plasă de siguranță (`recoverStuckBackups` re-dispecerizează după 20 min fără heartbeat, `BackupDispatcher.php:176–179`). **Pentru restore nu există nimic** — vezi QS-01.
- `ReportDispatcher` folosește claim atomic pe `next_run_at` înainte de dispatch (`ReportDispatcher.php:47–54`) — model corect, singurul dispatcher care o face; celelalte se bazează pe `ShouldBeUnique` + actualizarea `next_*_at` la dispatch (DataSync) sau în job (Monitoring/CheckUptime.php:220).
- `ProcessNotificationBatch` drenează bufferul cu `LPOP` atomic (`ProcessNotificationBatch.php:36`) — suprapunerea nu duplică mesaje. `ProcessNotificationEscalations` în schimb face read-then-update ne-atomic (`ProcessNotificationEscalations.php:44–68`) — vezi QS-11.

---

## Dispatchere

Toate cele 7 (`app/Dispatchers/`) verificate:

| Dispatcher | Selecție site-uri | Protecții | Scalare la 50+ site-uri |
|---|---|---|---|
| MonitoringDispatcher (`:32–70`) | monitors `active` + `next_check_at <= now` + circuit breaker + `is_monitoring_disabled` | `ShouldBeUnique` pe joburi; `next_check_at` setat în job (`CheckUptime.php:220`) | OK ca dispatch (`each()` = chunk implicit); **capacitatea e la workeri**: 2 workeri uptime × timeout 30s → max ~4–8 checks/min în worst case cu site-uri lente; la 50+ monitoare cu interval 1 min se acumulează backlog — alerta `waits` la 30s există |
| DataSyncDispatcher (`:33–98`) | conexiuni active due + circuit breaker; WP sync la 6h | actualizează `next_sync_at` **la dispatch** (atomic-ish, ne-tranzacțional dar suficient cu un singur scheduler) | OK; WP sync fără jitter — toate site-urile eligibile intră în coadă simultan, dar `sync` are 3 workeri × 120s |
| BackupDispatcher (`:24–111`) | configs due + fără backup Pending/InProgress + DiskSpaceGuard + circuit breaker | stagger `index*180s`; `next_backup_at` avansat la dispatch; recovery automat (20 min heartbeat / 45 min pending, max 2 auto-retry) | **La 50 site-uri due simultan, ultimul backup pornește cu delay ~2,45h** — acceptabil, dar joburile delayed >60 min dispar din UI pending (trim). Recovery-ul e solid pentru backups; inexistent pentru restores |
| ReportDispatcher (`:18–79`) | schedules due | **claim atomic** pe `next_run_at` — cel mai corect model din codebase | OK |
| IncidentResponseDispatcher (`:19–92`) | alerte/issues critice + cooldown 30 min per site per tip | kill-switch `config('incident-response.enabled')` | OK |
| SeoAuditDispatcher (`:16–55`) | monitors due + fără audit în curs | curăță audituri stale >30 min; `RunSeoAudit` unic cu `uniqueFor=900` | OK; nota: `cleanupStaleAudits` marchează auditul Failed dar nu anulează joburile din lanțul `Bus::chain` deja în coadă (neverificat comportamentul lanțului după markAs Failed) |
| BrokenResourceDispatcher (`:13–43`) | monitors cu `crawl_enabled` due | `next_crawl_at` avansat la dispatch | OK, zilnic |

**Se pot îneca la 50+ site-uri?** Dispatch-ul propriu-zis nu (interogări indexabile, `each()`), dar: (1) toate rulează în același proces `schedule:work` — orice încetinire a unuia întârzie tick-ul celorlalți; (2) gâtuirea reală e numărul de workeri (mai ales `uptime` 2× și `general` 3×), configurabil din env fără cod — corect proiectat.

---

## Constatări

| ID | Sev | Fișiere:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| QS-01 | **P0** | `docker-compose.prod.yml:63–65`; `deploy.sh:44–64`; `app/Jobs/RestoreBackup.php:32–34,51–54,56–101` (fără `failed()`, fără `uniqueFor`); `app/Dispatchers/BackupDispatcher.php:170–204` (recovery doar pt. `status`, nu `restore_status`); `app/Models/Backup.php:233`; `app/Livewire/Sites/Detail/Components/RestoreConfirmation.php:278–285`; `app/Console/Commands/BackupReleaseLock.php:35–36` | Restore ucis prin SIGKILL (deploy: grace 300s ≪ timeout 3600s; sau OOM — vezi QS-05) nu are niciun mecanism de recuperare: `tries=1`, fără handler `failed()`, fără echivalent `recoverStuckBackups` pentru `restore_status`, lock unic fără `uniqueFor`, `backup:release-lock` nu acoperă restore. Comentariul din compose („Must exceed longest job timeout... 2700s") contrazice valoarea 300s. | Deploy în timpul unui restore de 40 min pe site-ul unui client: DB-ul WP e deja importat, fișierele pe jumătate copiate → SIGKILL la 305s. Site-ul clientului rămâne într-o stare hibridă (DB nou + fișiere vechi/parțiale) și **nimeni nu e alertat**. UI arată „restore in progress" pentru totdeauna; butonul Retry setează `restore_status='pending'` dar dispatch-ul e înghițit silențios de lock-ul orfan timp de ~2h. | (1) `stop_grace_period` ≥ 3700s sau gate de deploy care așteaptă golirea cozii `backups`; (2) recovery de restore în `BackupDispatcher` (heartbeat pe `restore_*`, marchează Failed + `NotifyIncident`); (3) `failed()` + `uniqueFor` pe `RestoreBackup`; (4) extinde `backup:release-lock` la lock-urile de restore. |
| QS-02 | **P1** | `app/Console/Commands/HorizonHealthCheckCommand.php:24–35`; `app/Services/Notifications/NotificationService.php:237–241`; `docker-compose.prod.yml:96–101` | Lanțul de alertare e circular: alerta „Horizon Is Not Running" se pune în coada `notifications` procesată de Horizon (care e jos); `horizon:health-check` rulează în scheduler, iar scheduler-ul nu are niciun monitor real (healthcheck `php -r 'echo 1;'`). | Horizon crapă vineri seara. Alerta critică stă în Redis până luni când cineva observă manual că nu s-au făcut backup-uri, nu s-au trimis alerte de uptime și nu au rulat scan-urile de securitate. Dacă moare scheduler-ul, nici măcar alerta întârziată nu există. | Trimite alerta horizon_stopped sincron (direct sender Slack/Telegram, nu prin coadă). Heartbeat extern (healthchecks.io) pingat din `schedule:call` la fiecare 5 min + healthcheck Docker real pe scheduler (ex. touch pe un fișier la fiecare tick, verificat de healthcheck). |
| QS-03 | **P1** | `routes/console.php` (0 apariții `runInBackground` — verificat prin grep pe tot `app/` și `routes/`); `app/Console/Commands/VerifyBackupRestoreCommand.php:81–83`; `routes/console.php:101–104,107–113,116–120,137–141,215–218` | Toate task-urile rulează secvențial în foreground-ul `schedule:work`. Comenzile lungi inline — `backup:verify-restore` (descarcă 3 arhive complete din S3/Dropbox în containerul scheduler), `db:dump`, `db:vacuum-analyze`, `sites:backfill-favicons`, `security:maintenance recalculate-scores` — blochează tick-urile dispatcher-elor pe minut. | Duminică 03:00: vacuum-analyze + verify-restore rulează spate-în-spate; descărcarea a 3 backup-uri de câțiva GB durează 20–40 min. În tot acest timp **nu se dispecerizează niciun uptime check** — un site de client care pică la 03:05 e detectat abia la 03:40. | `->runInBackground()` pe toate comenzile lungi (cer `name()`, deja există) sau convertește-le în joburi pe coadă; păstrează inline doar dispatch-closures. |
| QS-04 | **P1** | `routes/console.php:17–51` (dispatchere everyMinute cu `withoutOverlapping`); `docker-compose.prod.yml:88–122` (scheduler fără `stop_grace_period` → default 10s); `deploy.sh:64` (force-recreate scheduler la fiecare deploy) | Mutex-ul `withoutOverlapping` (expirare default 1440 min) rămâne orfan dacă `schedule:work` e SIGKILL-uit mid-task (finally nu rulează la SIGKILL). Scheduler-ul e recreat la fiecare deploy cu doar 10s grace, iar QS-03 face probabil ca un deploy să prindă un task inline lung în execuție. Nimeni nu ascultă `ScheduledTaskSkipped`. | Deploy duminică 03:10 prinde `monitoring-dispatcher` (sau, mai probabil, tick-ul care conține verify-restore) în execuție → SIGKILL la 10s → mutex-ul rămâne în Redis → dispatcher-ul e „skipped" silențios la fiecare minut, până la 24h. Zero uptime checks, zero backup dispatch, zero alertă (task-ul nu „eșuează", e sărit). | `stop_grace_period` pe scheduler + listener pe `ScheduledTaskSkipped` cu alertă după N skip-uri consecutive pe dispatchere; opțional `->withoutOverlapping(15)` (expirare 15 min) pe dispatcherele pe minut. |
| QS-05 | **P1** | `docker-compose.prod.yml:82–86` (limită container 1024M); `config/horizon.php:240,294–296` (3 workeri backups × memory 1024); `app/Jobs/RestoreBackup.php:58` (`ini_set('memory_limit','1G')`) | Bugetul de memorie e incoerent: limita totală a containerului horizon (1024M) e egală cu pragul de restart al **unui singur** worker de backup, iar în container rulează ~16 procese worker + master. `memory` din Horizon e prag de restart graceful între joburi — în timpul unui job, PHP poate consuma legitim până la 1G. | Un restore de arhivă mare (memory_limit 1G) + un CreateBackup concurent pe alt worker → cgroup depășește 1024M → kernel OOM-killer trimite SIGKILL unui worker **în mijlocul jobului** → declanșează exact scenariul QS-01, fără vreun deploy. | Ridică limita containerului (ex. 3–4G) sau redu `HORIZON_BACKUP_WORKERS` la 1 și pragurile worker-ilor la valori care încap sumate în limită; aliniază `memory_limit`-ul din RestoreBackup cu bugetul. |
| QS-06 | P2 | `app/Jobs/CheckUptime.php:23–39` (ShouldBeUnique fără `uniqueFor`); `config/queue.php:70` (`retry_after` 7200) | Joburile unice fără `uniqueFor` lasă lock-ul blocat ~2h (până la expirarea rezervării) după un worker ucis; dispatcher-ul re-dispecerizează în gol în tot acest timp. | Worker uptime OOM-kill: monitorul X rămâne cu lock; site-ul X pică în fereastra de 2h → nedetectat, fără alertă. | `uniqueFor` ≈ 2× timeout pe joburile unice de monitoring; `retry_after` diferențiat per coadă nu e posibil pe o singură conexiune — alternativ conexiuni redis separate pentru cozile scurte. |
| QS-07 | P2 | `app/Jobs/ExportBackupForLocal.php:31–37` (necomis; timeout 1800, fără `onQueue`); `config/horizon.php:259,267` | Jobul de export local (WIP) merge pe coada `default` → `supervisor-general` (timeout 600 < 1800; proprietatea jobului câștigă, dar ocupă 1 din 3 workeri generali până la 30 min). | Un export de backup mare blochează o treime din capacitatea security/performance/reports/default; combinat cu 2 crawl-uri SEO, un `RunSafeUpdate` pe site live așteaptă zeci de minute. | `$this->onQueue('backups')` în constructor (e muncă de backup, cu I/O mare) înainte de commit. |
| QS-08 | P2 | `config/queue.php:106–110`; `routes/console.php` (fără `queue:prune-failed` — verificat) | Tabela `failed_jobs` (Postgres) nu e curățată niciodată; retenția de 7 zile din `config/horizon.php:126–127` se aplică doar Redis-ului/UI-ului. | După un an cu joburi `tries=1` care eșuează sporadic, tabela ajunge la sute de mii de rânduri cu payload-uri serializate mari; dump-ul zilnic (`db:dump`) și restore-ul aplicației cresc proporțional. | `Schedule::command('queue:prune-failed --hours=720')` zilnic. |
| QS-09 | P2 | `app/Console/Commands/CleanupBackupTemp.php:43` (prefix doar `backup-`); `app/Jobs/RestoreBackup.php:60` (`temp/restore-…`) | Curățarea temp-ului ignoră directoarele `restore-*`; acestea se șterg doar în `finally` (cleanup), care nu rulează la SIGKILL. | Două-trei restore-uri ucise (QS-01/QS-05) lasă zeci de GB în `storage/app/temp` pentru totdeauna → `DiskSpaceGuard::canDispatchBackup()` (`BackupDispatcher.php:30`) începe să refuze **toate** backup-urile programate, silențios. | Adaugă prefixul `restore-` (și prefixul temp al `ExportBackupForLocal`) în `cleanupBackupDirectories`. |
| QS-10 | P2 | `config/horizon.php:257–269`; `app/Jobs/CrawlSitePages.php:33`; `app/Jobs/RunSeoAudit.php:69` | 3 workeri pentru 4 cozi eterogene: lanțul SEO (900s+300s+120s per site) poate satura `supervisor-general`, întârziind rapoarte și operații pe site-uri live (`PushConnectorPlugin`, `RunSafeUpdate`). Prioritatea security e doar la eliberarea unui worker. | 3 audituri SEO pornite de dispatcher la 5 min interval ocupă toți workerii ~15 min; un safe-update de plugin vulnerabil (security) așteaptă; fereastra de patch se prelungește. | Coadă/supervisor separat pentru `performance` (crawling) sau `maxProcesses` mai mare pe general + limită de concurență pe crawl-uri (`WithoutOverlapping` middleware / rate limit). |
| QS-11 | P2 | `app/Jobs/ProcessNotificationEscalations.php:44–68` (read-then-update, `escalated=false` → dispatch → `update`), fără `ShouldBeUnique`, coada `default` | Escaladările nu sunt claim-uite atomic; două instanțe concurente (posibil când coada `default` are backlog și tick-urile de 5 min se acumulează) escaladează aceleași notificări de două ori. | Backlog pe general (QS-10) → două joburi de escaladare rulează simultan pe 2 workeri → on-call primește dublu fiecare escaladare. | Claim atomic: `UPDATE ... SET escalated=true WHERE id=? AND escalated=false` și dispatch doar dacă a afectat 1 rând; sau `ShouldBeUnique`. |
| QS-12 | P2 | `app/Providers/AppServiceProvider.php:134–149` (`if ($failures === 3)`) | Alerta de „Repeated Job Failures" se declanșează doar la exact al 3-lea eșec pe oră **per clasă**; eșecurile singulare ale joburilor `tries=1` (RestoreBackup, RunSafeUpdate, DeleteSpamUsersJob, RunIncidentResponse — toate distructive) nu alertează infrastructural. | Un `RunSafeUpdate` eșuează lăsând site-ul în maintenance mode (scenariu de verificat în modulul 13); nicio alertă de job failure nu pleacă pentru că e un singur eșec. | Listă de clase critice alertate la primul eșec; pragul ≥3 doar pentru restul. |
| QS-13 | P3 | `routes/console.php:89–92,101–104,209–212,57–61` | `horizon:snapshot`, `db:dump`, `security:maintenance expired-bans`, `keyword-rankings-fetch` fără `withoutOverlapping`. Frecvențele (5 min/zilnic/orar) fac suprapunerea improbabilă, dar `db:dump` concurent cu el însuși ar dubla I/O-ul pe disc. | pg_dump anormal de lent (>24h) s-ar suprapune cu următorul — practic teoretic. | `withoutOverlapping()` uniform pe comenzile cu efecte. |
| QS-14 | P3 | `config/horizon.php:124` (`pending` 60 min); `app/Dispatchers/BackupDispatcher.php:53` (stagger `index*180s`) | Joburile de backup întârziate peste 60 min (de la al 21-lea site simultan) sunt șterse din indexul „pending" al UI-ului Horizon (jobul rămâne în coadă și rulează). | Operatorul caută în Horizon backup-ul site-ului #30 și nu-l vede → conclude greșit că nu s-a programat. | `trim.pending` ≥ delay-ul maxim de stagger (ex. 240 min). |
| QS-15 | P3 | `config/queue.php:70`; `.env` producție (**neverificat** — necitibil în acest audit) | Coerența `retry_after` (default 7200) > timeout max (3600) ține doar dacă env-ul de producție nu suprascrie `REDIS_QUEUE_RETRY_AFTER` cu o valoare mică. | Dacă cineva a setat 1800 în `.env`, un CreateBackup de 40 min e livrat a doua oară la minutul 30 → două backup-uri concurente pe același site (mitigat parțial de lock-ul WP-side `abortedAsDuplicate`, `CreateBackup.php:55`). | Confirmă valoarea în producție; comentariu în `.env.example` că valoarea trebuie > 3600. |
| QS-16 | P3 | `docker-compose.prod.yml:118–122` (scheduler 256M/0.5 CPU); `app/Console/Commands/VerifyBackupRestoreCommand.php:81–83` | Verify-restore rulează în containerul scheduler cu 256M RAM; descărcarea + verificarea ZIP a arhivelor multi-GB depinde de streaming corect în `IntegrityVerifier` (**neverificat** aici — modulul 12). | Dacă verificarea încarcă bufere mari, OOM pe scheduler → schedule:work moare → vezi QS-02 (nimeni nu observă). | Mută verify-restore pe coada `backups` ca job. |

---

*Raport generat exclusiv din citirea codului; nicio comandă mutantă nu a fost rulată. Fișierul `.env` de producție nu a fost citit (acces refuzat) — toate valorile dependente de env sunt marcate ca atare.*
