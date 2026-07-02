# 25 — Securitatea întregii aplicații (App-wide Security)

**Data:** 2026-07-02 · **Auditor:** revizuire senior Laravel/DevOps/securitate · **Scope:** întreg working tree-ul, inclusiv codul necomis (export local backup). Fiecare afirmație citează `path:line` verificat prin citirea fișierului. Unde nu am putut verifica la runtime (fișiere `.env`, comportament în producție), notez explicit „neverificat".

---

## Rezumat executiv

Aplicația are un strat de securitate perimetral decent: middleware `SecurityHeaders` cu CSP pe nonce + HSTS (`app/Http/Middleware/SecurityHeaders.php:34-49`), rate limiting granular pe login/2FA/agent/status-page (`app/Providers/AppServiceProvider.php:54-88`), rute publice protejate prin URL-uri semnate sau token-uri, secrete sensibile marcate `encrypted` pe modelele `User` și `Site`, iar hardening-ul de upload avatar din commit `27edf6d` este corect (extensia derivă din MIME server-side, `app/Livewire/Settings/ProfileSettings.php:92-99`). Controllerele de download de backup validează atât autorizarea (`authorize('view', ...)`) cât și traversarea de cale (`BackupDownloadController.php:22-40`).

Există însă o **breșă P0 de autorizare pe cea mai distructivă operațiune din platformă**: componenta Livewire `RestoreConfirmation` (restore de backup peste un site WordPress live) nu are **nicio** verificare de autorizare — nici în `mount`, nici pe metodele publice — și încarcă backup-uri după ID arbitrar (`app/Livewire/Sites/Detail/Components/RestoreConfirmation.php:56-80, 183-292`). Orice utilizator autentificat, inclusiv un `Viewer` read-only, poate declanșa un restore pe backup-ul oricărui alt client (IDOR cross-tenant), suprascriind un site de producție.

La nivel sistemic, componentele de detaliu-site care execută operații de scriere folosesc `authorizeSiteAccess` (nivel de vizualizare, care permite `Viewer`-ilor) în loc de `authorizeSiteModification`, permițând rolului read-only să șteargă backup-uri, să șteargă/creeze utilizatori WP, să facă curățare de bază de date etc. (P1). Middleware-ul `EnforceTwoFactor` conține un bypass mult prea larg (`$request->is('livewire*')`) care golește de conținut politica MFA (P2), iar login-ul prin Google SSO sare complet peste challenge-ul 2FA al utilizatorului (P2).

---

## Stack-ul de autentificare

**Breeze + invitații (invitation-only din `27edf6d`).** Rutele de înregistrare publică au fost eliminate (commit `27edf6d` șterge `RegisteredUserController` și `routes/auth.php` nu mai conține `register`, verificat `routes/auth.php:14-63`). Onboarding-ul se face doar prin invitație: `AcceptInvitationController` validează token-ul, expirarea și creează userul cu rolul din invitație (`app/Http/Controllers/Auth/AcceptInvitationController.php:33-62`). Corect. Observație: userul creat prin invitație primește `email_verified_at => now()` fără a confirma efectiv adresa (`:53`) — acceptabil pentru invitation-only, dar înseamnă că `verified` middleware nu oferă garanție reală de proprietate a adresei.

**Login + 2FA.** `AuthenticatedSessionController::store` autentifică, apoi, dacă `two_factor_enabled`, face `logout` și mută userul într-o sesiune parțială `2fa:user_id` înainte de challenge (`app/Http/Controllers/Auth/AuthenticatedSessionController.php:34-47`). `TwoFactorChallengeController::store` verifică TOTP (fereastră implicită Google2FA) sau recovery code cu `in_array` (`app/Http/Controllers/Auth/TwoFactorChallengeController.php:49-67`). Regenerează sesiunea la succes (`:77`). Comparația recovery code prin `in_array($code, $recoveryCodes)` nu este constant-time, dar recovery codes sunt aleatorii lungi — impact scăzut.

**Google SSO — bypass 2FA (vezi Constatări P2).** `GoogleSsoController::callback` face `Auth::login($user, remember: true)` direct, fără a verifica `two_factor_enabled` (`app/Http/Controllers/Auth/GoogleSsoController.php:41, 63`). Un user care are 2FA activat în aplicație îl ocolește complet dacă se loghează prin Google. În plus, linkarea contului se face pe potrivire de email (`orWhere('email', ...)`, `:31-33`) — riscul de preluare e mitigat de faptul că Google verifică proprietatea adresei, dar comportamentul e inconsistent cu politica MFA a aplicației.

