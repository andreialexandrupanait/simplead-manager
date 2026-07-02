# AUDIT.md — SAD Manager (audit tehnic complet)

**Data:** 2026-07-02 · **Scope:** întreg working tree-ul (inclusiv codul necomis: export local backup) · **Metodă:** recon (Faza 0) → 12 audituri de modul + 5 cross-cutting (17 subagenți, Fazele 1–2) → verificare manuală a fiecărui P0 pe fișierele citate (Faza 3). Rapoartele detaliate: [`docs/audit/`](docs/audit/README.md).

> **Concluzie într-o frază:** codebase-ul e ambițios și larg (91 modele, 124 servicii, 104 componente Livewire), dar **cele mai distructive operații — restore pe site live și update de plugin — rulează fără autorizare, fără plasă de siguranță și fără teste**, iar mecanismele de siguranță proiectate (SafeUpdate, BackupPolicy, verificarea de restore) există dar sunt cod mort. O platformă care poate suprascrie site-urile live ale clienților trebuie tratată azi ca **fragilă și nesigură pentru operații în masă**.

---

## Scor general: **2.5 / 10**

Scorul e ponderat pe scopul aplicației: impactul se măsoară în *blast radius către site-urile live ale clienților*. Un cod elegant nu poate compensa faptul că cea mai distructivă operație (restore) nu are autorizare, nu e atomică, nu are rollback și nu are niciun test.

| Sub-scor | Notă | Justificare (2–3 fraze) |
|---|---|---|
| **Securitate** | **2/10** | Gaură sistematică de autorizare: rolul „Viewer" (read-only prin definiție) poate declanșa restore, ștergere de backup-uri, ștergere de useri WP, curățare DB remote, purge Cloudflare și update-uri de plugin pe site-uri live — pe **orice** site, inclusiv cross-tenant (IDOR pe `openModal(backupId)`). Există `BackupPolicy` și `authorizeSiteModification()`, dar nu sunt apelate în componentele de detaliu-site. Link-urile publice de raport expun permanent harta de vulnerabilități + PII useri WP, fără expirare. |
| **Fiabilitatea operațiilor distructive** | **2/10** | Restore-ul e non-atomic (extract in-place peste `ABSPATH`), safety-backup-ul pre-restore e doar de bază de date chiar și la full restore, nu există lock per-site (două restore-uri concurente posibile), iar un restore ucis de deploy/OOM lasă site-ul hibrid și blocat „in_progress" pe viață, fără alertă. Pipeline-ul „safe" (backup→update→health check→rollback) e cod mort — fluxul real din UI nu-l folosește. Zero teste pe restore/push. |
| **Test coverage** | **1/10** | 23 fișiere de test (152 teste) pentru ~400 clase; suita anterioară (~95 fișiere, auth/Livewire/controllers/RestoreBackupTest) a fost **ștearsă integral** în aprilie 2026 și reconstruită ~20%. Zero teste pe HTTP/rute/Livewire/auth/connector. Suita nu e rulabilă în niciun mod documentat (host fără PHP, imagine prod fără dev-deps) și e posibil să fie deja roșie. Fără CI. |
| **Calitate cod** | **4/10** | Structură de servicii rezonabilă și convenții în general respectate (strict_types, enums, DTOs), dar job-uri god-object (`CreateBackup.php` 1.195 linii, `RestoreBackup.php` 876), baseline PHPStan de 121 erori care ascunde bug-uri reale de runtime (variabilă nedefinită, `ArgumentCountError`), și un canal HTTP paralel către connector cu header inexistent. |
| **Observabilitate** | **2/10** | Eșecuri silențioase peste tot: backup-urile se opresc la disc plin cu doar un `Log::warning`, scanările de securitate îngheață scorul fără alertă, alerta „Horizon e jos" e trimisă **prin coada procesată de Horizon**, notificările eșuate nu escaladează. Nu există heartbeat extern („cine păzește paznicul") și nici audit trail pe operațiile distructive (restore/push nelogate, `user_id` pierdut în job-uri). |
| **Arhitectură** | **5/10** | Strat de servicii coerent în mare parte, `WordPressApiServiceFactory` bun ca model, separare Livewire/Services respectată. Dar: god objects în Jobs, trait-uri Livewire cu un singur consumator și 400–600 linii, pipeline-uri de backup duplicate intern cu serviciile existente, și un al doilea client HTTP către WP care ocolește semnătura HMAC. |

---

## Top 10 constatări (ponderate pe blast radius către clienți)

