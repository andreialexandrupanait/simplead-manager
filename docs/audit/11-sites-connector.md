# 11 — Sites & comunicarea cu connector plugin-ul

**Data:** 2026-07-02 · **Auditor:** revizuire senior Laravel/securitate · **Scope:** registrul de site-uri, clientul HTTP HMAC către WP, sincronizare, push connector, comenzi, fetch loguri PHP, favicon/screenshots, ȘI întreg plugin-ul WP `wordpress-plugin/simplead-manager-connector/` (v2.14.0). Audit pe working tree (branch `main`), inclusiv codul necomis.

---

## Rezumat executiv

Modulul este nucleul operațional al platformei: fiecare acțiune distructivă pe un site de client trece prin `WordPressHttpClient` (semnare HMAC-SHA256 cu nonce). Fundația criptografică e solidă — chei criptate la repaus (`encrypted` cast), HMAC pe METHOD|PATH|TS|NONCE|BODY, fereastră de timp 5 min, anti-replay cu nonce, IP whitelist + rate limiter pe plugin. Nu am găsit un P0 (breșă directă / distrugere imediată / scurgere de credențiale); blast radius-ul unui site compromis către manager sau alte site-uri este, din fericire, **contenit** (managerul inițiază tot traficul; direcția site→manager e practic ne-funcțională).

Cele mai grave probleme sunt două defecte confirmate:
1. **IDOR pe `SiteSettings`** (și `ReportRecommendationsManager`): `mount()` nu apelează `authorizeSiteAccess()`, deci orice utilizator autentificat — inclusiv un `viewer` sau un membru care nu deține site-ul — poate accesa `/sites/{oricare}/settings` și poate **dezactiva modulele de backup/securitate** pe site-urile altor clienți, fără audit.
2. **Autentificarea agentului este permanent stricată**: `AuthenticateAgent` face `Site::where('api_key', $token)` pe o coloană cu cast `encrypted` (ciphertext ne-determinist) — clauza nu se potrivește niciodată, deci API-ul agent răspunde mereu 401 (eșec silențios).

Probleme serioase secundare: fără scoping de tenant pe `/error-logs` (orice user vede logurile PHP ale tuturor site-urilor), calea HMAC legacy fără nonce încă acceptată de plugin, SSRF ne-restricționat pe URL-urile de site introduse manual, lipsa unui flux real de rotire a cheilor, și feature-ul SEO care lovește WP cu antet greșit (`X-SAM-API-Key`) fără HMAC → 401 mereu.

---

## Inventar & corectitudine — ce face de fapt modulul

**Registrul de site-uri.** `Site` (`app/Models/Site.php`) — coloană `url` (nu `domain`), chei `api_key`/`api_secret` cu cast `encrypted` (`Site.php:177-178`), `api_endpoint` opțional. `booted()` (`Site.php:186-217`) dispecerizează favicon + aplică planul de mentenanță la creare. `SitesList` (`app/Livewire/Sites/SitesList.php:22-23`) scopează corect pe `user_id` pentru non-admini. `CreateSiteWizard` creează site-uri fără credențiale (se completează ulterior din `SiteOverview::saveCredentials`, `SiteOverview.php:355-373`).

**Clientul HTTP.** `WordPressApiService` (facade + trait-uri `Manages*`) delegă la `WordPressHttpClient` (`app/Services/WordPress/WordPressHttpClient.php`). Semnarea HMAC (`buildAuthHeaders`, `WordPressHttpClient.php:81-103`) trimite **întotdeauna** nonce (v2.0). Throttling adaptiv, retry pe 429 + erori curl tranzitorii, streaming curl pentru backup-uri. Corect implementat.

**Sincronizare.** `SyncWordPressSite` (`app/Jobs/SyncWordPressSite.php`) — `ShouldBeUnique` (`uniqueId = sync-wp-{id}`), `tries=3`, backoff `[30,60,120]`, `failed()` înregistrează circuit-breaker. Bine construit. Ingestă plugins/themes/users cu `updateOrCreate` + ștergere `whereNotIn` a orfanilor (`SyncWordPressSite.php:102-104,133-136,178-181`).

**Push connector.** `PushConnectorPlugin` (`app/Jobs/PushConnectorPlugin.php`) construiește ZIP-ul din sursă, calculează `sha256`, trimite `download_url` (signed, 30 min) + `expected_hash` la `/self-update`. Plugin-ul WP (`class-self-update-endpoint.php`) descarcă, verifică hash-ul, face backup pentru rollback, rulează `Plugin_Upgrader`, rollback la eșec. Flux robust. Declanșat din `Settings/WordPressSettings.php` (rută `role:admin`) și `connector:update`.

