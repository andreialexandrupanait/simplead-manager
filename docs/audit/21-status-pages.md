# 21 — Status Pages publice

**Data:** 2026-07-02 · **Auditor:** Claude (audit modul, Faza 1) · **Scope:** `app/Services/StatusPageService.php`, `app/Jobs/CreateStatusPageIncident.php`, `app/Jobs/ResolveStatusPageIncident.php`, `app/Listeners/CreateStatusPageIncidentOnDown.php`, `app/Listeners/ResolveStatusPageIncidentOnRecovery.php`, `app/Livewire/StatusPages/*`, `app/Http/Controllers/StatusPageController.php`, `app/Policies/StatusPagePolicy.php`, modele `StatusPage`, `StatusPageSite`, `StatusPageIncident`, `StatusPageIncidentUpdate`, `StatusPageIncidentTemplate`, view-uri `resources/views/status-page/*` + `resources/views/livewire/status-pages/*`, rute `routes/web.php:222-224, 256-259`.

---

## Rezumat executiv

- **Cea mai gravă problemă (SP-P1-1):** ștergerea unui utilizator din Settings → Users șterge în cascadă (FK `ON DELETE CASCADE`, `database/schema/pgsql-schema.sql:8362`) toate paginile de status create de acel utilizator, împreună cu tot istoricul de incidente — URL-uri publice date clienților dispar silențios cu 404.
- Endpoint-ul de **badge SVG ocolește protecția cu parolă** (`StatusPageController.php:88` verifică doar `is_public`) — starea agregată a unei pagini protejate e publică oricui știe slug-ul.
- Răspunsul JSON al API-ului public trimite `Cache-Control: public, max-age=60` **inclusiv pe paginile protejate cu parolă** (`StatusPageController.php:61`) și serializează modelele Eloquent complete (ID-uri interne `site_id`, `status_page_id`, flag `auto_created`).
- **Incidente-fantomă posibile:** job-urile create/resolve au `uniqueId` diferite, rulează pe coada `default` partajată cu rapoarte/scanări de securitate (`config/horizon.php:259`); dacă `CreateStatusPageIncident` e procesat după `ResolveStatusPageIncident` (flap + backlog), incidentul auto rămâne deschis pe pagina publică pe termen nelimitat — nu există niciun mecanism de curățare a incidentelor stale.
- Două feature-uri pe jumătate: **custom domain** (câmp salvat + instrucțiuni CNAME în UI, dar zero rutare pe Host și `server_name manager.simplead.ro` fix în nginx) și **scheduled maintenance** (secțiune pe pagina publică, dar niciun cod nu scrie `is_scheduled=true`).
- Acțiunile Livewire de administrare **nu apelează niciodată policy-ul** la delete/create/incident — se bazează exclusiv pe middleware-ul de rută `role:admin`, care nu e re-aplicat de Livewire pe request-urile ulterioare (nu există `addPersistentMiddleware`).
- **Zero teste** pentru modul; nu există factory-uri deși modelele folosesc `HasFactory`.
- Puncte bune verificate: rate limiting real pe toate rutele publice, cache de 60s pe datele publice, XSS-ul e absent (tot output-ul public trece prin `{{ }}`), `noindex` pe layout, brute-force pe parolă limitat la 5/min per slug+IP cu logging.

---

## Inventar & corectitudine

### Ce face modulul

Pagini de status publice (gen statuspage.io) per client:

| Componentă | Fișier | Rol |
|---|---|---|
| Controller public | `app/Http/Controllers/StatusPageController.php` | 4 endpoint-uri fără auth: `__invoke` (HTML), `api` (JSON), `authenticate` (POST parolă), `badge` (SVG) |
| Serviciu | `app/Services/StatusPageService.php` | `getPublicData()` (cache 60s), `createAutoIncident()`, `resolveAutoIncident()`, `computeSla()`, `verifyPassword()` |
| Job-uri | `app/Jobs/CreateStatusPageIncident.php`, `ResolveStatusPageIncident.php` | creare/rezolvare incidente auto, declanșate de evenimente uptime |
| Listeneri | `app/Listeners/CreateStatusPageIncidentOnDown.php:14`, `ResolveStatusPageIncidentOnRecovery.php:14` | leagă `SiteWentDown`/`SiteRecovered` (emise din `app/Jobs/CheckUptime.php:286,310`) de job-uri; descoperiți automat (Laravel 11 event discovery — nu există `EventServiceProvider`, verificat `app/Providers/`) |
| Admin UI | `app/Livewire/StatusPages/StatusPagesList.php`, `StatusPageEdit.php` | CRUD pagini, atașare site-uri, incidente manuale + update-uri, template-uri, ordonare, parolă |
| Modele | `StatusPage`, `StatusPageSite`, `StatusPageIncident`, `StatusPageIncidentUpdate`, `StatusPageIncidentTemplate` | 5 tabele, FK-uri cu cascade (vezi Model de date) |
| Rute | `routes/web.php:222-224` (admin, în grupul `role:admin` deschis la linia 192), `routes/web.php:256-259` (publice, throttled) |