| # | Sev | Modul | Titlu | Fișiere cheie | Remediere (rezumat) |
|---|-----|-------|-------|---------------|---------------------|
| 1 | **P0** | Securitate / Backups | Restore fără nicio autorizare + IDOR cross-tenant — orice user (inclusiv Viewer) poate suprascrie site-ul live al oricărui client | `RestoreConfirmation.php:57,183,203` · `WithBackupActions.php:181` | `mount()` cu `authorizeSiteModification` + `Backup::findOrFail` scopat la `$this->site` + policy pe `restore()`/`restoreAnyway()` |
| 2 | **P0** | Plugin Mgmt | Fluxul real de update din UI n-are backup/health-check/rollback și rulează sincron; `RunSafeUpdate` (plasa de siguranță) e cod mort | `UpdatesOverview.php:136-297` · `WithPluginManagement.php:18-110` · `RunSafeUpdate` (nedispatch-uit) | Rutează toate update-urile prin `RunSafeUpdate` pe coadă (backup sincron → update → health check → auto-rollback) |
| 3 | **P0** | Backups | Restore non-atomic, fără rollback: extract in-place peste `ABSPATH`, safety-backup doar DB — un restore mort la mijloc corupe ireversibil site-ul | `RestoreConfirmation.php:244` · `class-backup-endpoint.php:2477,2484` · `RestoreBackup.php:619` | Restore în director temporar + swap atomic; safety-backup full obligatoriu; import SQL în tranzacție |
| 4 | **P0** | Backups | Fără lock per-site: două restore-uri (sau restore + backup programat) rulează simultan pe același site live | `RestoreBackup.php:51` (`uniqueId` per-backup) · `BackupDispatcher.php:45` | `uniqueId` per-site pentru toată clasa backup/restore + guard în dispatcher pe `restore_status` |
| 5 | **P0** | Cozi / Infra | Restore ucis de deploy/OOM rămâne „in_progress" pe viață, fără recuperare și fără alertă (`stop_grace 300s` vs `timeout 3600s`) | `docker-compose.prod.yml:63-65` · `RestoreBackup.php:32-34` · `deploy.sh:43-57` | Drain real Horizon + `stop_grace ≥ timeout`; `failed()` + `uniqueFor` pe `RestoreBackup`; detecție stuck-restore în dispatcher |
| 6 | **P0** | Plugin Mgmt | `SafeUpdateService` trimite slug în loc de plugin-file și ignoră rezultatul — remedieri de securitate raportate fals ca reușite | `SafeUpdateService.php:62-88` (`success=>true` hardcodat) · `IncidentActionExecutor.php:185-195` | Trimite plugin-file corect + verifică `$updateResult` înainte de `success=true`; propagă eșecul în incident |
| 7 | **P1** (sistemic) | App-wide | Rolul Viewer poate executa operații distructive pe site-uri live în ~8 module (plugins, teme, useri WP, DB cleanup, Cloudflare purge, uptime, SEO, performance) | `WithSiteAuthorization.php:25` (apelat doar în mount, nu pe metode) · zeci de call-site-uri | Aplică `authorizeSiteModification()` pe fiecare metodă mutantă; centralizează prin policies |
| 8 | **P1** (funcțional P0) | Security IR | Toată calea de incident-response crapă la runtime — query pe coloane inexistente (`status`, `cvss_score`) | `IncidentResponseDispatcher.php:67` · `ContextGatherer.php:94,103` | Corectează coloanele (`is_fixed`/`is_ignored`, `software_slug`); adaugă test de schemă |
| 9 | **P1** | Infra | Backup-ul propriu al managerului e rupt și netestat: `$config` nedefinit + exit-code `pg_dump` mascat de `gzip` + fără offsite | `AppBackupCreator.php:151-154` · `DatabaseDumpCommand.php:47-63` | Fix `$config`, verifică exit-code + dimensiune, upload offsite S3, restore-test lunar |
| 10 | **P1** | Notificări / Cozi | Platforma poate deveni oarbă: alerta „Horizon jos" trece prin coada procesată de Horizon; notificările eșuate nu escaladează | `HorizonHealthCheckCommand.php:28` · `SendNotificationJob.php:97` · `ProcessNotificationEscalations.php:46` | Heartbeat extern (healthchecks.io) + canal sincron pentru meta-alerte + escaladare pe status='failed' |

**Restul:** 0 alte P0, ~46 alte P1, ~135 P2, ~111 P3 — detaliate în rapoartele de modul. Mențiuni notabile: link public de raport expune permanent vulnerabilități + PII (`R-P1-3`); monitorizarea de performanță **nu rulează deloc** din refactor (`PF-P1-1`); feature-ul „SEO Fix" n-a funcționat niciodată (header auth inexistent, `S-P1-1`/`ARH-01`); `sites.health_score` nu e scris niciodată, deci filtrele/sortarea/API-ul de health rulează pe NULL (`D-P1-1`).

---

## Teme transversale (cauze-rădăcină)