**Comenzi.** `BulkAddSites` (listă hardcodată de clienți reali — one-off, `app/Console/Commands/BulkAddSites.php`), `UpdateConnectorPlugin` (`connector:update --site=|--all`). `ManageSites` — neverificat în detaliu (nu ridică semnale de securitate la citirea semnăturii).

**Fetch loguri PHP.** `FetchPhpErrorLogs` (`ShouldBeUnique`, `tries=1`, `timeout=60`) + `ErrorLogs/ErrorLogsOverview` — vezi P1-3 (fără scoping de tenant).

**Cod mort / feature-uri pe jumătate:**
- `rotateApiKeys()` (`app/Services/WordPress/Concerns/ManagesSiteInfo.php:105-111`) și endpoint-ul WP `/rotate-keys` (`class-key-rotation-endpoint.php`) **nu au niciun apelant** în manager (grep: 0 rezultate) → rotire de chei ne-implementată (S-P2-3).
- API-ul agent `/agent/{site_token}/security/*` (`routes/api.php`) nu are **niciun client** în plugin-ul connector (grep pe `pending-commands`/`X-Signature`/`agent/` în `wordpress-plugin/` → 0). Combinat cu S-P1-2, întregul canal pare mort/latent.
- `config('connector.current_version')` nu există în `config/connector.php` (doar `changelog`) → ETag-ul din `ConnectorPluginDownloadController.php:40` e mereu `connector-0.0.0` (S-P3-1).
- `config/connector.php` changelog e stagnat (top = `unreleased`/`2.9.15`) deși plugin-ul e la 2.14.0.

**Corect (fără finding):** header `Version: 2.14.0` (`simplead-manager-connector.php:6`) == `SAM_VERSION` (`:20`) — sincronizate.

---

## Siguranța operațiilor distructive

**Push connector plugin** (singura op distructivă a modulului):
- **Confirmare/dry-run:** declanșat din UI admin cu selecție de site-uri; nu există dry-run, dar există backup+rollback automat pe WP (`class-self-update-endpoint.php`).
- **Integritate:** `expected_hash` sha256 verificat pe WP înainte de instalare — protejează contra unui download_url manipulat.
- **Idempotență/locking:** `PushConnectorPlugin` are `tries=1` dar **nu** e `ShouldBeUnique` (`PushConnectorPlugin.php:25`). Două push-uri concurente către același site pot rula `Plugin_Upgrader` simultan, în timp ce backup-ul de rollback (`simplead-manager-connector-rollback`) e comun → risc de corupere a directorului plugin-ului pe WP (S-P2-4).
- **Audit logging (manager):** push-ul **nu** scrie în `ActivityLog` cine a declanșat pushul și pe ce site-uri; rezultatele stau doar în cache 1h (`PushConnectorPlugin.php:126-130`). Pe WP se logează (`SAM_Audit_Logger::log('self_update', ...)`). Lipsa audit-ului pe manager e o lacună de trasabilitate.
- Schimbarea/ștergerea credențialelor (`SiteOverview::saveCredentials`/`disconnectSite`) **nu** e auditată (deși `SiteOverview` folosește `ActivityLogger` în altă parte).

---

## Securitate — authz pe fiecare entry point

**Autentificare site↔manager.** Manager→site: HMAC-SHA256 cu API key + secret + nonce, chei criptate la repaus. Bine. Site→manager: API agent (HMAC pe `timestamp.body` cu `api_secret`) și backup callback (X-Backup-Token, modul 12). Cheile în WP sunt în `wp_options` (`sam_api_key`, `sam_api_secret`) în **clar** — normal pentru WP, dar înseamnă că un site compromis expune ambele chei.

**Blast radius al unui site compromis (întrebare cheie):**
- Cheile permit doar direcția manager→site (managerul semnează). Un atacator cu cheile unui site NU poate semna cereri către alt site (chei distincte per site) și NU poate citi/afecta alt site prin manager.
- Direcția site→manager: API-ul agent e scopat pe `Site` din rută; chiar dacă ar funcționa (nu funcționează — S-P1-2), un site compromis ar putea injecta doar rezultate/loguri pentru propriul site. **Nu există escaladare cross-site prin connector.** (pozitiv, de confirmat că nu apare un client de agent în viitor).
- Datele de sync (plugins/users) sunt randate escapat în Blade → fără XSS evident. Injecție de log posibilă dar redată escapat.