**EnforceTwoFactor — bypass prea larg (Constatare P2).** `app/Http/Middleware/EnforceTwoFactor.php:32` exceptează de la enforcement toate cererile `livewire*`. Cum întreaga interfață interactivă rulează prin endpoint-ul Livewire `/livewire/update`, politica „mfa_required" devine un simplu obstacol pe încărcările GET full-page; toate acțiunile de business rămân accesibile fără 2FA. Istoricul (commit-urile `fc0defd` și `d43c52d` de bypass Livewire) confirmă că exceptarea a fost lărgită iterativ pentru a repara flow-ul de setup 2FA — dar rezultatul actual e prea permisiv. Nu este un bypass al gate-ului de login (acela rămâne intact în `AuthenticatedSessionController`), ci al politicii de *impunere* a activării 2FA.

---

## Sesiuni & cookies

`config/session.php`:
- Driver `redis` (`:21`) — sesiuni server-side, deci datele nu sunt în cookie; `encrypt=false` (`:50`) are impact redus.
- `http_only` default `true` (`:185`), `same_site` `lax` (`:202`) — rezonabil.
- **`secure` este `env('SESSION_SECURE_COOKIE')` fără default (`:172`)** — dacă variabila nu e setată explicit pe `true` în `.env`-ul de producție, flag-ul `Secure` nu e forțat pe cookie-ul de sesiune. Aplicația e HTTPS-only la nivel de nginx (redirect 301, `docker/nginx/conf.d.ssl/app.conf:12-14`), dar cookie-ul ar trebui marcat `Secure` explicit. **Neverificat** valoarea reală din `.env` de producție (fișierul e în afara permisiunilor de citire). Vezi Constatări P2.
- `lifetime` 120 min (`:35`), `expire_on_close=false` (`:37`) — standard.

Trusted proxies: `bootstrap/app.php:22-28` setează `trustProxies` la `env('TRUSTED_PROXIES', '127.0.0.1')` cu headere X-Forwarded-*. În Docker, nginx nu e pe 127.0.0.1 față de containerul app, ci pe rețeaua internă; dacă `TRUSTED_PROXIES` nu e ajustat, `$request->ip()` (folosit ca cheie de rate-limit login) poate reflecta IP-ul containerului nginx în loc de IP-ul real al clientului, degradând rate-limitingul pe email+IP. **Neverificat** valoarea de producție.

---

## Autorizare

**Policies existente:** `app/Policies/` conține `SitePolicy`, `BackupPolicy`, `ClientPolicy`, `StatusPagePolicy`. `SitePolicy::delete` cere `isAdmin` (`SitePolicy.php:36-43`); `BackupPolicy::restore` interzice `Viewer` și cere admin sau proprietar (`BackupPolicy.php:27-34`). Aceste policies sunt însă aplicate **inconsistent**: componentele globale (`Livewire/Backups/BackupsOverview.php:161`, `Livewire/Sites/SitesList.php` via `$this->authorize('delete', $site)` la `GlobalDashboard.php:269`) le folosesc, dar componentele de detaliu-site nu (vezi mai jos).

**Cine poate șterge un site:** `GlobalDashboard::deleteSite` apelează `$this->authorize('delete', $site)` → `SitePolicy::delete` → doar admin (`app/Livewire/Dashboard/GlobalDashboard.php:269-270`). Corect.

**Cine poate declanșa un restore:** aici este problema P0. Ruta `/sites/{site}/backups` montează `SiteBackups`, care autorizează accesul la site în `mount` (`SiteBackups.php:31`, `authorizeSiteAccess` = permite și Viewer). Blade-ul include componenta nested `<livewire:sites.detail.components.restore-confirmation :site="$site" />` (`resources/views/livewire/sites/detail/site-backups.blade.php:728`). Componenta `RestoreConfirmation`:
- NU are `mount` și NU verifică autorizarea nicăieri (fișier integral citit, `RestoreConfirmation.php:1-304`);
- `openModal(int $backupId)` face `Backup::with([...])->findOrFail($backupId)` fără scoping pe site sau pe user (`:56-59`);
- `restore()` / `restoreAnyway()` → `dispatchRestore()` → `RestoreBackup::dispatch($this->backup, ...)` folosind backup-ul încărcat liber (`:183-210, 265-292`).

