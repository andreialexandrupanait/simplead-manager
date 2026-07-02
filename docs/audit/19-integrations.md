# 19 — Integrări externe (Cloudflare, Google Analytics/Search Console, Dropbox, Postmark, Unsplash) + webhook inbound

**Data:** 2026-07-02 · **Auditor:** audit senior Laravel/securitate · **Scope:** working tree complet (inclusiv cod necomis).

Fișiere auditate: `app/Services/CloudflareService.php`, `GoogleApiService.php`, `GoogleAnalyticsService.php`, `GoogleSearchConsoleService.php`, `PostmarkService.php`, `UnsplashService.php`, `ScreenshotService.php`; `app/Jobs/{SyncCloudflareZone,FetchAnalyticsData,FetchSearchConsoleData,ValidateExternalConnections}.php`; `app/Http/Controllers/{GoogleAuthController,DropboxAuthController,WebhookController}.php`; `app/Livewire/Settings/IntegrationsSettings.php`, `app/Livewire/Sites/Detail/{SiteCloudflare,SiteAnalytics,SiteSearchConsole}.php`; `app/Dispatchers/DataSyncDispatcher.php`, `MonitoringDispatcher.php`; modele `CloudflareConnection`, `GoogleConnection`, `AnalyticsConnection`, `SearchConsoleConnection`; `app/Services/{SettingsService,CircuitBreakerService,ActivityLogger}.php`; `routes/web.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `database/schema/pgsql-schema.sql`.

---

## Rezumat executiv

Modulul e funcțional și, în cea mai mare parte, corect din punct de vedere al stocării credențialelor: token-urile OAuth Google și API token-ul Cloudflare sunt criptate (`casts` `encrypted`), OAuth-ul folosește `state` anti-CSRF și scope-uri Google `readonly` (minimizate). Există însă trei probleme serioase.

1. **Cuplaj periculos între integrări și circuit breaker (P1):** eșecul API-urilor Google (Analytics/Search Console) e înregistrat ca *failure* pe circuit breaker-ul SITE-ului (`CircuitBreakerService::recordFailure($this->site)`). După 3 eșecuri circuitul se deschide, iar `MonitoringDispatcher` **oprește uptime + security scan** pentru acel site client. O pană Google sau un token Google expirat poate dezactiva silențios monitorizarea de securitate/uptime a unui site de client.
2. **Gaură de autorizare pe Cloudflare (P1):** acțiunile disruptive din `SiteCloudflare` (purge cache total, purge URLs, connect/disconnect zonă) verifică doar `authorizeSiteAccess` (în `mount`), niciodată `authorizeSiteModification`. Un **Viewer** cu acces la site poate purja tot cache-ul Cloudflare al clientului sau re-lega zona.
3. **Webhook inbound public, fără validare (P1):** `/api/webhooks/inbound` nu are auth/semnătură; scrie necontrolat în `activity_logs` și în log-ul Laravel din input controlat de atacator (log injection + inflație DB/disk). Feature mort — nu există pipeline de „leads", doar logging.

Restul: `ValidateExternalConnections` e un job monolitic sincron cu `timeout=300, tries=1` care poate expira la multe site-uri fără notificare; degradare silențioasă a sync-ului (rapoartele folosesc cache vechi fără avertisment); zero teste pe tot modulul.

---

## Inventar & corectitudine

- **Cloudflare** (`CloudflareService.php`): wrapper complet peste API v4 (zone, DNS CRUD, purge cache în 4 variante, security level, firewall rules, WAF, access rules/IP block, SSL, analytics GraphQL). Doar o parte e expusă în UI: `SiteCloudflare` folosește listZones, getZoneDetails, listDnsRecords (read-only), purgeEverything, purgeByUrls, getSslMode, getAnalytics. Metodele DNS write (`createDnsRecord`/`updateDnsRecord`/`deleteDnsRecord`), firewall, WAF, access-rules, `blockIpViaCloudflare` **nu sunt apelate din niciun entry point** al acestui modul (`grep` confirmă lipsa consumatorilor) — cod mort/latent din perspectiva UI. (`CloudflareService.php:124-276`).
- **Google Analytics / Search Console**: servicii bogate (`GoogleAnalyticsService.php`, `GoogleSearchConsoleService.php`) extinse din `GoogleApiService.php`. Consumate de `FetchAnalyticsData`/`FetchSearchConsoleData` (sync programat) și de Reports (`Reports/Sections/AnalyticsGatherer.php`, `SearchConsoleGatherer.php`).
- **Dropbox** (`DropboxAuthController.php`): doar OAuth → creează `StorageDestination type=dropbox`. Aparține de fapt modulului Backups (storage); aici auditat doar fluxul OAuth.
- **Postmark** (`PostmarkService.php`): read-only (listare domenii, descoperire selector DKIM). Folosit de DNS/email tooling.
- **Unsplash** (`UnsplashService.php`): pur cosmetic — imagini pentru slideshow-ul paginii de login (`guest.blade.php`).
- **Screenshot** (`ScreenshotService.php`): capturi via Gotenberg pentru safe-update (modulul 13); nu e un entry point al acestui modul.
- **Webhook inbound** (`WebhookController.php`): 18 linii, doar loghează. Confirmă nota din harta modulelor: „fostul Leads nu există". **Feature pe jumătate / mort.**

Nu există TODO-uri notabile în fișiere. Comentariul din `GoogleSearchConsoleService::getExternalLinks` (`:433-456`) recunoaște că GSC nu are API de linkuri și returnează un fallback aproximativ (top pages ca „external links") — date înșelătoare, dar documentate.

---

## Siguranța operațiilor distructive

Singura operație cu impact în acest modul e **purge cache Cloudflare** (disruptiv, nu pierdere de date: golește cache-ul edge → spike pe origine).

- **Fără confirmare / dry-run:** `purgeEverything()` execută imediat, fără modal de confirmare la nivel de metodă (posibil doar în blade). (`SiteCloudflare.php:156-186`).
- **Rate limit prezent:** 5 purge/min/(site×user) (`SiteCloudflare.php:163-168`) + rate-limiter global CF 200/min/conn (`CloudflareService.php:430,455`).
- **Audit logging parțial:** purge-ul e înregistrat în `cache_purges` cu `purged_by = auth()->id()` și tip/target (`SiteCloudflare.php:175-179, 208-213`) — bun (cine, ce, când). DAR connect/disconnect zonă **nu** sunt audit-logate.
- **Autorizare insuficientă:** vezi I-P1-2 — niciun gate pe rol pentru purge/connect/disconnect.
- **Idempotență/locking:** purge e idempotent prin natura lui; nu e nevoie de lock.

`SyncCloudflareZone` e read-only (doar update status/SSL în DB local), non-distructiv.

---

## Securitate

**Stocare credențiale — în general corect:**
- `CloudflareConnection.api_token` → cast `encrypted` (`CloudflareConnection.php:39`). Creat prin `create(['api_token' => ...])`, cast-ul criptează la persist (`IntegrationsSettings.php:439-442`).
- `GoogleConnection.access_token`/`refresh_token` → cast `encrypted` (`GoogleConnection.php:47-48`), plus `encrypt()` explicit la scriere în controller (dublă — inofensiv). (`GoogleAuthController.php:91-92`).
- Postmark token, Google client_secret, Dropbox app_secret, OpenAPI/Anthropic/OpenAI keys → `encrypt()` în `AppSetting` (`IntegrationsSettings.php:143,176,211,245,347,390`).
- **Excepție:** `unsplash_access_key` și `google_client_id`/`dropbox_app_key` stocate **plaintext** (`IntegrationsSettings.php:175,194,244`). Client-ID/app-key sunt semi-publice; Unsplash access key e trimis oricum ca `client_id` în query (`UnsplashService.php:36`) → sensibilitate mică (P3, inconsistență).

**Authz pe entry points:**
- Rutele OAuth (`google.auth/callback`, `dropbox.auth/callback`) și pagina Integrations sunt sub `auth`+`role:admin` (`routes/web.php:192,234-235`) — corect.
- `SiteCloudflare`, `SiteAnalytics`, `SiteSearchConsole` sunt sub `auth` dar **fără gate de rol**; se bazează pe `authorizeSiteAccess` în `mount`. Analytics/SearchConsole sunt read-only (fetch în cache) → acceptabil pentru Viewer. **Cloudflare NU e read-only** → I-P1-2.
- **Webhook inbound**: fără auth (`routes/web.php:37`) → I-P1-3.

**OAuth:**
- `state` anti-CSRF generat cu `random_bytes(32)` și verificat (`GoogleAuthController.php:33,62`; `DropboxAuthController.php:22,44`) — corect.
- `return_url` la Google validat pe host (open-redirect prevenit) (`GoogleAuthController.php:22-29`) — bun.
- Scope Google minimizat: `analytics.readonly`, `webmasters.readonly` (`GoogleAuthController.php:44-45`) — corect. Dropbox cere `files.content.write` (necesar pentru backup upload).
- `refresh_token` absent → se stochează `encrypt('')` (`GoogleAuthController.php:92`); combinat cu `prompt=consent` (mereu re-consimțământ) riscul e mic, dar la refresh un token gol duce la `is_active=false` (`GoogleApiService.php:31-47`). P3.

**Injecții / SSRF:**
- `getAnalytics` interpolează `$zoneId` direct în query GraphQL (`CloudflareService.php:315`). `zone_id` provine din `connectSiteToZone($selectedZoneId)` fără a valida că e o zonă reală din `availableZones` (`SiteCloudflare.php:96-100`). Un admin ar putea injecta un `"` și rupe query-ul. Admin-only → risc redus (P3). `since` e cast `(int)` — sigur (`CloudflareService.php:291`).
- Fără SSRF direct în entry points: webhook-ul nu face fetch; `inspectUrl`/`purgeByUrls` trimit URL-uri către Google/Cloudflare, nu către infra proprie.

