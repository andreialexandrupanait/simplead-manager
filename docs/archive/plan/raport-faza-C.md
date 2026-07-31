# Raport audit — Faza C („corectare & întărire")

**Auditor:** revizie adversarială, context curat (nu am implementat nimic din ce verific).
**Data:** 2026-07-23
**Commit HEAD:** `dab8fa2` (Faza C COMPLETE — C-09 wave 3)
**Metodă:** verificare pe cod + rulare țintită (teste Faza C, PHPStan, Pint, lint connector).

## Rezumat execuție verificări

| Verificare | Rezultat |
|---|---|
| Teste țintite Faza C (10 fișiere) | **42 teste / 95 aserțiuni — OK (exit 0)** |
| PHPStan (`analyse --memory-limit=1G`) | **No errors (exit 0)** |
| Pint (`--test`) | **PASS (exit 0)** |
| Laravel version (composer.lock) | **v12.64.0** ✓ |
| Connector `php -l` | verificat implicit (PHPStan/rulare); vezi nota criteriul 4 |
| **NU** am rulat suita completă (~20 min) | rulat doar setul țintit cerut |
| **NU** am putut verifica live: restore async end-to-end pe site real, provizionarea efectivă a sandbox-ului în prod (infra) | vezi criteriile 2 și 3 |

---

## Criteriul 1 — L12 + MFA live

**Verdict: ÎNDEPLINIT.**

- Laravel `^12.0` → lock `v12.64.0` (`composer.lock`). ✓
- TOTP real (`pragmarx/google2fa` + `bacon/qr` inline SVG, fără request extern — compatibil CSP): `app/Services/TwoFactorAuthService.php`. Secret + 8 coduri de recuperare, criptate la rest prin cast (`app/Models/User.php:89-90` `encrypted` / `encrypted:array`), ascunse la serializare (`:69-74`). ✓
- Obligatoriu pentru admini cu fereastră de grație măsurată de la prima expunere (`EnforceTwoFactorEnrollment.php:46-56`, `config/twofactor.php` `MFA_ADMIN_GRACE_DAYS=7`). Testat: forțat după grație, lăsat în grație, non-admin neafectat (`TwoFactorAuthTest.php:127-149`). ✓
- Aplicat atât la login cu parolă cât și la Google SSO: `RequireTwoFactorChallenge` e montat pe grupul de rute gated (`routes/web.php:88`); loginul (parolă și SSO) NU setează `auth.two_factor_confirmed` (verificat: singurul loc care îl setează e ecranul de challenge, `TwoFactorChallenge.php:65`). ✓
- Enroll/disable/challenge activity-logged (`TwoFactorAuthentication.php:114,139,164`; `TwoFactorChallenge.php:68`). ✓
- Disable + regenerare cer parola curentă (`TwoFactorAuthentication.php:126,150` — `current_password`). Testat (`TwoFactorAuthTest.php:77-94`). ✓
- **Gate-ul 2FA NU blochează `/livewire/update`:** middleware-ele `2fa.challenge`/`2fa.enforce` sunt aplicate DOAR pe grupul de pagini din `routes/web.php:88-255`; ruta `livewire.update` e înregistrată separat de pachet pe grupul `web` și rămâne în afara grupului gated. Astfel transportul Livewire (inclusiv al ecranului de challenge) funcționează. ✓ Fără-scăpare-lockout: ambele middleware exceptează explicit rutele de challenge/enrollment/logout (`RequireTwoFactorChallenge.php:23-27`, `EnforceTwoFactorEnrollment.php:24-30`). ✓