**Authz pe entry points UI (Livewire):**
- `SiteSettings` (`app/Livewire/Sites/Detail/SiteSettings.php:19-23`): `mount()` **NU** apelează `authorizeSiteAccess()` → IDOR (**S-P1-1**). Ruta `/sites/{site}/settings` e doar sub `auth,verified` (`routes/web.php:116`), nu `role:admin`.
- `ReportRecommendationsManager` (`ReportRecommendationsManager.php:31`): idem, fără authz (componentă copil, ne-rutată, dar cu metode publice `update/remove` — expunere Livewire).
- Restul componentelor `Sites/Detail/*` apelează `authorizeSiteAccess()` (verificat: toate mai puțin cele două de mai sus).
- Push connector: rută `role:admin` (`routes/web.php:192`) — OK.

**SSRF pe URL-uri de site introduse manual (S-P2-2):** `SiteWizardFormData` validează doar `url` (`Livewire/Forms/SiteWizardFormData.php`), fără blocare de IP-uri interne/loopback/metadata. `CreateSiteWizard::checkConnectivity()` (`CreateSiteWizard.php:73-87`) face curl cu `FOLLOWLOCATION`; `FetchSiteFavicon::fetchUrl()`, `SyncWordPressSite`, `RunSeoAudit` lovesc URL-ul. Creare de site cere `canManageSites` (admin/manager), deci SSRF-ul e de la un utilizator privilegiat intern — severitate moderată, dar `api_endpoint` din `saveCredentials` (`required|url`) permite redirecționarea managerului către o gazdă internă arbitrară.

**Mass assignment:** `Site::$fillable` include `api_key`/`api_secret` (`Site.php:130-132`) — populate doar din formulare admin controlate; nu am găsit un `Site::create/update(request()->all())` necontrolat.

**Secrete în loguri:** `SAM_Request_Logger::log()` stochează doar `substr(sha256(api_key),0,8)` (`class-request-logger.php:48`) — cheia NU e logată în clar. Bine.

---

## Igienă queue/job

| Job | tries | timeout | unique | backoff | failed() |
|---|---|---|---|---|---|
| `SyncWordPressSite` | 3 | 120 | ✅ `sync-wp-{id}` | `[30,60,120]` | ✅ circuit breaker |
| `PushConnectorPlugin` | 1 | 180 | ❌ (S-P2-4) | — | ❌ (doar try/catch intern) |
| `FetchPhpErrorLogs` | 1 | 60 | ✅ | — | neverificat |
| `FetchSiteFavicon` | 2 | 60 | ❌ | — | — |

Dacă o coadă Horizon e blocată: sync-ul (unic) se coalescează corect; push-ul cu `tries=1` eșuează definitiv fără retry și fără `failed()` — rezultatul rămâne „incomplet" în cache 1h, iar UI-ul poate arăta un push blocat la X/N (fără alertare).

---

## Error handling & observabilitate

- **Push connector:** eșecurile sunt prinse intern și scrise în cache (`PushConnectorPlugin.php:115-130`) + `Log::warning`. **Fără alertare** — un push eșuat pe un site nu generează notificare; rămâne vizibil doar dacă adminul deschide modalul de progres.
- **Sync:** `failed()` scrie în circuit breaker + JobTracker (bun, vizibil în UI).
- **OPcache flush post-push** (`PushConnectorPlugin.php:96-106`): ambele încercări sunt `catch(\Throwable){}` mute — corect ca best-effort, dar un flush eșuat poate lăsa versiunea veche în OPcache fără urmă.
- Fetch favicon / SEO: eșecuri înghițite silențios (`catch` fără re-raise) — cosmetic pentru favicon, dar SEO (vezi S-P2-5) eșuează 100% silențios.

---

## Teste

**Azi:** modulul nu are teste dedicate. Harta (`00-module-map.md:15`) confirmă „zero teste HTTP/Livewire/auth" în tot repo-ul; nu există `tests/*` care să exercite `WordPressHttpClient`, semnarea HMAC, `AuthenticateAgent`, `SiteSettings` sau plugin-ul WP.