**Secrete în loguri:**
- `WebhookController` loghează `payload_keys` și primele 5 câmpuri din payload (`:19,25`) — dacă un webhook legitim ar trimite secrete, ajung în `activity_logs`/log. Combinat cu lipsa auth, mai grav ca inflație (I-P1-3).
- Serviciile Google aruncă `->body()` complet în mesajul excepției (`GoogleAnalyticsService.php:26,67` etc.) → corpul răspunsului API (ne-sensibil de regulă) ajunge în `last_error`/log. Minor.

---

## Igienă queue/job

- `FetchAnalyticsData` / `FetchSearchConsoleData`: `ShouldBeUnique` (uniqueId per site), `tries=2`, `timeout=120`, `backoff=[30,60]`, `failed()` implementat. Corect. (`FetchAnalyticsData.php:19-41,115-119`).
- `SyncCloudflareZone`: `ShouldBeUnique`, `tries=2`, `timeout=60`, `backoff=[15,30]`, `failed()` loghează. Corect. (`SyncCloudflareZone.php:18-37,77-83`).
- **`ValidateExternalConnections`: `tries=1`, `timeout=300`, NU `ShouldBeUnique`.** Iterează sincron TOATE conexiunile Google + Cloudflare + storage + **toate site-urile WP cu `healthCheck()`** într-un singur job (`ValidateExternalConnections.php:28,41-47,137-152`). La un parc mare, health-check-urile WP pot depăși 300s → job expiră la mijloc, validare parțială, fără retry și **fără notificare** (notificarea se trimite doar dacă bucla se termină cu `failures>0`, `:49-60`). P2.
- **Ce pățește modulul dacă coada `sync` e blocată:** sync-ul de analytics/SC/CF se acumulează; fiindcă `ShouldBeUnique` previne duplicate, nu explodează, dar datele devin stale silențios (vezi observabilitate). `next_sync_at` e avansat în dispatcher *înainte* de execuție (`DataSyncDispatcher.php:48,67,79`), deci un job pierdut nu se re-programează imediat — se așteaptă următorul interval.