### Feature-uri pe jumătate / cod mort

1. **Custom domain — promisiune nefuncțională.** `custom_domain` e salvat (`StatusPageEdit.php:157`), iar UI-ul instruiește explicit clientul să adauge CNAME către host-ul aplicației (`resources/views/livewire/status-pages/status-page-edit.blade.php:73-81`). Dar: nu există niciun middleware/rută care să rezolve o cerere după header-ul `Host` către o pagină de status (grep pe `custom_domain` în `app/`, `routes/`, `bootstrap/` — singura utilizare e salvarea și afișarea în form), iar nginx are `server_name manager.simplead.ro` (`docker/nginx/conf.d.ssl/app.conf:4`). O cerere pe `status.clientdomain.com` nu va funcționa niciodată (nici certificat TLS). Câmpul nu are nici validare în `save()` (`StatusPageEdit.php:133-140` — `customDomain` lipsește din reguli).
2. **Scheduled maintenance — doar afișare.** Pagina publică are secțiune dedicată (`resources/views/status-page/show.blade.php:128-150`, query în `StatusPageService.php:98-102` pe `is_scheduled=true`), dar niciun cod din aplicație nu scrie `is_scheduled`/`scheduled_start_at` (grep pe tot `app/` — zero writers; `createIncident()` din `StatusPageEdit.php:218-225` nu le setează). Secțiunea nu poate avea conținut decât prin SQL manual.
3. **Incident templates — fără CRUD.** `StatusPageEdit::applyIncidentTemplate()` (`StatusPageEdit.php:197-203`) și `incidentTemplates()` (`:371-375`) citesc tabela, dar nu există nicio interfață/seeder/comandă care să creeze template-uri (grep `StatusPageIncidentTemplate` — doar model + cele două citiri; nimic în `database/seeders/`). Dropdown-ul e permanent gol pe o instalare curată.
4. **Policy parțial mort.** `StatusPagePolicy::view/update` au logică de ownership (`user_id === $user->id`, `StatusPagePolicy.php:19,33`), dar rutele sunt exclusiv `role:admin` (`routes/web.php:192`), deci ramura non-admin-owner e inaccesibilă; `delete()` (`:36-39`) nu e apelat nicăieri (vezi SP-P2-3).

TODO-uri: niciun `TODO`/`FIXME` în fișierele modulului (verificat prin citire integrală).

---

## Siguranța operațiilor distructive