**Set minim viabil (cele 3-7 teste care ar fi prins regresiile periculoase):**
1. **Authz IDOR:** un `user` non-owner primește 403 pe `GET /sites/{other}/settings` și pe `toggleModule` (ar fi prins S-P1-1).
2. **Agent auth round-trip:** un request agent semnat corect (cu `api_key` real al unui site din DB) trece de `AuthenticateAgent` (ar fi prins S-P1-2 — `where` pe coloană criptată).
3. **Semnare HMAC:** `buildAuthHeaders` produce o semnătură pe care `SAM_Authentication::validate` o acceptă (contract manager↔plugin), plus respingerea unui timestamp expirat.
4. **Anti-replay:** al doilea request cu același nonce → 401 `NONCE_REUSED`.
5. **SSRF guard:** crearea unui site cu `url=http://169.254.169.254` / `http://127.0.0.1` e respinsă (după fix S-P2-2).
6. **Scoping error-logs:** un non-admin nu vede logurile unui site neasignat (ar fi prins S-P1-3).
7. **Integritate push:** `/self-update` respinge un pachet cu hash greșit (`HASH_MISMATCH`).

---

## Model de date

- **Criptare:** `api_key`/`api_secret` cu cast `encrypted` (`Site.php:177-178`), coloane `text` (`pgsql-schema.sql:3789-3790`) — corect, chei ne-recuperabile fără `APP_KEY`.
- **Consecință criptare (S-P1-2):** coloana criptată **nu poate fi interogată** cu `where('api_key', ...)`; nu există `api_key_hash` indexat pentru lookup. `AuthenticateAgent.php:23` e stricat structural.
- **Indexuri hot-path:** `sites` are indexuri pe `client_id, health_score, is_up, status, user_id, sort_order` (`pgsql-schema.sql:7207-7259`) — acoperă filtrarea din `SitesList`. Fără index pe `api_key` (nici n-ar ajuta, e criptat).
- **N+1:** `SitesList` face eager-load complet + `withCount` (`SitesList.php:41-42`) — bine. `ErrorLogsOverview` face `with('site')` + `join` — bine.
- **Soft-delete/orfani:** `Site` folosește `SoftDeletes`; FK-urile copil sunt `ON DELETE CASCADE` (se declanșează doar la hard-delete), deci la soft-delete rămân `sitePlugins`/`siteUsers` etc. „orfane logic" (comportament standard Laravel, dar de conștientizat la raportări globale).

---

## Constatări