---

## Error handling & observabilitate

- **Degradare silențioasă (P2):** când `FetchAnalyticsData`/`FetchSearchConsoleData` eșuează, se salvează `last_error` pe conexiune (`FetchAnalyticsData.php:110`) și `JobTracker::fail`, dar **nu se emite nicio notificare**. Rapoartele citesc pur și simplu ultimul `AnalyticsCache` (`AnalyticsGatherer.php:27-33`) — dacă e vechi/expirat, raportul livrează date stale clientului fără avertisment. Nu există verificare `expires_at` în gatherer.
- **Alerting există doar la nivel agregat zilnic:** `ValidateExternalConnections` trimite `NotificationService::notifyAppEvent` dacă găsește eșecuri (`:53-60`) — util, dar rulează o dată pe zi (`console.php:167`) și e vulnerabil la timeout (mai sus).
- **Cuplaj circuit breaker → monitorizare (I-P1-1):** eșecul unei integrări externe (Google) e mascat ca problemă a site-ului și **oprește** uptime/security scan — invers decât ai vrea: o problemă de securitate reală nu mai e scanată pentru că Analytics a picat. Vizibilitatea e slabă (doar `Log::info`/`warning`).
- `SyncCloudflareZone::failed()` loghează fără mesaj (doar clasa excepției, `:79-82`) — greu de diagnosticat.