Deoarece în Livewire 4 orice metodă publică este apelabilă din client (nici `#[Locked]`, nici verificare), un atacator autentificat care are acces la *orice* pagină de backups (deci și un `Viewer` asignat unui client) poate apela `openModal(<id backup al altui client>)` urmat de `restoreAnyway()` și declanșa un restore peste site-ul live al altui client. Job-ul `RestoreBackup` nu are context de user și nu re-verifică autorizarea — nu există plasă de siguranță. **Constatare P0.**

**Inventar rute + eșantion acțiuni Livewire:**

| Rută / acțiune | Fișier:linie | Gate |
|---|---|---|
| `/` dashboard, toate `/sites/*`, `/backups`, `/reports`, `/security`, `/uptime` etc. | `routes/web.php:72-237` | `auth`+`verified`+`throttle:authenticated` (grup) |
| `/settings/*` (general, integrations, users, wordpress, ai-incident) | `routes/web.php:192-236` | `role:admin` |
| `/security/presets` | `routes/web.php:144` | `role:admin` |
| `deleteSite` | `GlobalDashboard.php:269` | policy `delete` (admin) ✅ |
| `renameSite` | `GlobalDashboard.php:~245` | policy `update` ✅ |
| `RestoreConfirmation::restore` | `RestoreConfirmation.php:183-292` | **niciunul** ❌ (P0) |
| `WithBackupActions::deleteBackup/bulkDelete/backupFull` | `WithBackupActions.php:66-247` | doar scoping `$this->site->backups()`, fără check de rol ❌ (P1) |
| `SecurityUsers::createUser/deleteUser` | `SecurityUsers.php:122-197` | mount `authorizeSiteAccess` (permite Viewer) ❌ (P1) |
| `SiteDatabaseCleanup` (curățare DB remote) | `SiteDatabaseCleanup.php:80` | mount `authorizeSiteAccess` (permite Viewer) ❌ (P1) |
| `SitePlugins` (update/toggle) | `SitePlugins.php:61` | mount `authorizeSiteAccess` (permite Viewer) ❌ (P1) |
| `BackupsOverview`, `UpdatesOverview`, `SeoOverview`, `UptimeOverview` | ex. `BackupsOverview.php:136,161` | `authorizeSiteModification` (blochează Viewer) ✅ |

Rezultă un **pattern inconsistent**: componentele *globale* folosesc corect `authorizeSiteModification` (care apelează `abort(403)` pentru `Viewer`, `WithSiteAuthorization.php:25-38`), dar componentele de *detaliu-site* folosesc `authorizeSiteAccess` (nivel view) și nu re-verifică rolul pe metodele de scriere. Vezi Constatări P1.

---

## CSRF & Livewire

Rutele web sunt în grupul `web` cu protecție CSRF implicită Laravel; endpoint-ul Livewire `/livewire/update` folosește mecanismul propriu de snapshot cu checksum care împiedică falsificarea proprietăților publice. Aceasta este relevantă pentru P0: atacatorul **nu** poate schimba proprietatea `$site` (protejată de checksum), dar **nu are nevoie** — vectorul folosește argumentul liber `$backupId` al metodei `openModal`, iar restore-ul acționează pe `$this->backup->site`, nu pe `$this->site`. Rutele API (`routes/api.php`) sunt stateless (Bearer/HMAC), în afara sesiunii web, deci fără CSRF — corect.

---

## Rate limiting

Definiții în `AppServiceProvider.php:54-88`:
- `login`: 5/min pe `email|ip` (`:54-58`) — aplicat pe `POST /login`, `two-factor.store`, `forgot-password` (`routes/auth.php:19,26,33`). Bun. **Observație:** cheia `email|ip` permite unui atacator care iterează multe emailuri să nu fie limitat per-IP agregat; totuși 5/min per combinație e rezonabil.
- `agent`: 120/min pe `agent:<site_token>` (`:72-76`); `agent-activity-logs`: 1/min (`:78-82`).
- `status-page-auth`: 5/min (`:84-88`).
- Endpoint-uri publice: `/health` 30/min, `/api/webhooks/inbound` 60/min, `/restore-download/{token}` 10/min, plugin signed 10/min, portal 60/min (`routes/web.php:34-66, 245-259`).
- Endpoint plugin agent HMAC (`routes/api.php:36-44`) — throttle `agent` per site token. Rezonabil.

Nu am găsit lipsuri majore de rate-limit pe suprafețele expuse. Bruteforce-ul 2FA e limitat prin `throttle:login` pe `two-factor.store`.

---

## Dependențe