Modulul nu execută operații pe site-urile WordPress ale clienților (corect marcat „N" în harta modulelor). Operațiile „distructive" interne:

- **Ștergerea unei pagini de status** (`StatusPagesList.php:36-46`): are modal de confirmare în UI, dar (a) nicio verificare de policy în cod (vezi Securitate) și (b) cascade FK șterge toate incidentele + update-urile (`pgsql-schema.sql:8330,8314`). Fără soft-delete, fără export. O ștergere accidentală = istoric SLA/incident pierdut ireversibil + 404 pe URL-ul dat clientului.
- **Ștergerea unui utilizator** șterge paginile lui de status prin FK cascade — vezi SP-P1-1. `UserManagement::deleteUser()` (`app/Livewire/Settings/UserManagement.php:104`) nu avertizează despre resursele deținute.
- **Audit logging: inexistent.** Nicio urmă cine a creat/rezolvat/șters un incident sau o pagină (grep `ActivityLogger|activity(` în toate fișierele modulului — zero rezultate). Incidentele manuale nu stochează autorul (`status_page_incidents` nu are coloană `user_id`/`created_by`, `pgsql-schema.sql:3906-3922`). Pentru un canal public către clienți, „cine a publicat acest mesaj" e neauditabil.
- **Idempotență auto-incidente:** OK la nivel de serviciu — `createAutoIncident()` verifică incident activ existent înainte de creare (`StatusPageService.php:19-27`), iar job-urile sunt `ShouldBeUnique` per site. Problema de ordonare create/resolve rămâne (vezi Igienă queue).

---

## Securitate

### Authz pe entry points

| Entry point | Protecție | Verdict |
|---|---|---|
| `GET /settings/status-pages*` (`routes/web.php:222-224`) | `auth`+`verified`+`role:admin` (`:72`, `:192`) | OK la nivel de rută |
| `StatusPagesList::deleteStatusPage()` (`StatusPagesList.php:36-46`) | **niciun** `authorize('delete')` — `StatusPage::find($id)?->delete()` direct pe orice ID | SP-P2-3 |
| `StatusPageEdit::mount()` (`StatusPageEdit.php:76`) | `authorize('update')` doar pe edit; ramura create nu apelează `authorize('create')` | SP-P2-3 |
| `StatusPageEdit::save/createIncident/updateIncidentStatus/resolveIncident/addIncidentUpdate/removePassword/moveSite*` | niciun check de policy în metode | SP-P2-3 |
| `GET /status/{slug}` (`StatusPageController.php:14-38`) | `is_public` + parolă opțională (sesiune) + `throttle:status-page` 30/min/IP (`AppServiceProvider.php:68-70`) | OK |
| `GET /api/status/{slug}` (`:40-62`) | idem + verifică parola (`:49-54`) | OK, dar vezi SP-P2-2/SP-P2-6 |
| `POST /status/{slug}/auth` (`:64-84`) | `throttle:status-page-auth` 5/min per slug+IP (`AppServiceProvider.php:84-88`), log pe eșec (`:78-81`) | OK |
| `GET /status/{slug}/badge.svg` (`:86-114`) | doar `is_public` — **nu verifică parola** | SP-P2-1 |

Notă pe SP-P2-3: exploatarea directă e improbabilă (Livewire cere un snapshot semnat obținut de pe pagina gated), dar `role:admin` **nu** e middleware persistent Livewire (grep `addPersistentMiddleware` — zero rezultate), deci singura apărare pe acțiunile ulterioare e integritatea snapshot-ului, nu o verificare de rol. Policy-ul există și e ignorat — defense-in-depth lipsă.

### Enumerare / expunere publică

- **Poate un vizitator afla lista clienților?** Parțial. O pagină expune doar site-urile atașate ei (`is_visible=true`, `StatusPageService.php:74-77`) și doar `name/status/uptime/response_time` (`:79-84`) — fără URL-uri. Dar `is_public` are **default `true`** (`pgsql-schema.sql:3993`, `StatusPageEdit.php:36`), iar slug-ul e generat din titlu (`StatusPageEdit.php:97-103`) — tipic numele clientului. Un competitor poate confirma prin ghicire de slug-uri (`/status/nume-client`) ce firme sunt clienți ai agenției și starea lor de uptime. Throttle 30/min/IP încetinește, nu previne. (SP-P2-9)
- **API JSON** serializează modelele complete: `active_incidents`, `recent_incidents`, `scheduled_maintenance` merg în răspuns cu toate coloanele — `site_id` (ID intern), `status_page_id`, `auto_created`, `is_scheduled` (`StatusPageService.php:120-122` + `StatusPageController.php:58-61`). (SP-P2-6)
- **Titlul incidentelor auto folosește numele intern al site-ului** — `"{$site->name} is experiencing issues"` (`StatusPageService.php:32`) și mesajele de update (`:42`, `:65`) — ocolind alias-ul `display_name` din `StatusPageSite` (`StatusPageSite.php:54-57`) creat exact ca să mascheze numele real. (SP-P2-5)
- **Badge** dezvăluie existența slug-ului + starea agregată pentru pagini protejate cu parolă. (SP-P2-1)
- `POST /status/{slug}/auth` nu verifică `is_public` (`StatusPageController.php:66` — doar `firstOrFail` pe slug), deci răspunsul diferă între slug inexistent (404) și pagină privată (redirect/eroare de parolă) — oracle de existență. (SP-P3-6)

### XSS pe conținutul public

Verificat integral `resources/views/status-page/show.blade.php` și `password.blade.php`: tot conținutul dinamic (titluri, descrieri, mesaje de update, nume site-uri) e afișat prin `{{ }}` (escaped). `severity_color`/`status_label` vin din `match` cu valori fixe (`StatusPageIncident.php:90-108`). Badge-ul SVG interpolează doar `$label`/`$color` din `match` cu valori hardcodate (`StatusPageController.php:91-96`). **Nu am găsit XSS.** Excepții minore:

- `primary_color` e injectat într-un bloc `<style>` (`resources/views/components/layouts/status-page.blade.php:10`); Blade nu escapează `{`/`}`, deci un admin poate injecta CSS arbitrar — limitat la `max:20` caractere (`StatusPageEdit.php:138`). Doar admin-controlled → SP-P3-2.
- `logo_url` acceptă orice string ≤500 (`StatusPageEdit.php:137`) și e pus în `src` (`show.blade.php:6`); `javascript:` în `img src` e inert în browsere moderne, dar poate referenția un tracker extern; plus depășește coloana DB de 255 (SP-P3-1).

### Mass assignment / injecții / secrete

- `$fillable` pe toate modelele e explicit; `password_hash` e fillable pe `StatusPage` (`StatusPage.php:63`) dar toate scrierile trec prin array-uri construite manual în componentă — acceptabil.
- Nicio interpolare SQL brută; totul Eloquent.
- Parola e stocată `Hash::make` (`StatusPageEdit.php:161`), comparată cu `Hash::check` (`StatusPageService.php:180`) — corect. Parola în clar nu e logată (log-ul de eșec conține doar slug+IP, `StatusPageController.php:78-81`).
- SSRF: nu există niciun fetch server-side de URL în modul (logo-ul e doar redat client-side) — nu se aplică.

---

## Igienă queue/job

- Ambele job-uri: `tries=2`, `timeout=30`, `backoff=[15,30]`, `ShouldBeUnique` (`CreateStatusPageIncident.php:21-25,32-35`; `ResolveStatusPageIncident.php:21-25,31-34`). Rezonabil pentru operații DB-only.
- **Coadă:** niciun `onQueue()` → coada `default`, servită de supervisorul partajat cu `security`, `performance`, `reports` (`config/horizon.php:259`). Un backlog de rapoarte lunare sau scanări de securitate întârzie publicarea incidentelor — exact momentele cu activitate mare. Dacă acea coadă e blocată, pagina de status **rămâne totuși corectă la nivel de disponibilitate** (bulinele și bannerul vin live din `site->is_up`, `StatusPageSite.php:59-78`, `StatusPage.php:102-124`), doar incidentele textuale lipsesc/întârzie — un design bun, care limitează impactul.
- **Cursă create/resolve (incidente-fantomă):** `uniqueId` diferă între job-uri (`status-incident-{id}` vs `status-resolve-{id}`), deci nu se serializează între ele. Scenariu: site down la prag → `CreateStatusPageIncident` dispatched; coada are backlog; site-ul își revine → `ResolveStatusPageIncident` dispatched; dacă resolve rulează primul (retry pe create după eșec tranzitoriu, sau workeri paraleli), resolve nu găsește nimic (no-op), apoi create inserează un incident „investigating" pentru un site deja funcțional. Nimic nu-l mai închide până la următorul ciclu down→up sau intervenție manuală. Nu există comandă de reconciliere/cleanup incidente stale (grep în `app/Console/Commands` — zero). (SP-P2-4)
- **`failed()` absent** pe ambele job-uri — după 2 eșecuri, incidentul pur și simplu nu apare, fără nicio alertă (vezi Observabilitate).
- `SerializesModels` cu `public Site $site` — dacă site-ul e șters între dispatch și rulare, job-ul aruncă `ModelNotFoundException` și moare silențios; acceptabil.
- Evenimentul `SiteWentDown` e emis o singură dată, la egalitate strictă `consecutive_failures === alert_after_failures` (`app/Jobs/CheckUptime.php:283`) — dacă job-ul de creare pică de 2 ori, outage-ul respectiv nu va mai avea niciodată incident auto (evenimentul nu se re-emite). (parte din SP-P2-4/SP-P2-12, suprapunere cu modulul 15)

---

## Error handling & observabilitate

- **Eșecurile sunt silențioase.** Job-urile nu au `failed()`, nu notifică, nu loghează nimic la succes sau eșec; singura vizibilitate e tab-ul „Failed" din Horizon. O pagină de status care nu publică incidente e o formă de dezinformare a clientului — mai rea decât lipsa paginii.
- Serviciul nu loghează crearea/rezolvarea incidentelor auto (`StatusPageService.php:16-68` — zero `Log::`).
- Nu există metrici/health-check pe modul (câte incidente auto deschise > X ore etc.).
- Singurul logging din modul: eșec de parolă (`StatusPageController.php:78-81`) — bun.
- Cache-ul de 60s (`StatusPageService.php:72`) nu e invalidat la crearea unui incident manual — un operator care publică un incident urgent îl vede pe pagina publică abia după ≤60s și poate crede că nu a funcționat (fricțiune, nu defect).

---

## Teste

**Există azi: zero.** `grep -rli "statuspage|status_page" tests/` — niciun rezultat. Nu există nici factory-uri (`database/factories/` — nimic pentru StatusPage*), deși toate modelele declară `HasFactory`, deci primul test scris va cere și factory-uri.

**Set minim viabil (6 teste):**
1. **Feature:** `GET /status/{slug}` cu `is_public=false` → 404; cu parolă setată și fără sesiune → view-ul de parolă, nu datele (regresie pe gating-ul public).
2. **Feature:** `GET /status/{slug}/badge.svg` pe pagină protejată cu parolă → nu expune starea (azi pică — documentează SP-P2-1).
3. **Feature:** `POST /status/{slug}/auth` cu parolă greșită de 6 ori → 429 (rate limiter-ul chiar se aplică).
4. **Unit:** `StatusPageService::createAutoIncident()` apelat de două ori pentru același site → un singur incident activ (idempotență); `resolveAutoIncident()` închide toate incidentele auto active și adaugă update „resolved".
5. **Feature (Livewire):** utilizator non-admin nu poate accesa `settings.status-pages.*`; `deleteStatusPage()` respectă policy-ul `delete` (azi pică — documentează SP-P2-3).
6. **Feature:** flux uptime → status page: emiterea `SiteWentDown` creează incident doar pe paginile cu `auto_incidents=true` care conțin site-ul; `SiteRecovered` îl rezolvă (protejează sârma listener→job→serviciu, care azi e ținută doar de event discovery implicit).

---

## Model de date

- 5 tabele; FK-uri: `status_pages.user_id → users ON DELETE CASCADE` (`pgsql-schema.sql:8362` — **sursa SP-P1-1**), `status_pages.client_id → clients SET NULL` (`:8354`), `status_page_sites` cascade pe ambele FK + unique `(status_page_id, site_id)` (`:6214`), `status_page_incidents.site_id → sites SET NULL` (`:8322`), update-uri cascade pe incident (`:8314`).
- **Indexuri pe query-urile fierbinți:** `status_page_incidents (status_page_id, status, started_at)` (`:7266`) acoperă `activeIncidents`/`recent` — OK. `status_pages.slug` unique (`:6230`) acoperă lookup-ul public — OK. Lipsă: index pe `status_page_incidents (site_id, auto_created, status)` folosit de `createAutoIncident`/`resolveAutoIncident` și de `ResolveStatusPageIncident::handle()` (`ResolveStatusPageIncident.php:38-42`) — volum mic, impact neglijabil azi (P3).
- **N+1:** `getPublicData()` face eager loading corect (`with('site.uptimeMonitor')`, `with('updates')`, `StatusPageService.php:76,87,94`). `overall_status` (accessor, `StatusPage.php:106`) re-interoghează aceleași `statusPageSites` deja încărcate în serviciu — query duplicat, dar în interiorul cache-ului de 60s. **Badge-ul** însă calculează `overall_status` la fiecare hit, fără cache, cu `Cache-Control: no-cache` (`StatusPageController.php:89,112`) — 2+ query-uri per vizită, per IP 30/min (P3).
- **Orfane:** incidentele auto cu `site_id` devenit NULL (site șters) nu mai pot fi rezolvate automat (`resolveAutoIncident` filtrează pe `site_id`, `StatusPageService.php:50-54`) — rămân deschise pe pagină până la rezolvare manuală (P3).
- **Consistență soft-delete:** modelele nu folosesc SoftDeletes; `Site` — de asemenea nu (cascade hard). Consecvent, dar fără plasă de siguranță la ștergeri.
- `sort_order`: sincronizarea din `save()` folosește cheile păstrate de `array_diff` (`StatusPageEdit.php:180-185`) → valori `sort_order` ne-secvențiale/potențial duplicate; swap-ul din `moveSiteUp/Down` devine no-op pe duplicate (P3).
- `logo_url` varchar(255) în DB (`pgsql-schema.sql:3990`) vs validare `max:500` (`StatusPageEdit.php:137`) → excepție SQL la salvare pentru 256-500 caractere (P3).

---

## Constatări

| ID | Sev | Fișiere:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| SP-P1-1 | P1 | `pgsql-schema.sql:8362`, `app/Livewire/Settings/UserManagement.php:104`, `app/Models/StatusPage.php:46-48` | `status_pages.user_id` are `ON DELETE CASCADE`; ștergerea unui user șterge paginile lui de status + tot istoricul de incidente | Un coleg pleacă din agenție; adminul îi șterge contul din Settings → Users; paginile de status date clienților (URL-uri publice, eventual embed-uri de badge) dispar instant cu 404, fără avertisment și fără recuperare | Migrare FK la `SET NULL` sau reasignare obligatorie la ștergerea userului; avertisment în `deleteUser()` despre resursele deținute |
| SP-P2-1 | P2 | `app/Http/Controllers/StatusPageController.php:86-96` | `badge()` verifică doar `is_public`, nu și parola — starea agregată a paginilor protejate e publică | Agenția protejează cu parolă pagina unui client sensibil; oricine ghicește slug-ul citește `/status/{slug}/badge.svg` și monitorizează când clientul are outage | În `badge()`, dacă `password_hash` e setat și sesiunea nu e autentificată, întoarce badge neutru („Status: protected") sau 404 |
| SP-P2-2 | P2 | `app/Http/Controllers/StatusPageController.php:61` | `Cache-Control: public, max-age=60` pe răspunsul JSON inclusiv când pagina e protejată cu parolă | Un proxy/CDN intermediar cache-uiește răspunsul autorizat și îl servește unui vizitator neautentificat în fereastra de 60s | `private, max-age=60` când `password_hash` e setat (sau `Vary: Cookie`) |
| SP-P2-3 | P2 | `app/Livewire/StatusPages/StatusPagesList.php:36-46`, `StatusPageEdit.php:131,205,237,252,273,363` | Nicio verificare de policy pe delete/create/incident/removePassword; singura apărare e `role:admin` pe rută (`routes/web.php:192`), pe care Livewire nu o re-aplică pe request-urile ulterioare (fără `addPersistentMiddleware`) | Orice regresie de rutare (mutarea rutelor în grupul non-admin de la `routes/web.php:187`, cum s-a întâmplat cu `settings.profile`) face instant toate acțiunile — inclusiv ștergerea — disponibile oricărui user autentificat | `$this->authorize('delete', ...)` / `authorize('create', StatusPage::class)` în fiecare acțiune; `Livewire::addPersistentMiddleware(['role:admin'])` sau echivalent |
| SP-P2-4 | P2 | `app/Jobs/CreateStatusPageIncident.php:32-35`, `ResolveStatusPageIncident.php:31-34`, `config/horizon.php:259`, `app/Jobs/CheckUptime.php:283` | Incidente-fantomă: create/resolve au `uniqueId` diferite pe coadă partajată; resolve poate rula înaintea create-ului întârziat; `SiteWentDown` se emite o singură dată (egalitate strictă), deci un create eșuat de 2 ori = niciun incident; nu există cleanup pentru incidente auto stale | Site flap în timp ce coada `default` macină rapoarte lunare: resolve rulează primul (no-op), create inserează apoi un incident „investigating" pentru un site sănătos — pagina publică arată simultan „All Systems Operational" și un incident activ, pe termen nelimitat | În `handle()`-ul create, re-verifică `$site->fresh()->is_up` înainte de insert; comandă programată de auto-rezolvare a incidentelor `auto_created` mai vechi de N ore cu site-ul up; coadă dedicată sau `uniqueId` comun |
| SP-P2-5 | P2 | `app/Services/StatusPageService.php:32,42,65` vs `app/Models/StatusPageSite.php:54-57` | Incidentele auto folosesc `$site->name` (numele intern), ocolind alias-ul public `display_name` | Agenția setează `display_name` = „API" ca să mascheze numele real „client-x-staging.example.com"; la primul outage, titlul public al incidentului afișează numele intern | În `createAutoIncident`, rezolvă numele prin `StatusPageSite` al paginii respective (`display_name ?? site->name`) |
| SP-P2-6 | P2 | `app/Services/StatusPageService.php:120-122`, `StatusPageController.php:58-61` | API-ul JSON public serializează modelele Eloquent complete: `site_id`, `status_page_id`, `auto_created`, `is_scheduled` etc. | Un vizitator citește `/api/status/{slug}` și obține ID-urile interne ale site-urilor și care incidente sunt auto vs redactate manual — informație internă, utilă la corelarea cu alte endpoint-uri | Mapează explicit incidentele la câmpurile publice (title, status, severity, started_at, updates.message) ca la `sites` |
| SP-P2-7 | P2 | `StatusPageEdit.php:157`, `resources/views/livewire/status-pages/status-page-edit.blade.php:73-81`, `docker/nginx/conf.d.ssl/app.conf:4` | Custom domain: UI-ul dă instrucțiuni CNAME, dar nu există rutare pe Host, server block nginx sau TLS pentru domenii custom — feature-ul nu poate funcționa | Operatorul urmează instrucțiunile din propriul UI, configurează CNAME la client, dă clientului `status.client.ro` — care servește eroare de certificat / pagina default | Ori se implementează rezolvarea pe Host + TLS (efort real), ori se scoate câmpul și instrucțiunile din UI |
| SP-P2-8 | P2 | `resources/views/status-page/show.blade.php:128-150`, `StatusPageService.php:98-102`, `StatusPageEdit.php:205-235` | Scheduled maintenance e doar afișare: niciun cod nu scrie `is_scheduled`/`scheduled_start_at` | Operatorul vrea să anunțe o mentenanță programată — nu există nicio cale din UI; secțiunea publică e permanent goală | Adaugă în form-ul de incident opțiunea „scheduled maintenance" cu interval |
| SP-P2-9 | P2 | `pgsql-schema.sql:3993`, `StatusPageEdit.php:36,97-103`, `AppServiceProvider.php:68-70` | `is_public` default `true` + slug generat din titlu (tipic numele clientului) → lista de clienți a agenției e confirmabilă prin ghicire de slug-uri | Un competitor rulează un dicționar de nume de firme pe `/status/{slug}` (30 req/min/IP, distribuit) și confirmă portofoliul de clienți + istoricul lor de downtime | Slug cu sufix aleator la generare (ex. `client-x7k2`) sau `is_public` default `false` |
| SP-P2-10 | P2 | `tests/` | Zero teste pentru întregul modul public; fără factory-uri | Orice regresie pe gating (`is_public`, parolă) sau pe fluxul auto-incident ajunge în producție nedetectată, pe singura suprafață publică fără token a aplicației | Setul minim de 6 teste din secțiunea Teste |
| SP-P2-11 | P2 | tot modulul (grep `ActivityLogger` — zero), `pgsql-schema.sql:3906-3922` | Niciun audit logging; incidentele manuale nu stochează autorul | Un client contestă un mesaj public de incident; nimeni nu poate spune cine l-a publicat sau șters | Coloană `created_by` pe incidente/update-uri + `ActivityLogger` pe acțiunile de administrare |
| SP-P3-1 | P3 | `StatusPageEdit.php:137` vs `pgsql-schema.sql:3990` | `logoUrl` validat `max:500`, coloană varchar(255) → excepție SQL între 256-500; lipsă validare `url` | Admin lipește un data-URI sau URL lung → eroare 500 la salvare | `url|max:255` |
| SP-P3-2 | P3 | `StatusPageEdit.php:138`, `resources/views/components/layouts/status-page.blade.php:10` | `primary_color` injectat în `<style>`; Blade nu escapează `{}` → injecție CSS (max 20 caractere, doar admin) | Admin rău-intenționat injectează `red}body{display:none` pe pagina publică | Validare regex `#[0-9a-fA-F]{3,8}` |
| SP-P3-3 | P3 | `StatusPageEdit.php:237-250` | `updateIncidentStatus(int, string $status)` nu validează statusul contra allowlist-ului | Apel Livewire cu status arbitrar → valoare nevalidă afișată public prin `ucfirst()` (`StatusPageIncident.php:106`) | Validare `in:investigating,identified,monitoring,resolved` |
| SP-P3-4 | P3 | `app/Policies/StatusPagePolicy.php:17-39`, `routes/web.php:192` | Policy cu logică de ownership pentru non-admini, dar rute exclusiv admin — logică moartă/contradictorie; `delete()` nu e apelat nicăieri | Un viitor dezvoltator relaxează rutele bazându-se pe policy și obține un model de permisiuni diferit de cel intenționat | Aliniere: fie rute per policy, fie policy admin-only |
| SP-P3-5 | P3 | `StatusPageEdit.php:172-185` | `sort_order` calculat cu cheile păstrate de `array_diff` → valori ne-secvențiale/duplicate; swap-ul up/down e no-op pe duplicate | Adaugă/șterge site-uri în aceeași sesiune → ordonarea din UI nu mai răspunde | Renumerotare secvențială la fiecare sync |
| SP-P3-6 | P3 | `StatusPageController.php:64-84`, `:23-30` | `authenticate()` nu verifică `is_public` (oracle de existență a slug-urilor private); sesiunile de parolă (`status-page-auth.{id}`) supraviețuiesc schimbării parolei | Vizitator distinge slug privat de inexistent; un client căruia i s-a revocat parola păstrează accesul cât ține sesiunea | 404 pe non-public în `authenticate()`; include un hash al parolei în cheia de sesiune |
| SP-P3-7 | P3 | `StatusPageController.php:88-89,112`, `StatusPage.php:102-124` | Badge-ul recalculează `overall_status` (2+ query-uri) la fiecare hit, cu `Cache-Control: no-cache` | Badge embed-uit pe un site cu trafic → query-uri repetate; throttle-ul limitează la 30/min/IP, dar IP-uri multe = load inutil | `Cache::remember` 30-60s pe status + `max-age=60` |
| SP-P3-8 | P3 | `StatusPageService.php:72`, `StatusPageEdit.php:205-299` | Cache-ul public de 60s nu e invalidat la crearea/actualizarea manuală a incidentelor sau la salvarea paginii | Operatorul publică un incident urgent și nu-l vede pe pagina publică → confuzie, dublă postare | `Cache::forget("status-page:{id}")` după scrieri |
| SP-P3-9 | P3 | `StatusPageEdit.php:197-203,371-375`, `database/seeders/` | Template-urile de incident nu au CRUD/seeder — dropdown permanent gol | Feature-ul e invizibil/nefolosibil; cod mort în practică | Mini-CRUD în Settings sau seeder cu 3-4 template-uri standard |
| SP-P3-10 | P3 | `StatusPageService.php:141-143`, `resources/views/status-page/show.blade.php:98` | SLA „Current Month" folosește `uptime_30d` (fereastră glisantă de 30 zile), nu luna calendaristică — inconsecvent cu istoricul lunar din `SiteMonthlySnapshot` (`:153-157`) | Pe 2 ale lunii, „Current Month" reflectă în mare parte luna trecută; un client compară cu istoricul lunar și numerele nu bat | Calculează luna curentă din `UptimeCheck` pe interval calendaristic sau redenumește eticheta („Last 30 days") |
| SP-P3-11 | P3 | `ResolveStatusPageIncident.php:38-42`, `pgsql-schema.sql:8322` | Incidente auto cu `site_id` devenit NULL (site șters) nu mai pot fi rezolvate automat | Site scos din management în timpul unui outage → incident deschis pe pagina publică pe termen nelimitat | Acoperit de comanda de cleanup din SP-P2-4 |

**Total: 1×P1, 11×P2, 11×P3, 0×P0.**

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-urile existente

1. **Invalidare de cache + preview la publicarea incidentelor** — `Cache::forget` după orice scriere (SP-P3-8) plus un buton „View public page" cu starea proaspătă; azi operatorul lucrează orb 60s.
2. **Autor + audit pe incidente** — `created_by` pe incident/update și afișare în admin UI (SP-P2-11); costul e o migrare + 2 linii per create.
3. **Finalizarea scheduled maintenance** — form-ul de incident are deja toate câmpurile în DB (`is_scheduled`, `scheduled_start_at/end_at`); lipsesc doar 3 input-uri și o bifă (SP-P2-8).
4. **Slug-uri neghicibile + `is_public` opt-in** — sufix aleator la generarea slug-ului și default privat (SP-P2-9); o linie în `updatedTitle()` + o migrare de default.
5. **Etichetă corectă pe SLA** — „Last 30 days" în loc de „Current Month" sau calcul calendaristic real (SP-P3-10); încrederea clientului în cifrele SLA e tot rostul paginii.

### (b) Propuneri de feature-uri noi

1. **Abonare la notificări pe pagina de status (email „subscribe to updates")** — standardul categoriei (Statuspage/Instatus/MainWP-uptime); infrastructura de trimitere există deja în modulul 20 (`Services/Notifications/*`, Postmark), lipsește doar tabela de subscriberi + hook în `createAutoIncident`/`resolveAutoIncident`. Efort: **M**.
2. **Bară de uptime pe 90 de zile per site (heatmap zilnic)** — datele există deja în `UptimeCheck`/`SiteMonthlySnapshot` și parțial agregat în `uptime_365d` pe monitor (`CheckUptime.php:255-262`); e elementul vizual care face o pagină de status credibilă (toți competitorii îl au). Efort: **S/M**.
3. **Custom domain end-to-end** — middleware de rezolvare pe `Host` + caddy/nginx cu TLS on-demand (sau Cloudflare for SaaS); transformă SP-P2-7 din promisiune falsă în diferențiator real pentru o agenție white-label. Efort: **L**.

---

*Raport generat în cadrul auditului pe module, Faza 1. Nicio modificare de cod aplicată.*
