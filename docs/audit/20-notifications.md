# 20 — Notificări & Alerting

**Data:** 2026-07-02 · **Auditor:** Claude (audit modul, Faza 1) · **Scope:** `app/Services/Notifications/*`, `app/Jobs/{SendNotificationJob,ProcessNotificationBatch,ProcessNotificationEscalations,SendDailyDigest,Notify*}.php`, `app/Livewire/Notifications/*`, `app/Livewire/Settings/NotificationSettings.php` + `Components/ChannelForm.php` + `Forms/ChannelFormData.php`, `app/Http/Controllers/NotificationAckController.php`, modele `NotificationChannel`, `NotificationLog`, `NotificationEscalationRule`, `NotificationEventPreference`, `NotificationTemplate`, `InAppNotification`, plus punctele de emisie (`app/Providers/AppServiceProvider.php`, `app/Listeners/TrackScheduledTaskFailures.php`, `app/Console/Commands/HorizonHealthCheckCommand.php`).

Working tree-ul necomis (feature-ul de export local backup) **nu atinge acest modul** (verificat cu `git status` — fișierele modificate sunt din modulul Backups).

---

## Rezumat executiv

1. **Un canal defect pierde alertele silențios și nu escaladează niciodată.** La eșec final, `SendNotificationJob` nu aruncă excepție (`app/Jobs/SendNotificationJob.php:97-99`), deci jobul nu ajunge în `failed_jobs` și nu declanșează alerta `job_failures`; escaladarea consideră doar logurile cu `status = 'sent'` (`app/Jobs/ProcessNotificationEscalations.php:46`) — exact cazul „canalul primar e mort" nu escaladează. Nu există niciun UI peste `NotificationLog`. Un webhook Slack revocat = platforma devine oarbă la site-down/backup-failed, cu singura urmă un `last_error` trunchiat la 60 de caractere într-o pagină de setări.
2. **Acknowledgment-ul e un feature mort**: `ack_token` se generează și se stochează (`app/Jobs/SendNotificationJob.php:68`), ruta publică există (`routes/web.php:245`), dar **niciun sender și niciun mailable nu include vreodată link-ul de ack** (verificat prin grep în `app/`, `resources/views/`, `app/Mail/`). Consecință: nicio alertă nu poate fi confirmată → orice regulă de escaladare escaladează *întotdeauna*, iar cicluri de reguli (A→B, B→A) produc ping-pong infinit de escaladări.
3. **Watchdog-ul se anunță pe sine prin coada moartă**: alerta „Horizon Is Not Running" e dispatch-uită ca job pe coada `notifications` procesată de... Horizon (`app/Console/Commands/HorizonHealthCheckCommand.php:28-33`). Când Horizon e jos, alerta nu pleacă niciodată.
4. **UI-ul de subscripții pe evenimente listează 12 evenimente din ~20 emise** (`resources/views/livewire/settings/components/channel-form.blade.php:96-109`); un canal cu orice subscripție bifată pierde silențios `dns_changed`, `php_fatal_error`, `safe_update_failed`, `vulnerability_detected`, `core_files_modified` ș.a.
5. Punctele bune, pe dovezi: alertele critice **nu** sunt batch-uite (bufferul Redis e doar pentru `severity === 'info'`, `app/Services/Notifications/NotificationService.php:80-87`), există dedup 5 min per event+site, retries 3 cu backoff, timeout 30s, config-ul canalelor e criptat la rest (`encrypted:array`), authz pe setări e `role:admin` (`routes/web.php:192`).
6. Zgomot secundar: quiet hours **aruncă** (nu amână) notificările non-critice; toggle-urile „Notify down/recovery/degraded" din UI nu sunt citite nicăieri; `NotificationEventPreference` nu are UI (mort); token-ul de bot Telegram poate scurge în `last_error`/`NotificationLog` prin mesajul excepției cURL.
7. **Zero teste** pentru întregul modul (niciun fișier din `tests/` nu menționează „notification").

---

## Inventar & corectitudine

### Ce face de fapt modulul

- **Fan-out**: `NotificationService::notifySiteEvent()`/`notifyAppEvent()` (+ variantele `*Slim`) — quiet hours → dedup 5 min → template opțional → rezolvă canalele (explicite prin `channelIds` sau toate cele `is_default` + `is_active`) → filtru `subscribedTo()` + preferință per user/canal/eveniment → dispatch imediat (`SendNotificationJob`, coada `notifications`) sau buffer Redis pentru `info` (`app/Services/Notifications/NotificationService.php:33-105, 249-285`). Creează și `InAppNotification` pentru `site->user_id`.
- **Sendere** (toate statice, întorc `['success','response_code','error']`): Slack (`SlackNotificationSender.php:51`, timeout 5s), Discord (`DiscordNotificationSender.php:39`), Telegram (`TelegramNotificationSender.php:54`, bot token dublu-criptat), Webhook generic cu HMAC opțional (`WebhookNotificationSender.php:42-49`), Email (doar deleagă `Mail::queue`, `EmailNotificationSender.php:25`).
- **Batching**: `ProcessNotificationBatch` (pe minut, `routes/console.php:172-177`, `onOneServer()->withoutOverlapping()`) golește lista Redis `notification_buffer`, grupează pe `channel_id:event` și trimite fie individual, fie sumar „Nx …" (`app/Jobs/ProcessNotificationBatch.php:36-142`).
- **Escaladări**: `ProcessNotificationEscalations` (la 5 min, `routes/console.php:179-183`) — pentru fiecare regulă activă caută loguri `sent`, ne-escaladate, ne-ack-uite, mai vechi decât `delay_minutes`, max 24h, `limit(10)`, și re-trimite cu prefix `[ESCALATION]` pe canalul de escaladare (`app/Jobs/ProcessNotificationEscalations.php:39-73`).
- **Digest**: `SendDailyDigest` (zilnic 07:00, `routes/console.php:186-189`) — agregat global (site-uri up/down, incidente, backupuri, updates) emailat către **toți** userii (`app/Jobs/SendDailyDigest.php:34-40`).
- **UI**: `NotificationCenter` (in-app, paginat, filtre, corect scoped pe `auth()->id()`), `NotificationSettings` (canale, template-uri, reguli de escaladare, quiet hours — sub `role:admin`), `NotificationDropdown` (clopotel global), `ChannelForm` (CRUD canale).
- **Meta-alerting**: `TrackScheduledTaskFailures` (3 eșecuri consecutive de task programat → critical, `app/Listeners/TrackScheduledTaskFailures.php:24-51`), `Queue::failing` (3 eșecuri/oră pe aceeași clasă de job → critical, `app/Providers/AppServiceProvider.php:134-155`), `HorizonHealthCheckCommand` (supervisori absenți → critical).

### Cod mort / feature-uri pe jumătate

| Ce | Dovadă | Stare |
|---|---|---|
| **Ack pe alerte** | token generat (`SendNotificationJob.php:68`), rută + view există (`routes/web.php:245`, `resources/views/notifications/acknowledged.blade.php`), dar link-ul nu e inclus în niciun mesaj/mail (grep `notifications.ack` / `ack_token` → doar controller, model, job, schema) | **mort** — vezi N-P1-2 |
| Toggle-uri `notify_down` / `notify_recovery` / `notify_degraded` | scrise în `app/Livewire/Settings/NotificationSettings.php:44-46`, citite doar în `mount()` (linia 34-36); niciun alt consumator în `app/` | **mort / UI mincinos** — N-P2-2 |
| `NotificationEventPreference` | citit în `NotificationService.php:292-304`; nicio scriere nicăieri (grep pe `app/`) → tabelul e mereu gol, default „enabled" | **mort** — N-P2-3 |
| Evenimente fantomă în `NotificationTemplate::EVENTS` | `site_recovered` (codul emite `site_up`, `app/Jobs/NotifyIncident.php:39`), `email_blacklisted`, `content_stale`, `connector_update_failed`, `site_degraded` — negăsite ca emisii în `app/` | template-uri configurabile care nu se aplică niciodată — N-P2-10 |
| `domain_expiring` în UI subscripții | `channel-form.blade.php:98`; nicio emisie în `app/` | mort |
| `'short' => true` în fields la test (`NotificationSettings.php:76-77`) | senderele citesc `title`/`name`/`value`, ignoră `short` | inofensiv |
| `fields: ['exception' => …]` la `job_failures` (`AppServiceProvider.php:146`) | senderele așteaptă array-uri `['title'=>…,'value'=>…]`; un string scapă prin `?? ''` și **detaliul excepției se pierde din mesaj** | jumătate-făcut, N-P3 |

TODO-uri explicite: niciunul în fișierele modulului (grep `TODO|FIXME` — zero).

---

## Siguranța operațiilor distructive

Modulul nu execută operații distructive pe site-uri (doar trimite mesaje). Aspectele adiacente:

- **Idempotență la retry**: `SendNotificationJob` cu `tries=3` re-trimite tot mesajul la fiecare încercare — dacă Slack răspunde lent (>5s) dar procesează cererea, retry-ul produce dubluri. Acceptabil pentru alerting; dedup-ul de 5 min din `NotificationService` NU se aplică aici (e la producere, nu la trimitere).
- **Ack public**: `NotificationAckController` e GET fără auth, doar `throttle:30,1` (`routes/web.php:245`), token 64 caractere aleatorii, unique în DB (`database/schema/pgsql-schema.sql:5598`) — neghicibil. Dar e un **GET cu efect secundar** („Escalation cancelled"): dacă link-ul ar fi vreodată livrat pe Slack/Telegram, unfurl-botul platformei ar face GET și ar ack-ui alerta automat, anulând escaladarea fără intervenție umană (N-P3-6; azi latent pentru că link-ul nu se trimite deloc — N-P1-2).
- **Audit „cine a făcut ce"**: `acknowledged_at` se setează fără să se rețină cine/de unde a ack-uit (`NotificationAckController.php:17`); ștergerea canalelor/regulilor din `NotificationSettings.php:54-57,197-201` nu lasă nicio urmă de audit.

---

## Securitate

**Authz pe entry points:**

- `/notifications` (NotificationCenter) — grup `auth` (`routes/web.php:162`); toate query-urile scoped `forUser(auth()->id())` inclusiv `markAsRead`/`deleteOld` (`app/Livewire/Notifications/NotificationCenter.php:51-79`). Corect.
- `/settings/notifications` + acțiunile Livewire (`deleteChannel`, `toggleChannel`, `testChannel`, template/escalation CRUD) — sub `middleware('role:admin')` (`routes/web.php:192-194`). `ChannelForm` este montat în aceeași pagină, deci moștenește gate-ul. Corect. (Atenție doar că *acțiunile* Livewire se autorizează prin faptul că componenta e accesibilă doar pe ruta admin — model acceptat în Livewire, dar fragil dacă componenta e refolosită altundeva.)
- `/notifications/ack/{token}` — public prin design, token aleator; vezi mai sus.
- `NotificationDropdown` — scoped pe user; `retrySiteBackup`/`retryFailedBackups` dispatchează `CreateBackup` pentru **orice** user autentificat, fără verificare de rol (`app/Livewire/Components/NotificationDropdown.php:111-142`) — operațiune benignă (creează backup), semnalat la modulul 12.

**Mass assignment**: `NotificationChannel::$fillable` include `config`/`event_subscriptions`, dar datele vin doar din `ChannelForm::save()` care construiește `config` explicit per tip (`app/Livewire/Settings/Components/ChannelForm.php:38-52`) — OK. `NotificationTemplate` validat (`NotificationSettings.php:133-137`).

**SSRF**: `WebhookNotificationSender` face request către URL arbitrar din config, cu metodă și headere arbitrare (`WebhookNotificationSender.php:26-27,49`), iar Slack/Discord la fel — dar URL-urile pot fi setate doar de admini (`role:admin`). Pe un tool intern, riscul e acceptat; totuși un admin poate ținti servicii din rețeaua Docker (pgbouncer, redis pe HTTP). Fără validare de scheme/IP privat. **N-P3-7.**

**Secrete:**

- Config-ul canalelor e criptat la rest (`NotificationChannel.php:44` — `'config' => 'encrypted:array'`), Telegram bot token criptat **încă o dată** individual (`ChannelForm.php:42`). Bun ca intenție, dar:
- **Token-ul Telegram e în URL-ul request-ului** (`TelegramNotificationSender.php:54`). O `ConnectionException` (subclasă de `RuntimeException`, prinsă la linia 66) are mesaj de forma „cURL error 6: Could not resolve host … for https://api.telegram.org/bot<TOKEN>/sendMessage" — mesaj care ajunge **în clar** în `notification_logs.error_message` (`SendNotificationJob.php:77`) și `notification_channels.last_error` (`SendNotificationJob.php:91-94`), afișat parțial în UI (`resources/views/livewire/settings/notification-settings.blade.php:55-57`). Anulează criptarea la rest (dump-urile DB pleacă offsite). **N-P2-4.**
- `getDecryptedConfig()` pune config-ul **decriptat** în cache-ul Redis pentru 600s (`NotificationChannel.php:58-64`) — webhook URL-uri Slack (care sunt secrete prin natura lor), signing secrets, chat ID-uri, în plaintext în Redis (a cărui parolă e condiționată de `REDIS_PASSWORD`, cf. recon). **N-P2-5.**
- La editare, bot token-ul e decriptat într-o proprietate publică Livewire și trimis în browser (`app/Livewire/Forms/ChannelFormData.php:81`), la fel `signing_secret` (`:90`) — vizibile în snapshot-ul Livewire. Admin-only, dar pattern-ul corect e write-only cu placeholder. (inclus în N-P2-5)
- Payload-urile trimise pe canale externe: verificat `NotifyIncident`, `NotifyBackupFailed` — conțin nume site, cauză, mesaj de eroare; nu am găsit URL-uri semnate sau token-uri incluse deliberat în mesaje. Mesajele de eroare de backup pot conține însă căi/detalii interne (`NotifyBackupFailed.php:37-38` — trimite `errorMessage` brut, doar cu backticks/newlines înlocuite).

**Injecții**: `NotificationCenter` escapează corect LIKE (`NotificationCenter.php:88`). Telegram folosește `parse_mode: Markdown` cu conținut neescapat — nu e injecție de securitate, dar e vector de **eșec de livrare** (vezi N-P2-7).

---

## Igienă queue/job

| Job | Coadă | tries | timeout | backoff | failed() | Unicitate |
|---|---|---|---|---|---|---|
| `SendNotificationJob` | notifications | 3 | 30s | [5,15,60] | **nu** | nu |
| `ProcessNotificationBatch` | notifications | (supervisor: 3) | (supervisor: 30s) | — | nu | scheduler `withoutOverlapping` |
| `ProcessNotificationEscalations` | default (nesetat!) | 1 | 60s | — | nu | scheduler `withoutOverlapping` |
| `SendDailyDigest` | default (nesetat) | 2 | 60s | [30,60] | da (doar Log::error) | dailyAt |
| `NotifyIncident`/`NotifyBackupFailed` | notifications | 3 | 30s | [30,60,120] | nu | nu |

- Supervisor `supervisor-notifications`: cozile `['notifications']`, 2-3 workeri, memorie 64MB, tries 3, timeout 30 (`config/horizon.php:245-256,297-299`). Observație: **`ProcessNotificationEscalations` nu setează coada** — rulează pe `default` (supervisor-general, `config/horizon.php:257-268`), deci escaladările depind de o coadă partajată cu security/performance/reports; dacă `default` e blocată de un job de 600s, escaladările întârzie.
- **Dacă coada `notifications` e blocată/moartă**: alertele critice (site down, backup failed) stau în Redis nelivrate; meta-alerta despre asta (`horizon_stopped`, `job_failures`, `horizon_long_wait` — emisă tot prin `NotificationService`) intră în **aceeași coadă moartă** → orbire completă (N-P1-3). Singura plasă de siguranță este `LongWaitDetected` (config `waits` la `redis:notifications => 60`, `config/horizon.php:101`), care are aceeași problemă.
- **Eșecul final nu e un „failed job"**: pe ultima încercare `SendNotificationJob` nu mai aruncă (`SendNotificationJob.php:97-99`), deci notificările eșuate nu apar în Horizon → `failed_jobs`, nu declanșează `Queue::failing` și nici alerta `job_failures`. Design intenționat (log în `notification_logs`), dar combinat cu lipsa UI-ului peste `NotificationLog`, eșecurile devin invizibile (N-P1-1).
- **Buffer Redis**: `rpush` + `expire 300` (`NotificationService.php:276-277`). Dacă schedulerul stă >5 min, notificările `info` bufferizate **expiră silențios**. Dacă `ProcessNotificationBatch` crapă după `lpop`, itemele extrase se pierd (fără re-push). Doar severitate `info` — acceptabil, dar nedocumentat (N-P3-2).
- Retry duplicat: dacă trimiterea reușește dar `NotificationLog::create` aruncă (ex. DB indisponibil), excepția scoate jobul în retry → mesaj duplicat pe canal. Marginal.

---

## Error handling & observabilitate

- **Vizibilitatea eșecurilor**: singura suprafață este `notification_channels.last_error` trunchiat la 60 caractere în `/settings/notifications` (`notification-settings.blade.php:55-57`). Nu există nicio pagină peste `notification_logs` (grep `NotificationLog` în `app/Livewire` + `resources/views` → zero) — nici măcar admin nu poate vedea istoricul livrărilor, rata de eșec sau ce alerte s-au pierdut. **Backupuri care eșuează + canal defect = dublă orbire.**
- **Alerting pe eșecul jobului critic de alerting**: inexistent structural (N-P1-1, N-P1-3). Nu există „dead letter": un mesaj care eșuează de 3 ori e doar un rând `failed` în `notification_logs`, fără re-livrare, fără fallback pe alt canal.
- `ProcessNotificationEscalations` prinde `\Throwable` per log și doar `Log::warning` (`ProcessNotificationEscalations.php:70-72`) — o regulă cu canal de escaladare șters (fără FK restrict verificat) eșuează silențios la fiecare rulare.
- Quiet hours **aruncă** notificările non-critice în loc să le amâne (`NotificationService.php:46-48`, `206-208`) — un `dns_changed` (warning) sau `site_up` (success) din timpul nopții nu e livrat niciodată, nici in-app (return înainte de `InAppNotification::create`). N-P2-1.
- Dedup marchează cheia **înainte** de a ști dacă ceva s-a trimis (`NotificationService.php:310-321`): dacă dispatch-ul eșuează sau toate canalele sunt filtrate, evenimentul e „consumat" 5 minute. Pentru `notifyAppEvent`, cheia e doar pe eveniment: două clase de joburi diferite care ating pragul `job_failures` în aceeași fereastră → a doua alertă e suprimată (N-P2-9).
- `InAppNotification::create` e în `try/catch (\Throwable)` gol (`NotificationService.php:92-104`) — eșec silențios asumat.

---

## Teste

**Ce există azi: nimic.** `grep -rli notification tests/` → zero fișiere. Niciun test pentru sendere, fan-out, batch, escaladări, ack, UI.

**Set minim viabil (în ordinea valorii):**

1. **Fan-out critic nu se bufferizează**: `notifySiteEvent(..., severity: 'critical')` cu un canal default activ → `Queue::assertPushed(SendNotificationJob)` imediat, nimic în `notification_buffer`. Prinde regresia „site down batch-uit".
2. **Dedup**: două apeluri `notifySiteEvent('site_down', site A)` în <5 min → un singur dispatch; site B nu e afectat.
3. **Eșec de sender → log `failed` + retry**: `Http::fake()` cu 500 pe webhook Slack → `NotificationLog` cu `status='failed'`, `last_error` setat, excepție aruncată la attempts<3, NEaruncată la attempt 3. Prinde regresii în contractul de retry.
4. **Escaladarea nu se dublează și respectă ack**: log `sent`+critical mai vechi decât delay → dispatch `[ESCALATION]` + `escalated=true`; același log la a doua rulare → nimic; log cu `acknowledged_at` → nimic. (Ar fi picat azi pe constatarea „failed nu escaladează" dacă testul acoperă și cazul `status='failed'`.)
5. **`subscribedTo` + evenimente emise**: test care aserționează că fiecare eveniment emis de aplicație (listă canonică) există în lista UI de subscripții — ar fi prins N-P1-4.
6. **AckController**: token valid → `acknowledged_at` setat; token reutilizat → 404; token inexistent → 404.
7. **`ProcessNotificationBatch` grupare**: 3 iteme `info` același canal+eveniment → un singur `SendNotificationJob` cu „3x" și severitatea maximă.

---

## Model de date

- **Indexuri**: `notification_logs` are `(status, created_at)`, `(notification_channel_id, created_at)`, `(site_id, event)`, `created_at`, unique `ack_token` (`database/schema/pgsql-schema.sql:6619-6762`) — acoperă rezonabil query-ul de escaladare și retenția. `in_app_notifications (user_id, read_at)` (`:6685`) acoperă dropdown-ul și centrul de notificări. Fără probleme fierbinți.
- **N+1**: `ProcessNotificationEscalations` accesează lazy `$log->site` per log (`ProcessNotificationEscalations.php:59`) — max 10/regulă, benign în producție; în non-producție `Model::preventLazyLoading` (`AppServiceProvider.php:50`) transformă asta în excepție prinsă de `catch (\Throwable)` → escaladările NU funcționează local fără să se vadă (N-P3-10). Componentele Livewire nu au N+1 (nu încarcă relații).
- **Consistență FK**: `notification_logs.site_id` → `ON DELETE SET NULL`, `notification_channel_id` → `ON DELETE CASCADE` (`pgsql-schema.sql:7754-7762`) — la ștergerea unui canal dispare tot istoricul lui (inclusiv dovada alertelor trimise); discutabil dar consistent. `in_app_notifications.user_id` → CASCADE.
- **Orfane / creștere**: `notification_logs` e acoperit de retenție (`app/Services/RetentionPolicyService.php:61-67`); `in_app_notifications` **nu are nicio retenție automată** — doar butonul manual `deleteOld()` per user (`NotificationCenter.php:71-79`); rândurile necitite cresc nelimitat (N-P3-4). Câte 1 rând `notification_logs` **per încercare** de retry (3 rânduri pentru un eșec complet) umflă statistica (N-P3-1).
- `severity` pe `notification_logs` e nullable și string liber; `metadata`/`data` sunt `jsonb` conform convenției proiectului.

---

## Constatări

| ID | Sev | Fișier:linii | Constatare |
|---|---|---|---|
| N-P1-1 | P1 | `app/Jobs/SendNotificationJob.php:97-99`, `app/Jobs/ProcessNotificationEscalations.php:46`, `app/Providers/AppServiceProvider.php:134-149` | **Canal defect = alerte pierdute silențios, fără escaladare, fără failed-job.** Eșecul final nu aruncă excepție → jobul nu ajunge în `failed_jobs` și nu contribuie la alerta `job_failures`; escaladarea filtrează `status='sent'`, deci exact mesajele eșuate (cazul canonic pentru escaladare) nu escaladează niciodată; nu există UI peste `NotificationLog`. **Scenariu**: webhook-ul Slack e revocat vineri; luni un site client cade și 3 backupuri eșuează — toate alertele mor cu `status='failed'`, escaladarea tace, nimeni nu află până nu deschide manual pagina de setări. **Remediere**: la eșec final, marchează jobul failed (`$this->fail()`) ca să intre în `failed_jobs`/`Queue::failing`, extinde escaladarea la `status IN ('sent','failed')` sau adaugă fallback pe alt canal, și expune un badge „X notificări eșuate în 24h" în UI. |
| N-P1-2 | P1 | `app/Jobs/SendNotificationJob.php:68`, `app/Http/Controllers/NotificationAckController.php:11-23`, `routes/web.php:245`, `app/Jobs/ProcessNotificationEscalations.php:44-67`, `app/Livewire/Settings/NotificationSettings.php:176-195` | **Ack imposibil → escaladare necondiționată + potențial de buclă.** `ack_token` se generează dar link-ul `notifications.ack` nu e inclus în niciun sender/mailable/view (grep exhaustiv) — nimeni nu poate confirma o alertă. Orice regulă de escaladare escaladează deci fiecare alertă critică după delay, indiferent că a fost tratată. În plus, mesajele `[ESCALATION]` primesc propriul log + ack_token pe canalul țintă: două reguli A→B și B→A (validarea `different:` e doar per regulă, `NotificationSettings.php:180`) produc ping-pong infinit de escaladări la fiecare 5+delay minute, în fereastra de 24h, pentru fiecare mesaj. **Remediere**: include URL-ul de ack în mesaje (buton Slack/link email; ack prin POST/confirm, nu GET simplu), sau adaugă ack din UI pe `NotificationLog`; exclude logurile cu `event`/titlu `[ESCALATION]` din reevaluare și detectează ciclurile la crearea regulilor. |
| N-P1-3 | P1 | `app/Console/Commands/HorizonHealthCheckCommand.php:28-33`, `app/Providers/AppServiceProvider.php:134-149`, `app/Services/Notifications/NotificationService.php:237-241` | **Alerta „Horizon e jos" e livrată prin coada procesată de Horizon.** `notifyAppEvent` dispatchează `SendNotificationJob` pe coada `notifications`; când supervisorii lipsesc, jobul rămâne în Redis nelivrat — health-check-ul detectează pana dar anunțul nu pleacă niciodată (cache-ul `horizon_stopped_notified` chiar suprimă re-încercarea 1h). Același defect pentru `job_failures`/`horizon_long_wait`. **Scenariu**: containerul horizon crapă noaptea; schedulerul detectează la fiecare rulare, „trimite" alerta în gol; site-down-urile din aceeași noapte sunt și ele blocate în coadă. **Remediere**: pentru `horizon_stopped`, trimite sincron (apel direct de sender + `Mail::send` fără queue) din comanda de health-check, ocolind complet coada. |
| N-P1-4 | P1 | `resources/views/livewire/settings/components/channel-form.blade.php:96-109`, `app/Models/NotificationChannel.php:73-80` | **Lista UI de subscripții e incompletă → pierdere silențioasă de alerte.** UI-ul oferă 12 evenimente; aplicația emite ~20 (lipsesc din UI: `dns_changed`, `php_fatal_error`, `safe_update_failed`, `vulnerability_detected`, `security_score_critical`, `core_files_modified`, `theme_files_modified`, `plugin_conflict_detected`, `abandoned_plugins_found`, `scheduled_task_failing`, `wordpress_version_eol`, `connection_validation_failed`, `report_reminder`, `backup_verify_failures`, `app_backup_completed`). Cum `subscribedTo()` întoarce false pentru orice eveniment neinclus în `event_subscriptions`, un admin care bifează „Site Down + Backup Failed" pe canalul principal taie fără să știe alertele de vulnerabilități, integritate core și task-uri programate care eșuează. **Remediere**: generează lista din o constantă canonică unică (extinde `NotificationTemplate::EVENTS` și consum-o în ambele locuri) și aserționează în test că evenimentele emise ⊆ listă. |
| N-P2-1 | P2 | `app/Services/Notifications/NotificationService.php:46-48, 206-208` | Quiet hours **aruncă** definitiv notificările non-critice (inclusiv in-app, return timpuriu) în loc să le amâne până dimineața. `dns_changed`, `site_up` (recovery, severitate `success`), avertismente de securitate din noapte nu sunt livrate niciodată. Remediere: bufferizează în fereastra de liniște și golește la final, sau creează măcar `InAppNotification` înainte de return. |
| N-P2-2 | P2 | `app/Livewire/Settings/NotificationSettings.php:34-49` | Toggle-urile `notify_down`/`notify_recovery`/`notify_degraded` sunt salvate dar necitite nicăieri (grep) — UI mincinos: adminul crede că a dezactivat/activat alertele de uptime, fără niciun efect. Remediere: leagă-le în `NotifyIncident`/listeneri sau elimină-le din UI. |
| N-P2-3 | P2 | `app/Services/Notifications/NotificationService.php:292-304`, `app/Models/NotificationEventPreference.php` | `NotificationEventPreference` nu are nicio suprafață de scriere (niciun UI, nicio comandă) — tabel permanent gol, cod mort pe calea fierbinte a fiecărei notificări (1 query/canal/eveniment). Remediere: construiește UI-ul de preferințe per user sau șterge mecanismul. |
| N-P2-4 | P2 | `app/Services/Notifications/TelegramNotificationSender.php:54,66`, `app/Jobs/SendNotificationJob.php:77,91-94` | Bot token-ul Telegram apare în URL; mesajele `ConnectionException` (cURL) conțin URL-ul complet și sunt persistate în clar în `notification_logs.error_message` și `notification_channels.last_error` (afișat în UI) — anulează criptarea dublă a token-ului; token-ul ajunge și în dump-urile DB offsite. Remediere: redactează token-ul din mesajele de eroare înainte de persistare (`str_replace($botToken, '***', $error)`). |
| N-P2-5 | P2 | `app/Models/NotificationChannel.php:58-66`, `app/Livewire/Forms/ChannelFormData.php:81,90` | Config-ul decriptat (webhook URL-uri Slack = secrete, signing secrets) e ținut 600s în cache-ul Redis în plaintext; la editare, bot token-ul și signing secret-ul sunt decriptate în proprietăți publice Livewire și trimise în browser. Remediere: elimină cache-ul (decriptarea e ieftină) și fă câmpurile secrete write-only cu placeholder. |
| N-P2-6 | P2 | `app/Services/Notifications/NotificationService.php:80-87`, `app/Jobs/SendNotificationJob.php:25-29` | Nicio grupare/rate-limiting pentru severități non-info: o pană generală (50 site-uri down) = 50×N joburi individuale imediate; Slack limitează webhook-urile la ~1 msg/s → 429 repetat → după 3 încercări (backoff 5/15/60s) alertele excedentare se pierd definitiv. Remediere: agregare de tip „X site-uri down" peste un prag (ex. >5 evenimente identice/min) și tratare explicită a 429 cu `release()` pe `Retry-After`. |
| N-P2-7 | P2 | `app/Services/Notifications/TelegramNotificationSender.php:42-58` | `parse_mode: Markdown` cu titlu/mesaj/nume de site neescapate: un site numit `my_site` sau un mesaj cu `*`/`_`/`` ` `` neîmperecheate → Telegram răspunde 400 „can't parse entities" → toate cele 3 încercări eșuează identic → alerta se pierde (vezi N-P1-1). Remediere: escapează caracterele Markdown sau folosește `parse_mode: HTML` cu `htmlspecialchars`. |
| N-P2-8 | P2 | `app/Services/Notifications/EmailNotificationSender.php:23-30` | Senderul de email raportează `success` imediat ce mailable-ul e pus pe coada `default` — `status='sent'` înseamnă doar „queued"; eșecul SMTP ulterior nu e reflectat nicăieri în `notification_logs`; catch-ul `TransportExceptionInterface` e mort (queue() nu atinge transportul). Remediere: trimite sincron din jobul deja queue-uit (`Mail::send`) ca rezultatul să fie real. |
| N-P2-9 | P2 | `app/Services/Notifications/NotificationService.php:51,211,310-321` | Dedup-ul marchează cheia înainte de a ști dacă s-a livrat ceva (dispatch eșuat/canale filtrate = eveniment „consumat" 5 min); pentru evenimente app cheia e doar `event` → două probleme diferite cu același tip (`job_failures` pe clase diferite) se maschează reciproc; flapping down→up→down sub 5 min suprimă a doua cădere. Remediere: mută marcarea după dispatch reușit și include un discriminator (clasa jobului, monitor id) în cheie. |
| N-P2-10 | P2 | `app/Models/NotificationTemplate.php:45-65`, `app/Jobs/NotifyIncident.php:39` | Catalogul `EVENTS` conține evenimente inexistente (`site_recovered` vs `site_up` emis, `email_blacklisted`, `content_stale`, `connector_update_failed`, `site_degraded`) — template-uri configurabile pentru evenimente care nu se emit niciodată; recovery nu poate primi template. Remediere: aliniază catalogul cu emisiile reale (aceeași sursă unică cerută la N-P1-4). |
| N-P2-11 | P2 | `app/Services/Notifications/NotificationService.php:95-101,195-243` | In-app: doar `site->user_id` primește notificarea (restul echipei nu vede nimic în clopotel pentru site-urile colegilor), iar `notifyAppEvent` nu creează deloc `InAppNotification` (Horizon down, job failures — invizibile în centrul de notificări). Remediere: fan-out către toți userii activi (sau pe rol) pentru severități ≥ warning. |
| N-P3-1 | P3 | `app/Jobs/SendNotificationJob.php:70-85` | Un rând `NotificationLog` per încercare de retry (3 rânduri `failed` pentru un singur mesaj pierdut) — statistici umflate, escaladări potențial triplicate dacă un retry târziu reușește după un failed. Remediere: log doar pe încercarea finală sau `updateOrCreate` pe un idempotency key. |
| N-P3-2 | P3 | `app/Services/Notifications/NotificationService.php:276-277`, `app/Jobs/ProcessNotificationBatch.php:36-41` | Bufferul Redis expiră la 5 min dacă schedulerul stă; itemele `lpop`-uite se pierd la crash-ul jobului (fără re-push/tranzacționalitate). Doar `info`. Remediere: `RPOPLPUSH` într-o listă de procesare sau acceptă și documentează. |
| N-P3-3 | P3 | `app/Services/Notifications/NotificationService.php:314-318` | `isDuplicate` e check-then-set neatomic (`Cache::has` + `Cache::put`) — două joburi concurente pe workeri diferiți pot trece amândouă. Remediere: `Cache::add()` (atomic). |
| N-P3-4 | P3 | `app/Services/RetentionPolicyService.php:61-67`, `app/Livewire/Notifications/NotificationCenter.php:71-79` | `in_app_notifications` nu are retenție automată (doar buton manual per user, care șterge doar citite >30 zile) — creștere nelimitată. Remediere: adaugă tabelul în politica de retenție. |
| N-P3-5 | P3 | `app/Jobs/ProcessNotificationBatch.php:97-105` | Titlul grupat `"{$count}x {$first['title']}"` e `"5x "` pentru mesajele slim (title gol); mesajele individuale se pierd din sumar (doar numele site-urilor). Remediere: folosește prima linie a mesajului drept titlu la title gol. |
| N-P3-6 | P3 | `app/Http/Controllers/NotificationAckController.php:11-23`, `routes/web.php:245` | Ack e GET cu efect secundar; dacă link-ul va fi livrat pe Slack/Telegram (fix-ul N-P1-2), unfurl-botul platformei îl va accesa și va ack-ui automat alerta, anulând escaladarea. Remediere: pagină GET de confirmare + POST pentru ack. |
| N-P3-7 | P3 | `app/Services/Notifications/WebhookNotificationSender.php:26-49` | URL/metodă/headere arbitrare din config (admin-only) — SSRF către rețeaua Docker internă; semnătura HMAC e calculată pe `json_encode` propriu care poate diferi de body-ul efectiv trimis (mai ales la `method=GET`, unde datele pleacă în query, nu în body). Remediere: restricționează scheme la https + blochează IP-uri private; semnează exact body-ul trimis. |
| N-P3-8 | P3 | `app/Jobs/ProcessNotificationEscalations.php:51`, constructor fără `onQueue` | `limit(10)` fără `orderBy` → selecție nedeterministă la backlog; jobul rulează pe coada `default` (nesetată), partajată cu joburi de 600s. Remediere: `orderBy('created_at')` + `onQueue('notifications')`. |
| N-P3-9 | P3 | `app/Jobs/SendDailyDigest.php:34-40` | Digestul zilnic se trimite către toți userii, fără opt-out și fără verificare de user activ/rol. Remediere: preferință per user. |
| N-P3-10 | P3 | `app/Jobs/ProcessNotificationEscalations.php:59`, `app/Providers/AppServiceProvider.php:50` | `$log->site` lazy-load: în non-producție `preventLazyLoading` aruncă, excepția e înghițită de `catch (\Throwable)` → escaladările nu funcționează în dev/test fără semnal. Remediere: `->with('site')` pe query. |

**Contor: P0 = 0 · P1 = 4 · P2 = 11 · P3 = 10.**

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-urile existente

1. **Pagină „Delivery log" peste `NotificationLog`** (datele există deja, indexate): listă filtrabilă sent/failed per canal + badge global „X livrări eșuate în 24h" în clopotel. Închide jumătate din N-P1-1 fără schimbări de pipeline. *(S)*
2. **Livrarea link-ului de ack** în Slack (block-kit button) și email — activează întregul lanț ack→escaladare deja construit (token, rută, view, reguli există; lipsește un singur append de URL). *(S)*
3. **Sursă unică de adevăr pentru evenimente**: un enum `NotificationEvent` (există convenția `app/Enums/`) consumat de UI-ul de subscripții, `NotificationTemplate::EVENTS` și emitenți — elimină N-P1-4 și N-P2-10 dintr-o mișcare. *(S)*
4. **Fallback de canal la eșec final** („dacă Slack pică de 3 ori, trimite pe email") — o coloană `fallback_channel_id` pe `notification_channels` + o ramură în `SendNotificationJob` la eșecul final. *(M)*
5. **Amânare în loc de drop la quiet hours**: bufferizează non-criticele în fereastra de liniște și livrează un sumar la `quiet_hours_end` — infrastructura de buffer + batch există deja. *(M)*

### (b) Feature-uri noi

1. **Heartbeat extern pentru pipeline-ul de alerting** (stil Dead Man's Snitch / healthchecks.io, cum fac SpinupWP și ManageWP pentru monitoring): schedulerul face un ping HTTP la un serviciu extern la fiecare rulare a `ProcessNotificationBatch`; absența ping-ului alertează din afară — singura soluție reală la N-P1-3 („cine păzește paznicul"). *(S)*
2. **Rută de on-call cu ferestre orare** (benchmark: escalation policies din WPMU DEV/PagerDuty-lite): reguli de escaladare cu program (zi/noapte/weekend) și destinatar per fereastră — modelul `NotificationEscalationRule` are deja delay+severitate, lipsesc doar câmpurile de program. *(M)*
3. **Rezumat săptămânal per client cu alertele + acțiunile luate**, atașabil rapoartelor existente (modulul 18 are deja pipeline Gotenberg/Postmark): transformă `notification_logs` în valoare vandabilă către client, à la ManageWP „client report". *(M)*