| ID | Sev | Fișiere:linii | Descriere | Scenariu de eșec | Schiță remediere |
|---|---|---|---|---|---|
| **S-P1-1** | P1 | `app/Livewire/Sites/Detail/SiteSettings.php:19-23,69-104`; `ReportRecommendationsManager.php:31`; `routes/web.php:116`; contrast `WithSiteAuthorization.php:12` | `mount()` nu apelează `authorizeSiteAccess()`/`authorizeSiteModification()`. Ruta e doar sub `auth,verified`. | Un `viewer` sau un membru care nu deține site-ul deschide `/sites/{ID_ALTUI_CLIENT}/settings` și apelează `toggleModule('backup')`/`toggleModule('security')` → dezactivează silențios backup-urile/scanările de securitate pe un site al altui client; niciun audit. | Adaugă `$this->authorizeSiteModification($site)` în `mount()`/acțiuni; sau `->middleware('can:update,site')` pe grupul `/sites/{site}`. |
| **S-P1-2** | P1 | `app/Http/Middleware/AuthenticateAgent.php:23`; `app/Models/Site.php:177-178` | `Site::where('api_key', $token)` pe coloană cu cast `encrypted` (ciphertext ne-determinist) — nu se potrivește niciodată. | Orice cerere agent legitimă primește 401 „Invalid site token." Dacă livrarea comenzilor de securitate se bazează pe acest canal, remedierile de securitate eșuează **silențios** (mai rău decât lipsa lor). Confirmat ca defect de cod; impactul depinde de existența unui client de agent (neverificat — plugin-ul actual nu conține unul). | Introdu o coloană `api_key_hash` (sha256) indexată și ne-criptată pentru lookup; caută pe ea, apoi validează HMAC cu `api_secret` decriptat. |
| **S-P1-3** | P1 | `app/Livewire/ErrorLogs/ErrorLogsOverview.php:37-39,55-72`; `routes/web.php:156` | Interogarea `PhpErrorLog` nu e scopată pe site-urile utilizatorului; ruta e sub grupul general `auth`, nu `role:admin`. `resolve($id)` face `findOrFail` global. | Un utilizator non-admin deschide `/error-logs` și vede logurile PHP (căi de fișiere, fragmente SQL, mesaje de eroare) ale **tuturor** site-urilor, inclusiv clienți neasignați; poate marca „resolved" orice log al oricui. | Scopează query-ul prin `whereHas('site', canAccessSite-scope)` pentru non-admini; verifică ownership în `resolve()`; sau mută ruta sub `role:admin`. |
| **S-P2-1** | P2 | `wordpress-plugin/.../includes/class-authentication.php:55-95,108-119` | Calea HMAC legacy fără nonce (`METHOD\|PATH\|TS\|BODY`) e încă acceptată; anti-replay-ul cu nonce se bazează pe `wp_cache_add` (ne-persistent pe multe host-uri) + fallback transient, cu o fereastră de cursă la cereri concurente. | Managerul semnează mereu cu nonce, deci calea legacy nu e exploatabilă prin downgrade (semnătura n-ar corespunde). Riscul rezidual: dacă vreun semnatar legacy există, un atacator care captează cererea (rupere TLS) o poate reluat în 300s; pe host-uri fără object-cache partajat, protecția anti-replay degradează la fereastra transient. | După ce toate site-urile rulează v2.0, respinge cererile fără `X-SAM-Nonce`; forțează stocarea nonce-urilor într-un store partajat (DB), nu doar object cache. |
| **S-P2-2** | P2 | `app/Livewire/Forms/SiteWizardFormData.php` (regula `url`); `CreateSiteWizard.php:73-87`; `SiteOverview.php:355-366` (`api_endpoint`); `FetchSiteFavicon.php:68-77`; `SyncWordPressSite.php` | URL-urile de site / `api_endpoint` sunt validate doar cu `url`, fără blocare de IP-uri interne/loopback/link-local/metadata. Multiple job-uri fac fetch pe ele cu `FOLLOWLOCATION`. | Un utilizator cu `canManageSites` (sau prin redirect de la un site adăugat) setează `url`/`api_endpoint` către `http://169.254.169.254/...` sau `http://127.0.0.1:...`; managerul face request-uri către servicii interne (metadata cloud, servicii Docker). | Validează + rezolvă DNS și respinge IP-uri private/loopback/link-local înainte de fetch; interzice redirect-uri către asemenea gazde. |
| **S-P2-3** | P2 | `app/Services/WordPress/Concerns/ManagesSiteInfo.php:105-111`; `wordpress-plugin/.../class-key-rotation-endpoint.php` | `rotateApiKeys()` și endpoint-ul `/rotate-keys` există dar nu au niciun apelant; nu există flux de rotire în UI/scheduler. Dacă ar fi apelat, endpoint-ul WP schimbă cheile și returnează noile valori — managerul trebuie să le persiste, altfel se blochează afară. | Nu există rotire periodică a credențialelor per site. Dacă cineva sârmuiește `rotateApiKeys()` fără a salva răspunsul în `Site`, managerul pierde accesul la site (401 la următoarea cerere). | Implementează un flux de rotire care persistă atomic `api_key`/`api_secret` returnate; adaugă rotire programată opțională. |
| **S-P2-4** | P2 | `app/Jobs/PushConnectorPlugin.php:21-33`; `wordpress-plugin/.../class-self-update-endpoint.php` | `PushConnectorPlugin` nu e `ShouldBeUnique`; două push-uri concurente către același site rulează `Plugin_Upgrader` simultan peste directorul de rollback comun. | Un admin apasă „push to all" de două ori (sau push individual + all suprapus) → două job-uri lovesc `/self-update` pe același site; race pe `simplead-manager-connector-rollback` → posibilă corupere a directorului plugin-ului / rollback stricat pe site live. | Fă job-ul `ShouldBeUnique` pe `push-{site_id}`; pe WP, ia un lock (`wp_cache_add`/transient) la începutul `self_update`. |
| **S-P2-5** | P2 | `app/Livewire/Sites/Detail/SiteSeoAudit.php:509,619,656,691,730,760`; `app/Jobs/RunSeoAudit.php:46`; contrast `wordpress-plugin/.../class-seo-endpoint.php:10` | Apelurile SEO folosesc antetul greșit `X-SAM-API-Key` (corect: `X-SAM-Key`) și **nu** trimit semnătură HMAC/timestamp/nonce, dar endpoint-ul WP `/seo/*` folosește `check_permission` (HMAC complet). | Fiecare „bulk fix SEO" și `RunSeoAudit` primește 401 `MISSING_AUTH_HEADERS`; UI-ul numără totul ca „failed" fără a explica de ce. Feature-ul SEO analysis/bulk-fix e efectiv ne-funcțional (nota: audit de fond al SEO ține de modulul 17). | Rutează toate apelurile SEO prin `WordPressApiService`/`WordPressHttpClient` (semnare HMAC corectă) în loc de `Http::withHeaders`. |
| **S-P3-1** | P3 | `app/Http/Controllers/ConnectorPluginDownloadController.php:40`; `config/connector.php` | `config('connector.current_version')` nu există (config are doar `changelog`) → ETag mereu `connector-0.0.0`. | ETag static: un client care trimite `If-None-Match` ar putea primi 304 și servi un ZIP vechi din cache; changelog-ul din config e stagnat la 2.9.15 vs plugin 2.14.0. | Adaugă cheia `current_version` în `config/connector.php` sincronizată cu `SAM_VERSION`; folosește-o în ETag. |
| **S-P3-2** | P3 | `app/Console/Commands/BulkAddSites.php:19-53` | Listă hardcodată de nume de clienți + domenii reale într-o comandă comisă în repo. | Datorie tehnică + PII de clienți în cod versionat; comanda re-rulată poate crea clienți duplicat pe nume ușor diferit. | Mută în seeder/CSV extern ne-versionat; sau șterge comanda one-off. |