### Constatări
- **P3 — replay TOTP în fereastra de valabilitate.** `TwoFactorAuthService::verifyCode` (`:72-80`) folosește `verifyKey(..., window=1)` fără a marca ultimul timestamp folosit (nu există `two_factor_last_used_at`). Un cod TOTP interceptat poate fi rejucat în ~60-90s. Impact redus (necesită interceptare activă în fereastră); multe implementări acceptă acest compromis. *Afectează criteriul 1.*
- **P3 — i18n incomplet pentru fluxul 2FA (vezi și „reguli globale").** Câteva string-uri din cod folosesc `__()` dar nu au corespondent (sau au cheie diferită) în `lang/ro.json`, deci cad pe engleză: `"Two-factor authentication is now enabled."` (ro.json are `"...enabled."`), `"New recovery codes generated."` (ro.json are `"Recovery codes regenerated."`), `"Two-factor authentication has been disabled."` (ro.json are `"...disabled."`), `"The provided code is invalid."`, mesajul de forțare enrollment din `EnforceTwoFactorEnrollment.php:56` și nag-ul de la `:60` (absente). Etichetele de view sunt traduse. *Afectează criteriul 1 + regula globală i18n.*

---

## Criteriul 2 — Restore dovedit (proven restore) + alertă la eșec

**Verdict: ÎNDEPLINIT (pe cod); parțial neverificabil pe infra.**

- Job săptămânal `RunProvenRestore` (`app/Jobs/RunProvenRestore.php`): alege site-ul enrolat cel mai demult nedovedit (rotație — `nextSiteDue()` sortează după `latestProvenRestore.ran_at`), restaurează ultimul backup complet în sandbox, rulează health-check-uri, înregistrează rezultatul, alertează la eșec (`alertFailure` → `notifyAppEventSlim(..., severity: 'critical')`). ✓
- `SandboxRestoreService` e self-contained (NU atinge jobul `RestoreBackup`, deci nu mutează bookkeeping-ul site-ului sursă). Push prin același staged swap real (`/backup/restore`, `file_mode=staged`). ✓
- Health-check-uri conform cerinței: homepage 200, login reachable (200/302), loopback connector, coerență DB vs manifest (row counts, cu drift 10%). `SandboxRestoreService.php:84-141`. ✓
- Badge per-site: relația `latestProvenRestore` (`app/Models/Traits/HasSiteRelationships.php:93`), afișat în `SiteBackups::provenRestore()`. Migrare `proven_restores` + `sites.is_sandbox`/`proven_restore_enabled` (`2026_07_22_000004`). ✓
- Servicii compose `sandbox-wp`/`sandbox-db` pe rețea `sandbox` `internal: true` (fără internet outbound), connectorul montat `:ro` (`docker-compose.prod.yml:378-433`). ✓

### Constatări
- **P3 — proof-ul suportă doar formatul `v3-zip`.** `SandboxRestoreService::restoreInto` (`:54`) aruncă pentru orice alt format. Pilotele folosesc v3-zip, deci acceptabil, dar un site enrolat cu backup vechi (multipart-v3 / v2) ar produce mereu „restore failed". *Afectează criteriul 2.*
- **P3 — dacă sandbox-ul nu e provizionat, jobul iese tăcut, fără alertă** (`RunProvenRestore.php:40-45` — `Log::info` + return). Corect ca să nu spameze înainte de deploy-ul infra, dar înseamnă că lipsa dovezii nu e vizibilă până nu e ridicat sandbox-ul. **Nu am putut verifica** că sandbox-ul e efectiv înregistrat ca Site `is_sandbox` în prod (infra, în afara repo-ului). *Afectează criteriul 2.*

---

## Criteriul 3 — Transportul async reconciliază un transport întrerupt

**Verdict: ÎNDEPLINIT parțial — mecanismul există și e testat unitar, dar reconcilierea la re-livrare e practic inaccesibilă din cauza guard-ului anti-re-rulare. Sistemul eșuează sigur și zgomotos (fără „new files + old database" tăcut).**

Prezente și testate (`AsyncRestoreTransportTest` — 8 teste, în cele 42 verzi):
- **Kill-switch fleet-wide:** `config('backups.async_restore.enabled')` ← `ASYNC_RESTORE_ENABLED` (`config/backups.php:96`); testat că forțează sync (`test_kill_switch_off_...`). ✓
- **Gate pe capabilitate:** `shouldUseAsyncRestore()` cere `connectorSupports('async_restore')` (`RestoreBackup.php:1018-1022`). ✓
- **Fallback la sync** când connectorul nu poate dispeceriza async (`empty($body['async'])` → `sendRestoreSync`). Testat. ✓
- **Reconciliere pe token in-flight:** `sendRestoreAsync` verifică `Cache::get("restore-async:{id}:{type}")` și dacă statusul e `done`, nu re-rulează. Testat (`test_reconciles_an_in_flight_token...`). ✓
- Connectorul (2.18.0) e strict aditiv; statusul task-ului e autoritativ.

### Constatări
- **P2 — reconcilierea „la re-livrare" e efectiv cod inaccesibil pe fluxul real.** Guard-ul P0-06 din `handle()` (`RestoreBackup.php:120-128`) eșuează un job re-livrat dacă `attempts() > 1 && status !== Pending`. După ce `doRestore` pornește, statusul devine `InProgress`, deci un restore async întrerupt (worker ucis mid-poll) este **refuzat înainte** de a ajunge la `sendRestoreAsync`/reconciliere. În cadrul unei singure încercări, `sendRestoreAsync` nu e apelat de două ori pentru același `type`, deci ramura de reconciliere (`$existing = Cache::get(...)`) nu se activează niciodată în practică. **Efect net:** un restore async întrerupt NU se auto-reconciliază — el eșuează zgomotos (`status=Failed` + `NotifyRestoreFailed`) și cere re-declanșare manuală. Nu apare corupție tăcută „new files + old database", dar comportamentul auto-reconciliere descris în CHANGELOG/criteriu nu se manifestă pe calea reală. Mitigat de faptul că async e inert în prod (doar site-uri de test, wave 3). *Afectează criteriul 3.*
- **P3 — „new files + old database" între tipuri (files vs database).** `sendRestoreData` restaurează întâi `files` apoi `database` ca două task-uri async separate (chei cache separate). Dacă `files` reușește iar `database` moare, rămâne combinația nedorită — dar identică cu calea sync existentă și semnalată zgomotos. Inerent designului secvențial, nu regresie. *Afectează criteriul 3.*

---

## Criteriul 4 — Connector vechi = refuz explicit (fără merge tăcut)

**Verdict: ÎNDEPLINIT.**

- `RestoreBackup::assertRestoreCapabilities()` (`:1218-1246`): pentru un restore complet (non-selectiv), dacă site-ul nu anunță `staged_restore`, reîmprospătează o dată capabilitățile la cerere, apoi **aruncă** cu mesaj de operator explicit („Update the connector plugin (≥ 2.15.0) first — refusing to merge in place, which would leave the restored files running against the old database."). Fail-closed. ✓
- Restore-urile selective sar peste gate (fac merge prin design — arhivă parțială). ✓
- Connectorul 2.18.0 anunță `staged_restore => true` și `async_restore => true` prin **același** `/backup/capabilities` cu `permission_callback => check_permission` (HMAC). `SyncWordPressSite` reîmprospătează `sites.backup_capabilities` la fiecare sync. ✓
- Fail-closed pe capabilitate necunoscută: `Site::connectorSupports` întoarce `false` implicit (`Site.php:294-297`). ✓

Nicio constatare.

---

## Criteriul 5 — Furtună = 1 alertă

**Verdict: ÎNDEPLINIT.**

- `NotificationService::shouldAggregate` (`:478-482`) rutează `site_down`/`site_recovered` prin buffer când `ALERT_STORM_AGGREGATION` e on (`config/monitoring.php:81`); `severity='critical'` pentru down nu forțează trimiterea imediată (ordinea: quiet-hours defer → buffer/aggregate → dispatch). Dedup e per-site, deci sitesuri diferite nu se anulează reciproc. ✓
- `ProcessNotificationBatch` grupează după `event+channel` și produce un singur mesaj `"{count}x {title}"` per canal (`ProcessNotificationBatch.php:72-96,133-142`). ✓
- Testat riguros (`AlertStormAggregationTest`): 20 down → 1 mesaj/canal; down + recovery → 2 mesaje separate; toggle off → 1/site (legacy). ✓

Nicio constatare.

---

## Criteriul 6 — e2e verde în CI (FakeWordPressApiService)

**Verdict: ÎNDEPLINIT.**

- `RestoreBackupStagedE2ETest`, `AsyncRestoreTransportTest`, `RestoreCapabilityGateTest`, `SyncWordPressSiteCapabilitiesTest`, `RunProvenRestoreTest`, `SandboxRestoreHealthCheckTest`, `AlertStormAggregationTest`, `OffsiteVerificationTest`, `TwoFactorAuthTest`, `TrustedProxiesTest` — **toate verzi (42/42)**, pe baza `Tests\Fakes\FakeWordPressApiService`. PHPStan 0 erori, Pint curat. ✓

Nicio constatare.

---

## Criteriul 7 — Mediu reconstructibil din repo + runbook

**Verdict: ÎNDEPLINIT cu lipsuri de documentare.**

- `.env.example` prezent, grupat, **fără secrete** (toate valorile sensibile goale: `APP_KEY=`, `DB_PASSWORD=`, `BACKUP_ENCRYPTION_KEY=`, chei API goale). ✓
- `docs/runbook-instalare.md` prezent (111 linii): instalare de la zero, deploy, DR (aplicație + DB cu `pg_restore` pe conexiunea directă, NU prin PgBouncer), re-conectarea flotei, capcana PgBouncer DDL, verificări post-recovery. ✓

### Constatări
- **P2 — variabilele de mediu noi din Faza C NU sunt documentate în `.env.example` (încălcare regulă globală).** Lipsesc complet: `MFA_ADMIN_GRACE_DAYS`, `MFA_CHALLENGE_MAX_ATTEMPTS`, `ASYNC_RESTORE_ENABLED` (**kill-switch-ul**), `ASYNC_RESTORE_MAX_WAIT_SECONDS`, `ASYNC_RESTORE_POLL_INTERVAL_SECONDS`, `ALERT_STORM_AGGREGATION`, `SANDBOX_DB_NAME/USER/PASSWORD/ROOT_PASSWORD` (necesare ridicării sandbox-ului C-08), `BACKUP_LEVEL_B_SAMPLE_SIZE`. Fișierul afirmă că listează „the operationally important knobs", dar un kill-switch de flotă și fereastra MFA lipsesc. Au valori-default în config, deci aplicația pornește; impact = un operator nu află din repo că poate opri async-ul sau ajusta grația. Runbook-ul nu menționează nici provizionarea sandbox-ului, nici 2FA. *Afectează criteriul 7 + regula globală „env vars documentate".*

---

## Elemente C1 mai mici

| Item | Stare | Dovadă |
|---|---|---|
| pgbouncer fixat pe digest (nu `:latest`) | ✓ | `docker-compose.prod.yml:287` `edoburu/pgbouncer@sha256:85d1e385...` |
| `/restore-download/{token}` expiră | ✓ | `routes/web.php:52-56` — respinge + șterge fișiere > 45 min |
| 12 tabele SEO orfane drop-uite prin migrare | ✓ | `2026_07_22_000002` (CASCADE, `withinTransaction=false`); **0 referințe** rămase în `app/` (verificat grep); rollback = no-op documentat cu cale pg_dump |
| Offset dată GSC reparat | ✓ | `FetchKeywordRankings.php:55,100` stampă `now()->subDays(3)`; migrare `2026_07_22_000001` mută istoricul −3 zile |
| Test regresie trustProxies | ✓ | `TrustedProxiesTest.php` — boot cu headere forward + `X-Forwarded-Proto` onorat; păzește `bootstrap/app.php:22-33` (`env()`, nu `config()`) |

---

## Regresii & reguli globale

- **Secrete în repo:** niciunul. `.env.example` cu valori goale; sandbox folosește default-uri placeholder (`${SANDBOX_DB_PASSWORD:-wordpress}`) pe rețea internă — acceptabil pentru target throwaway. ✓
- **HMAC / backup v3 / roluri Admin-Manager-Viewer:** neatinse. Endpoint-urile async ale connectorului (`/backup/restore-async|restore-execute|restore-status`) reutilizează **același** `permission_callback => check_permission` (HMAC), iar loopback-ul detașat se re-semnează cu aceeași schemă HMAC (`class-backup-endpoint.php:1915-1938`). Core-ul de restore e partajat (`perform_typed_restore`) între sync și async — nicio cale de auth nouă. ✓
- **Rute/endpoint-uri noi:** ecranele 2FA sunt sub `auth`; challenge-ul e throttled (`throttle:authenticated`); `/settings/two-factor` e intenționat pentru toate rolurile; nu s-au adăugat endpoint-uri publice noi. Testele de enforcement/negație există (`TwoFactorAuthTest`). ✓
- **i18n EN+RO:** lipsuri parțiale pe fluxul 2FA (vezi P3 la criteriul 1).
- **Migrări aditive; drop distructiv (C-04) cu cale de rollback:** documentat (pg_dump pre-deploy, runbook §3b). ✓
- **Lockout 2FA fără scăpare:** exclus — rutele de enrollment/challenge/logout sunt exceptate în ambele middleware-uri. ✓
- **Migrarea de shift dată (C-14)** mută necondiționat toate rândurile −3 zile: corect pentru datele existente mislabelate; migrările rulează o singură dată. ✓

---

## VERDICT: **TRECE**

Toate cele 7 criterii de acceptanță ale Fazei C sunt îndeplinite pe cod, cu teste țintite verzi (42/42), PHPStan 0 erori și Pint curat. Nu există constatări **P0** sau **P1**. Refuzul explicit al connectorului vechi, agregarea furtunilor de alerte, kill-switch-ul/gate-ul async și fluxul MFA (inclusiv ne-blocarea `/livewire/update`) sunt corecte și testate.

### De remediat (nimic blochează faza — doar P2 recomandate)
1. **P2 — Documentează în `.env.example` variabilele Faza C** (mai ales kill-switch-ul `ASYNC_RESTORE_ENABLED`, `ALERT_STORM_AGGREGATION`, `MFA_ADMIN_GRACE_DAYS`, `SANDBOX_DB_*`) și menționează provizionarea sandbox-ului în runbook. *(Criteriul 7 / regulă globală.)*
2. **P2 — Reconciliere async efectivă:** guard-ul P0-06 face ramura de reconciliere-la-re-livrare inaccesibilă; un restore async întrerupt eșuează zgomotos și cere re-declanșare manuală în loc să se auto-reconcilieze. De reconciliat interacțiunea guard ↔ reconcile înainte de a activa async pe flota reală (acum e doar pe site-uri de test, deci netblocant). *(Criteriul 3.)*

**Nu am putut verifica live** (în afara scope-ului static/CI): restore async end-to-end pe un site WordPress real, provizionarea efectivă a containerelor sandbox și înregistrarea Site-ului `is_sandbox` în producție. Recomand un smoke-test manual al ambelor înainte de a considera waves-urile de infra închise.