`composer audit` **nu a putut fi rulat** (binarul `composer` lipsește în mediu; **neverificat**). Din `composer.lock`, versiuni cheie: `laravel/framework v11.48.0`, `livewire/livewire v4.1.4`, `laravel/socialite v5.26.0`, `guzzlehttp/guzzle 7.10.0`, `league/flysystem 3.31.0`, `pragmarx/google2fa v8.0.3` — versiuni recente, fără CVE evident cunoscut la data auditului (neconfirmat prin advisory DB).

`npm audit --omit=dev` a raportat **4 vulnerabilități (2 high, 2 moderate)**, toate în tooling de build/markdown, nu în runtime server-rendered:
- `linkify-it <=5.0.0` (high, ReDoS)
- `picomatch <=2.3.1 / 4.0.0-4.0.3` (high, method injection / ReDoS)
- `markdown-it <=14.1.1` (moderate, DoS quadratic)
- `postcss <8.5.10` (moderate, XSS la stringify CSS)

Impact real scăzut (dependințe de build, nu servite clientului). `npm audit fix` disponibil. Vezi Constatări P3.

---

## Secrete

- **Credențiale site WP:** `Site::$casts` marchează `api_key` și `api_secret` ca `encrypted` (`app/Models/Site.php:177-178`). **Inconsistență (Constatare P2):** `AuthenticateAgent` face `Site::where('api_key', $siteToken)->first()` (`app/Http/Middleware/AuthenticateAgent.php:23`), interogând o coloană declarată `encrypted` cu o valoare în clar. Cum cast-ul `encrypted` al Laravel folosește IV aleator (ciphertext nedeterminist), un `WHERE api_key = <plaintext>` nu poate potrivi rânduri criptate. Aceasta implică fie (a) autentificarea agentului este funcțional deteriorată, fie (b) coloana conține de fapt text în clar la rest (cast adăugat fără re-criptarea datelor). În ambele cazuri e un semnal serios: sau feature-ul e rupt, sau secretele agentului NU sunt criptate la rest așa cum sugerează modelul. **Neverificat** starea reală a datelor din DB de producție.
- **Token-uri OAuth / API keys integrări:** stocate în settings cu `encrypt()` explicit și `decrypt()` la citire (`AppServiceProvider.php:99-112`, `AiIncidentResponseSettings.php:49,81`). Corect.
- **Personal access tokens (API):** stocate ca `hash('sha256', $token)`, comparate prin lookup pe hash (`AuthenticateApiToken.php:22-25`) — corect (nu se stochează token-ul în clar).
- **2FA:** `two_factor_secret => encrypted`, `two_factor_recovery_codes => encrypted:array` (`User.php:89-90`). Corect.
- **APP_KEY rotation:** nicio strategie de rotație documentată/implementată; rotația ar invalida toate valorile `encrypted` (2FA, api_key/secret, settings OAuth) și token-ul callback backup (`BackupCallbackController::generateToken` semnează cu `config('app.key')`, `:56`). **Neverificat** procedura operațională.
- **Secrete în loguri:** `WebhookController` loghează `array_keys($payload)` și primele 5 câmpuri ale payload-ului în `ActivityLogger` (`WebhookController.php:19-27`) — endpoint public, poate fi folosit pentru log/activity injection și stocare de conținut arbitrar (P3). `AppServiceProvider` loghează mesajele de excepție ale job-urilor (`:146,151`) — pot conține date sensibile în mesaj; risc scăzut. Nu am găsit loguri care să scrie direct token-uri/parole.

---

## Headers & hardening