---

## Oportunități de îmbunătățire

**(a) Îmbunătățiri la feature-uri existente:**
1. **Audit trail pe manager pentru operații pe site** — logează în `ActivityLog` cine a declanșat push-ul connector, schimbarea credențialelor, disconnect și toggle de module (cu `site_id` + actor). Azi push-ul lasă doar cache 1h; trasabilitatea „cine-a-făcut-ce-pe-ce-site" lipsește exact pe acțiunile riscante.
2. **Alertare pe push eșuat** — leagă `PushConnectorPlugin` de sistemul de notificări (modul 20) astfel încât un push eșuat pe un site live să genereze un incident, nu doar o linie de log.
3. **Health-check de conector în listă** — arată versiunea connector-ului vs. cea curentă și „ultima autentificare reușită" per site în `SitesList`, ca site-urile cu chei desincronizate/plugin vechi să sară în ochi (benchmark: MainWP „Sync status").
4. **SSRF guard reutilizabil** — un `UrlSafetyValidator` central folosit de wizard, favicon, sync, uptime; elimină clase întregi de risc și devine test-abil.
5. **Rotire de chei end-to-end** — buton „Rotate credentials" care apelează `/rotate-keys`, persistă atomic răspunsul și re-sincronizează; plus rotire programată opțională (benchmark: WPMU DEV/ManageWP re-key).

**(b) Feature-uri noi (ancorate în cod existent):**
1. **Provisioning automat al conectorului (S)** — la crearea site-ului, generează chei în manager și oferă un one-liner/mu-plugin de bootstrap care le injectează în `wp_options`, eliminând copy-paste-ul manual din `SiteOverview::saveCredentials`. Codul de download signed + self-update există deja. *Rațiune: reduce fricțiunea de onboarding și erorile de tastare a cheilor.*
2. **„Connector drift & auto-heal" (M)** — job programat care detectează site-urile cu versiune veche/chei invalide (după fix S-P1-2) și re-push-ează connectorul sau alertează. *Rațiune: flota de plugin-uri rămâne pe versiuni divergente fără vizibilitate azi.*
3. **Kill-switch per site (S)** — un flag `is_paused` care oprește tot traficul connector către un site (util când un site e compromis/în mentenanță), verificat în `WordPressHttpClient`. *Rațiune: containment rapid al blast radius-ului fără a șterge credențialele.*

---

## Note de verificare

- **Neverificat:** dacă există un client de agent (WP-side) care lovește `/agent/{site_token}/security/*` în producție — plugin-ul din acest repo nu conține unul (grep 0); impactul real al S-P1-2 depinde de acest lucru.
- **Neverificat:** `ManageSites` command și `Livewire/Sites/BulkSettings`/`Detail/Components` în profunzime (nu au ridicat semnale la scanare, dar nu au fost citite integral).
- **Confirmat prin citirea codului:** toate citările `path:line` din tabelul de constatări.