1. **Autorizare declarativă doar la citire.** Convenția `authorizeSiteAccess()` în `mount()` protejează *vederea*, dar metodele mutante nu re-verifică rolul. Unele module o fac corect (`Updates`, `Uptime`, `Backups` globale), altele nu (detaliu-site: plugins, security, SEO, performance, Cloudflare) — divergență accidentală, nu politică. **Fix structural:** policies aplicate uniform pe toate acțiunile distructive.
2. **Plase de siguranță proiectate, dar deconectate.** `RunSafeUpdate`, `BackupPolicy`, `PrecacheBackupFileList`, editorul de performance budgets — toate există și sunt cod mort. Valoarea e deja construită; lipsește firul care le conectează la flux.
3. **Eșec silențios ca normă.** Aproape fiecare cale critică (backup, scan securitate, performanță, rapoarte, notificări, scheduler) se poate opri fără să alerteze pe nimeni. Nu există un heartbeat extern al pipeline-ului.
4. **Zero rețea de siguranță automată.** Fără CI, fără teste pe straturile distructive, suita ștearsă recent — orice regresie ajunge direct pe serverul care administrează site-urile clienților.

---

## Roadmap de remediere (3 niveluri)

### Nivel 1 — Stop-the-bleeding (P0, zile)
Ordinea = risc descrescător de coruptere a unui site live de client.

1. **Autorizare pe restore + ștergere backup + toate acțiunile distructive de detaliu-site.** Adaugă `mount()` cu `authorizeSiteModification` și scopează `openModal(backupId)` la site; aplică `BackupPolicy` existentă. (Top #1, #7) — *1–2 zile.*
2. **Rutează update-urile de plugin/temă prin `RunSafeUpdate` pe coadă** (backup sincron real → update → health check → auto-rollback), scoate fluxul sincron din request. (Top #2, #6) — *2–3 zile.*
3. **Fă restore-ul recuperabil:** `failed()` + `uniqueFor` pe `RestoreBackup`, detecție stuck-restore în `BackupDispatcher`, `backup:release-lock` să acopere restore. (Top #4, #5) — *1–2 zile.*
4. **Corectează deploy-ul:** drain real Horizon (poll până la inactiv), `stop_grace_period ≥ 3600s`, restart PgBouncer după DDL. (Top #5) — *~0.5 zi.*
5. **Repară incident-response** (coloane inexistente) și **backup-ul managerului** (`$config`, exit-code pg_dump, offsite). (Top #8, #9) — *1 zi.*

### Nivel 2 — Stabilize (testarea căilor distructive + observabilitate, 2–4 săptămâni)
1. **CI minim + izolarea suitei de test** (phpunit.xml pinuit, Redis izolat, `bin/test` cu pgsql efemer, GitHub Actions cu pint/phpstan/phpunit/composer audit, branch protection). — *1.5–2 zile.*
2. **Restore path end-to-end** cu `FakeWordPressApiService`: arhivă coruptă → abort înainte de a atinge site-ul; eșec la chunk → site scos din maintenance. — *4–5 zile.*
3. **Teste pe plugin push / safe-update + HMAC connector (ambele capete) + token flows publice.** — *4–6 zile.*
4. **Heartbeat extern al pipeline-ului** (monitoring-dispatcher + notificări) și **canal sincron pentru meta-alerte**; alertare pe backup/scan/rapoarte oprite. (Top #10) — *2–3 zile.*
5. **Audit trail complet** pe operațiile distructive (propagă user inițiator în constructorii job-urilor; loghează restore/push/safe-update/delete). — *2–3 zile.*
6. Curăță **categoria A din baseline-ul PHPStan** (bug-uri reale) + completează `WordPressApiServiceInterface`. — *~1 zi.*

### Nivel 3 — Harden (arhitectură & datorie, continuu)
1. Dizolvă job-urile god-object de backup în `Services/Backup/Pipelines/` cu contract comun + teste unitare.
2. Unifică traficul către connector printr-un singur client semnat HMAC (concern `ManagesSeo`), elimină canalul paralel; retrage calea HMAC legacy fără nonce.
3. Centralizează autorizarea distructivă în policies; dizolvă trait-urile Livewire cu un singur consumator în servicii.
4. Rotire de chei connector + coloană `api_key_hash` deterministă pentru lookup-ul agentului (azi `where` pe coloană criptată nu se potrivește niciodată).
5. Politici de retenție și indexare pe tabelele fierbinți (uptime_checks, seo_pages, activity_logs).

---

## Index rapoarte

Toate rapoartele de modul și cross-cutting: [`docs/audit/README.md`](docs/audit/README.md).
Roadmap-ul de produs (feature-uri, benchmark competitiv): [`ROADMAP.md`](ROADMAP.md).