---

## Teste

**Azi: ZERO teste pentru acest modul.** `grep` pe `tests/` pentru Cloudflare/Google/Analytics/SearchConsole/Webhook/Unsplash/Postmark/Dropbox → niciun rezultat. Există doar `tests/Feature/{CriticalSchemaTest,Jobs,Services}` și `tests/Unit/*` fără acoperire aici.

**Set minim viabil (cele mai periculoase regresii):**
1. **Authz Cloudflare:** un Viewer cu acces la site NU poate apela `purgeEverything`/`connectToZone`/`disconnectZone` (prinde I-P1-2).
2. **Circuit breaker decuplat:** un eșec `FetchAnalyticsData` NU deschide circuitul care oprește uptime/security (prinde I-P1-1) — sau, dacă comportamentul e intenționat, testează explicit că nu dezactivează monitorizarea de securitate.
3. **Webhook:** endpoint-ul respinge/limitează payload-uri și nu creează `activity_logs` nelimitat din input neautentificat (prinde I-P1-3).
4. **OAuth state mismatch:** `google.callback`/`dropbox.callback` cu `state` greșit → 403 (`GoogleAuthController.php:62`).
5. **Refresh token Google:** token expirat + refresh eșuat → `is_active=false`, excepție clară (`GoogleApiService.php:44-47`).
6. **Criptare credențiale:** `CloudflareConnection.api_token`/`GoogleConnection.refresh_token` sunt stocate criptat (nu plaintext) în DB.
7. **`ValidateExternalConnections`:** un token Google invalid produce o intrare în `failures` și notificare.

---

## Model de date