`SecurityHeaders` middleware (prepend pe grupul web, `bootstrap/app.php:30-32`):
- CSP cu nonce pe request (`SecurityHeaders.php:34-46`): `default-src 'self'`, `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'self'`. **`script-src` include `'unsafe-eval'`** (necesar Alpine.js v3) și `style-src 'unsafe-inline'` — slăbesc protecția XSS; documentat ca tradeoff, remediabil doar prin migrare la `@alpinejs/csp`. P3.
- HSTS `max-age=31536000; includeSubDomains; preload` doar pe HTTPS (`:48-49`); dublat în nginx (`app.conf:42`, fără `preload` acolo — inconsistență minoră).
- `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, `X-Robots-Tag: noindex` — toate prezente (`:28-33`).

nginx (`docker/nginx/conf.d.ssl/app.conf`): TLS 1.2/1.3, cifruri ECDHE-GCM, `ssl_session_tickets off` (`:26-33`); blochează `.env`, `.git`, `wp-admin`/`wp-login`/`xmlrpc` (`:52-54`); `fpm-ping` restricționat la loopback + rețele private (`:88-95`); ascunde `X-Powered-By` (`:107`); `client_max_body_size 100M`. Configurație solidă.

---

## Upload-uri

Avatar (post-`27edf6d`): validare `image|mimes:jpeg,jpg,png,gif,webp|max:2048|dimensions:max 1000x1000` (`ProfileSettings.php:82`) și, critic, **extensia derivă din `getMimeType()` server-side**, nu din input-ul clientului, cu `throw` pe MIME necunoscut (`:92-99`). Fișierul e salvat cu nume `uniqid('avatar_')` pe disk-ul `public` (`:99`). Aceasta remediază corect abuzul descris în commit (upload `.php56` cu payload imagine). Nu am identificat alte suprafețe de upload arbitrar expuse public. Corect.

---

## Constatări

| ID | Sev | Fișiere:linii | Descriere | Scenariu de eșec | Schiță remediere |
|---|---|---|---|---|---|
| S-01 | **P0** | `app/Livewire/Sites/Detail/Components/RestoreConfirmation.php:56-80,183-292`; blade `resources/views/livewire/sites/detail/site-backups.blade.php:728` | Componenta de restore (operațiune distructivă maximă) nu are nicio autorizare: fără `mount`, fără policy, iar `openModal(int $backupId)` încarcă orice backup după ID (`findOrFail`, fără scoping), `restore()/restoreAnyway()` dispatch-uind `RestoreBackup` pe backup-ul arbitrar. Job-ul nu re-verifică autorizarea. | Orice user autentificat (inclusiv `Viewer` asignat unui client) apelează din Livewire `openModal(<id backup al altui client>)` apoi `restoreAnyway()` → restore peste site-ul WordPress live al altui client (IDOR cross-tenant + pierdere/coruptere de date pe site live). | În `RestoreConfirmation` adaugă `mount(Site $site)` cu `authorizeSiteModification($site)`; în `openModal` verifică `$backup->site_id === $this->site->id` și `$this->authorize('restore', $backup)` (policy există deja, `BackupPolicy::restore`); adaugă gard de rol pe `restore/restoreAnyway`. |
| S-02 | **P1** | `app/Livewire/Traits/WithBackupActions.php:66-247,287-312`; `app/Livewire/Sites/Detail/Security/SecurityUsers.php:61,122-197`; `app/Livewire/Sites/Detail/SiteDatabaseCleanup.php:78-80`; `app/Livewire/Sites/Detail/SitePlugins.php:59-61`; trait `app/Livewire/Traits/WithSiteAuthorization.php:12-23` | Componentele de detaliu-site folosesc `authorizeSiteAccess` (nivel view, permite `Viewer`) în `mount` și nu re-verifică rolul pe metodele de scriere/distructive (delete/bulkDelete backup, create/delete user WP, curățare DB, acțiuni plugin). Rolul `Viewer` este definit read-only (`UserRole::canManageSites/canDeleteResources=false`) dar poate executa aceste mutații. | Un `Viewer` asignat unui client șterge backup-uri, șterge un admin WP prin `SecurityUsers::deleteUser`, sau lansează curățare de bază de date pe site-ul live — escaladare de privilegii + potențială deteriorare a site-ului. | Înlocuiește `authorizeSiteAccess` cu `authorizeSiteModification` în `mount`-urile componentelor cu acțiuni de scriere, sau adaugă `authorizeSiteModification($this->site)` la începutul fiecărei metode mutante; aplică policies existente. |
| S-03 | **P2** | `app/Http/Middleware/EnforceTwoFactor.php:32` | Enforcement-ul MFA exceptează toate cererile `livewire*`. Cum întreaga UI interactivă rulează prin `/livewire/update`, politica `mfa_required` nu forțează efectiv utilizatorii fără 2FA — ei pot opera aplicația via Livewire. | Admin activează `mfa_required` presupunând că toți userii vor fi forțați să activeze 2FA; în realitate userii fără 2FA continuă să folosească aplicația nelimitat prin Livewire, lăsând conturi fără al doilea factor. | Restrânge bypass-ul doar la endpoint-ul Livewire când componenta activă este `ProfileSettings` (sau permite doar update-uri către componenta de setup 2FA), nu la orice `livewire*`. |
| S-04 | **P2** | `app/Http/Controllers/Auth/GoogleSsoController.php:41,63` | Login-ul prin Google SSO face `Auth::login` direct, fără a verifica `two_factor_enabled`, sărind peste challenge-ul 2FA al aplicației; linkarea contului se face pe potrivire de email. | Un user cu 2FA activat în aplicație îl ocolește complet prin SSO; compromiterea sesiunii Google echivalează cu bypass al 2FA aplicativ. | După `Socialite` callback, dacă `user->two_factor_enabled`, redirecționează prin același flow de challenge 2FA ca login-ul cu parolă (setează `2fa:user_id` în loc de `Auth::login` direct). |
| S-05 | **P2** | `app/Models/Site.php:177-178`; `app/Http/Middleware/AuthenticateAgent.php:23` | `api_key`/`api_secret` sunt declarate cast `encrypted`, dar `AuthenticateAgent` face `Site::where('api_key', $plaintext)`. Un cast `encrypted` (IV aleator, nedeterminist) nu poate fi interogat cu valoare în clar → fie autentificarea agentului e ruptă, fie coloana conține text în clar la rest (secretele NU sunt criptate cum sugerează modelul). | Dacă datele sunt în clar: secretele agentului sunt expuse la rest contrar așteptării; dacă sunt criptate: HMAC agent nu se autentifică niciodată (fail-closed). **Neverificat** starea DB de producție. | Adaugă o coloană `api_key_hash` (SHA-256) pentru lookup determinist și păstrează secretul criptat/hashuit separat; sau folosește lookup prin token hashuit ca la `PersonalAccessToken`. |
| S-06 | **P2** | `config/session.php:172`; nginx `docker/nginx/conf.d.ssl/app.conf:12-14` | `SESSION_SECURE_COOKIE` fără default în config → flag `Secure` pe cookie-ul de sesiune nu e forțat decât dacă env-ul îl setează explicit. **Neverificat** valoarea din `.env` de producție. | Dacă env-ul nu setează `true`, cookie-ul de sesiune poate fi trimis pe o eventuală cale non-HTTPS (ex. mixed-content, subdomeniu greșit configurat), expunând sesiunea. | Setează `SESSION_SECURE_COOKIE=true` în `.env` producție și, ideal, hardcodează `'secure' => env('SESSION_SECURE_COOKIE', true)` în config. |
| S-07 | **P3** | `app/Http/Controllers/ReportViewController.php:13` | Comparație de token secret prin `!==` (nu constant-time) pe `view_token`. | Canal lateral de timing teoretic la ghicirea token-ului de raport public; impact practic redus (token aleator lung). | Folosește `hash_equals($report->view_token, $token)`. |
| S-08 | **P3** | `app/Http/Controllers/WebhookController.php:14-29` | Endpoint public neautenticat loghează payload-ul (chei + primele 5 câmpuri) în log și `ActivityLogger`, fără validare sau semnătură (60/min). | Injection în activity log / umplere a tabelei de activitate cu conținut arbitrar de la orice sursă de pe internet. | Adaugă verificare de semnătură/HMAC pe `X-Webhook-Source` sau elimină endpoint-ul (nu există pipeline de lead-uri — vezi module-map); limitează ce se loghează. |
| S-09 | **P3** | `package-lock.json` (npm audit) | 4 vulnerabilități în dependințe de build (`linkify-it`, `picomatch`, `markdown-it`, `postcss`) — ReDoS/DoS/XSS la stringify. | Impact runtime scăzut (tooling de build, nu cod servit clientului); ReDoS posibil doar dacă vreo librărie e folosită la runtime pe input neîncrezut. | `npm audit fix`; verifică dacă `markdown-it` e folosit la runtime pe conținut de la utilizator. |
| S-10 | **P3** | `app/Http/Middleware/SecurityHeaders.php:38,39` | CSP cu `'unsafe-eval'` (Alpine v3) și `style-src 'unsafe-inline'` slăbesc protecția XSS. | În caz de XSS injectat, `unsafe-eval`/`unsafe-inline` reduc bariera de exploatare. | Migrare la `@alpinejs/csp` și eliminarea expresiilor inline pentru a scoate `unsafe-eval`; nonce/hash pe stiluri. |
| S-11 | **P3** | `app/Http/Controllers/HealthCheckController.php:27-33` | `/health` public expune starea DB/Redis/Horizon/disk (30/min). | Divulgare de informații de infrastructură către atacatori (ex. „low disk space" ca semnal de DoS). | Restrânge la IP-uri interne/monitoring sau redu detaliul răspunsului pentru cereri neautentificate. |

**Contori:** P0 = 1 · P1 = 1 · P2 = 4 · P3 = 5.