- **Indexuri — OK pe query-urile fierbinți:** `analytics_cache(site_id, date_range)` și `search_console_cache(site_id, date_range, data_type)` indexate (`pgsql-schema.sql:6377,6881`), plus `fetched_at`. `activity_logs` are index pe `(site_id, created_at)`, `created_at`, `type`, `severity` (`:6349-6370`) — dar tabelul e ținta inflației din webhook (I-P1-3).
- **N+1:** `IntegrationsSettings::render` folosește `withCount(['analyticsConnections','searchConsoleConnections'])` (`:505`) — fără N+1. `DataSyncDispatcher` folosește `with('site')` (`:43,62`). OK. `SiteCloudflare::cachePurges` face eager `with('purgedBy')` (`:153`). OK.
- **Soft-delete/orfani:** `AnalyticsConnection`/`SearchConsoleConnection`/`SiteCloudflare` nu au `SoftDeletes`. La ștergerea unui `GoogleConnection` (`disconnectAccount`, `IntegrationsSettings.php:329`) se face `delete()` fără a curăța `AnalyticsConnection`/`SearchConsoleConnection` care referă `google_connection_id` → potențiale rânduri orfane care apoi eșuează la fetch (`$google` null → job „Skipped", `FetchAnalyticsData.php:55-59`). Degradare gestionată, dar rămâne datorie (P3). `AnalyticsCache`/`SearchConsoleCache` nu au TTL-cleanup automat vizibil în acest modul (au `expires_at` dar nimeni nu-l șterge aici).
- `GoogleConnection.token_expires_at` e nullable (`GoogleConnection.php:19`) dar `GoogleApiService::ensureValidToken` apelează `->isFuture()` fără null-check (`GoogleApiService.php:25`) → fatal dacă vreodată e null (P2).

---

## Constatări

| ID | Sev | Fișier:linii | Descriere | Scenariu de eșec | Remediere schiță |
|---|---|---|---|---|---|
| I-P1-1 | P1 | `FetchAnalyticsData.php:117`; `FetchSearchConsoleData.php:119`; `MonitoringDispatcher.php:36-37,52-53` | Eșecul API-urilor Google e înregistrat ca failure pe circuit breaker-ul site-ului; la 3 eșecuri circuitul se deschide și dispatcher-ul oprește uptime + security scan. | Token Google expiră / pană GA API → analytics eșuează 3× → circuit `open` → uptime & security scan sărite ≥60 min pentru site de client; la 3 break-uri/24h monitorizarea se dezactivează complet. Problemă de securitate reală rămâne nescanată. | Nu trata eșecul unei integrări terțe ca health a site-ului: elimină `recordFailure` din aceste job-uri sau folosește un contor de circuit separat, decuplat de monitorizarea uptime/security. |
| I-P1-2 | P1 | `SiteCloudflare.php:37-45,88-115,156-221` | Acțiunile purge cache total, purge URLs, connect/disconnect zonă cer doar `authorizeSiteAccess` (în `mount`), niciodată `authorizeSiteModification`; fără gate de rol pe rută. | Un utilizator `Viewer` alocat unui site deschide tab-ul Cloudflare și apasă „Purge everything" → golește tot cache-ul edge al site-ului de client (spike pe origine), sau `disconnectZone` rupe integrarea. Rol read-only execută operații disruptive. | Apelează `authorizeSiteModification($this->site)` la începutul fiecărei metode care schimbă starea (purge/connect/disconnect), sau adaugă `role` gate. |
| I-P1-3 | P1 | `WebhookController.php:14-30`; `routes/web.php:37` | `/api/webhooks/inbound` fără auth/semnătură/validare; scrie necontrolat în `activity_logs` și în log-ul Laravel din input controlat de atacator. Feature mort (fără pipeline de leads). | Atacator (rotind IP-uri, throttle 60/min/IP) inundă `activity_logs` și fișierul de log cu intrări arbitrare (`title`/`description` din body + header `X-Webhook-Source`) → umflă DB/disk, poluează timeline-ul de activitate, log injection prin newline în header. | Adaugă verificare de semnătură HMAC (secret configurabil) și respinge fără ea; validează/limitează schema payload-ului; sau elimină ruta cât timp nu există consumator. |
| I-P2-1 | P2 | `ValidateExternalConnections.php:28,41-47,137-152` | Job monolitic sincron: `tries=1`, `timeout=300`, non-unique; iterează toate conexiunile + toate site-urile WP cu `healthCheck()`. | La parc mare, health-check-urile WP depășesc 300s → job expiră la mijloc; validare parțială, fără retry, fără notificare (notificarea cere terminarea buclei). Conexiuni picate nedetectate. | Împarte pe job-uri per-tip/per-conexiune dispecerizate în coadă, sau mută health-check-urile WP în alt job; adaugă notificare pe timeout/`failed()`. |
| I-P2-2 | P2 | `FetchAnalyticsData.php:110`; `AnalyticsGatherer.php:27-33`; `SearchConsoleGatherer.php:27` | Eșecurile de sync se salvează doar în `last_error`; niciun alert. Rapoartele citesc ultimul cache fără a verifica `expires_at`. | Sync-ul Analytics eșuează silențios zile la rând; raportul lunar către client livrează date vechi/goale fără avertisment. | Notifică la eșec repetat de sync; în gatherers verifică prospețimea cache-ului și marchează datele stale în raport. |
| I-P2-3 | P2 | `GoogleApiService.php:25`; `GoogleConnection.php:19` | `token_expires_at` e nullable dar `->isFuture()` se apelează fără null-check. | O conexiune fără `token_expires_at` (import/migrare/edge) → `Call to a member function isFuture() on null` → fatal la orice fetch pe acea conexiune. | Tratează `null` ca expirat: `if ($this->connection->token_expires_at?->isFuture())`. |
| I-P2-4 | P2 | `tests/` (absent) | Zero teste pentru tot modulul (integrări + webhook). | Orice regresie la authz Cloudflare, criptare token sau cuplaj circuit breaker trece nedetectată. | Implementează setul minim viabil (secțiunea Teste). |
| I-P3-1 | P3 | `IntegrationsSettings.php:194,175,244` | `unsplash_access_key` (și `dropbox_app_key`, `google_client_id`) stocate plaintext, inconsistent cu convenția de criptare. | Un dump DB expune access key-ul Unsplash (sensibilitate mică; e trimis oricum ca `client_id`). | Criptează pentru consistență sau documentează explicit că sunt valori semi-publice. |
| I-P3-2 | P3 | `CloudflareService.php:315`; `SiteCloudflare.php:96-100` | `zone_id` interpolat direct în query GraphQL; `selectedZoneId` nevalidat față de zonele reale. | Admin (deja privilegiat) poate injecta `"` și rupe query-ul analytics; impact redus fiindcă e admin-only. | Validează că `zone_id` respectă `^[a-f0-9]{32}$` înainte de folosire. |
| I-P3-3 | P3 | `GoogleAuthController.php:92` | `refresh_token` absent → se stochează `encrypt('')`. | Dacă Google omite refresh_token, la expirarea access-token-ului refresh-ul cu string gol eșuează → `is_active=false` (recuperabil prin reconectare). | Nu suprascrie refresh_token-ul existent când Google nu returnează unul nou. |
| I-P3-4 | P3 | `WebhookController.php:17,19` | `X-Webhook-Source` intră netratat în mesajul de log și în titlul activității. | Newline în header → log injection / intrări false în timeline. | Sanitizează headerul (whitelist alfanumeric, trunchiere). |
| I-P3-5 | P3 | `IntegrationsSettings.php:329`; `AnalyticsConnection`/`SearchConsoleConnection` | Ștergerea `GoogleConnection` nu curăță `AnalyticsConnection`/`SearchConsoleConnection` care o referă. | Rânduri orfane cu `google_connection_id` invalid; job-urile le sar ca „Skipped" dar rămân în UI ca conexiuni „active". | Cascade delete (FK `onDelete('cascade')`) sau curățare explicită la disconnect. |

**Total:** P0 = 0 · P1 = 3 · P2 = 4 · P3 = 5.

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-uri existente

1. **Decuplează circuit breaker-ul de integrări** (rezolvă I-P1-1 și dă valoare): un breaker separat per-integrare per-site care doar pauzează sync-ul acelei integrări, nu monitorizarea de securitate. Fricțiune reală eliminată: astăzi o problemă Google poate orbi security scan-ul.
2. **Indicator de prospețime a datelor în UI și rapoarte:** afișează „ultimul sync acum X / date stale" pe `SiteAnalytics`/`SiteSearchConsole` și în PDF-ul de raport când `expires_at` a trecut — evită livrarea de date vechi fără avertisment.
3. **Buton „Test connection" și status vizibil pentru Google** în Integrations (există deja pentru Cloudflare/Storage/OpenAPI/AI, dar nu pentru conexiunile Google) — plus afișarea `last_error` per conexiune.
4. **Notificare la expirarea/revocarea token-urilor OAuth**, nu doar log: `ValidateExternalConnections` are deja infrastructura de notificare; extinde-o cu detecție de revocare (un apel API ușor, nu doar verificarea `token_expires_at`).
5. **Audit log pentru connect/disconnect zonă Cloudflare** (există deja pentru purge) — cine-a-legat/dezlegat ce zonă.

### (b) Feature-uri noi (ancorate în cod existent)

1. **DNS management din UI (S/M):** `CloudflareService` are deja `createDnsRecord/updateDnsRecord/deleteDnsRecord` complet implementate dar neexpuse; un tab „DNS" editabil (cu confirmare + audit) aduce paritate cu SpinupWP/ManageWP la efort mic. Necesită gate strict de rol (vezi I-P1-2).
2. **Firewall / WAF / IP-block orchestrat din incident response (M):** metodele `enableWaf`, `setSecurityLevel`, `blockIpViaCloudflare` există dar sunt cod mort; conectarea lor la playbook-urile de securitate (modulul 14) ar permite „sub atac → ridică security level / blochează IP la edge" — diferențiator față de WPMU DEV/MainWP.
3. **Webhook inbound funcțional și sigur (S):** transformă endpoint-ul mort într-un receiver semnat (HMAC) care creează notificări/incidente reale (uptime terț, form spam) — valoare imediată odată ce I-P1-3 e rezolvat, aliniat cu status pages/notificări (modulele 20/21).
