# STATUS program — corectare completă + modul SEO/Audit unificat

**Ultima actualizare:** 23 iulie 2026
**Promptul-program:** `docs/plan/program-prompt.md` (v1.1) — sursa de adevăr pentru faze, reguli, acceptanță.

---
## ▶ RELUARE (inclusiv pe alt calculator) — CITEȘTE ÎNTÂI

**Fazele A, B, C sunt COMPLETE, în producție și cu audit trecut (VERDICT TRECE).** **FAZA D a PORNIT**
(Andrei: „hai să continuăm și cu faza D"). **D1 GATA** (PR #121, branch `feat/faza-d-plan`): schema +
seed 82 verificări; suita full verde (800 teste). Următorul val: **D2 — Screaming Frog headless pe dasher**.

**Ce citești ca să reiei:** acest fișier + `docs/plan/program-prompt.md` + `docs/plan/propuneri.md`
(scope aprobat) + `docs/plan/r4-metodologie.md` (planul de port al Fazei D) + `docs/plan/raport-faza-C.md` (audit).

**Mediu (fără PHP pe host — tot prin docker):**
- Teste/lint: `docker run --rm --network host -v <repo>:/work -w /work --entrypoint ./vendor/bin/{phpunit|pint|phpstan} simplead-app:latest ...` (test DB = `sam-test-pgsql`/`sam-test-redis` pe 127.0.0.1 via phpunit.xml; PHPStan `--memory-limit=1G`). Suita full ~15–21 min.
- Composer: imaginea `composer:2` cu `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-gd --ignore-platform-req=ext-redis`; **NU** `--ignore-platform-req=php` (a pus symfony v8/PHP 8.4 în lock → CI roșu). `config.platform.php=8.3.32` e pinat; `laravel/pint=1.27.1` pinat.
- Deploy: `./deploy.sh` (git pull + gate CI care AȘTEAPTĂ + `migrate --database=pgsql_direct` + restart pgbouncer + nginx ultimul). pg_dump prod: `docker exec simplead-pgsql sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > out.dump`.
- **tinker LIPSEȘTE în prod.** Rulare ad-hoc: `docker compose -f docker-compose.prod.yml exec -T app php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); ...'`.
- **Faza D precondiție:** cheia Anthropic în `.env` prod (D4 fix-uri AI). Clona metodologiei: `git clone https://github.com/andreialexandrupanait/simplead-audit.git ../simplead-audit` (@ 9aeb9f4). Site piloți: notificarialimente.ro (id 41) + universulsacru.ro (id 23).

**Rămas mărunt (P2/P3 din audit + follow-ups):**
- **P2-1 (deschis):** env vars noi Faza C de adăugat în `.env.example` (hook `protect-env-files.sh` blochează scrierea prin Claude — Andrei le lipește; listă în CHANGELOG / raport-faza-C).
- P2-2 REZOLVAT (#119: reconciliere async înainte de hard-fail).
- 5× P3 în `raport-faza-C.md` (netblocante).
- Faza F backlog: descompunere god-objects (RestoreBackup/CreateBackup — protejate de e2e C-13), reducere phpstan-baseline + level 6, i18n scăpări, multi-locație uptime, upgrade Pint 1.29 + reformat, regenerare `pgsql-schema.sql`, C-08 val 2 (dashboard proof + provizionare sandbox live pe dasher).
- Reconciliere async cross-job-death completă = follow-up când async se activează pe TOATĂ flota (acum doar 2 site test).
---

## Unde suntem

- [x] Întrebările de la startul sesiunii puse și consemnate în `config.md`
- [x] Pas 0 — setup program (PR #94)
- [x] **Faza A — Fundație & inventar** — `inventar.md` + `raport-faza-A.md` (13/13 puncte confirmate, 3 nuanțe)
- [x] Faza B — research R1–R4 + `propuneri.md` → **STOP REZOLVAT**: C+D integral; piloți
  notificarialimente.ro + universulsacru.ro; E1 redefinit (conversie imagini PRIN CONECTOR, la
  cerere, fără plugin extern); E2 = linkuri moarte + IndexNow + scanare fișiere + SSO;
  scoase: Branda-light, Cloudflare geo/WAF (manual de Andrei)
- [x] **Faza C — COMPLETĂ, ÎN PRODUCȚIE, AUDIT TRECUT** (auditor #118: VERDICT TRECE, 0 P0/P1). C1 + C2.
- [ ] **Faza D — modulul SEO/Audit unificat (D1–D6)** ← URMĂTORUL (la STOP-point, așteaptă OK Andrei)
- [ ] Faza E — webp/conversie imagini prin conector + integrări bifate (linkuri moarte, IndexNow, scanare fișiere, SSO)
- [ ] Faza F — șlefuire & datorie
  - **C-09 WAVE 3 FĂCUT (23 iul):** deploy prod C-09 (0 erori, healthy); conector 2.18.0 împins pe
    site-urile test 23 (universulsacru.ro 2.17.1→2.18.0) + 41 (notificarialimente.ro 2.17.0→2.18.0);
    sync → ambele anunță `async_restore=true` + `staged_restore=true`; ambele rămân conectate + homepage 200.
    Async restore e LIVE pe cele 2 piloți (kill-switch `ASYNC_RESTORE_ENABLED` gata). Un restore async
    REAL cap-coadă (invaziv — restaurează un backup) NU a fost declanșat — de făcut pe sandbox/deliberat.
  - **URMĂTORUL PAS DE PROGRAM: subagent AUDITOR pe toată Faza C** (context curat, DOAR criteriile de
    acceptanță C + acces cod) → `docs/plan/raport-faza-C.md` P0–P3 → remediere P0/P1 → STOP → OK Andrei → Faza D.
  - **C1 pe main (MERGED):** #96 docs · #97 C-06 pin pgbouncer · #98 C-07 restore-download expiry ·
    #99 C-14 GSC date · #100 C-04 drop 12 tabele orfane · #103 C-03 runbook + `.env.example` ·
    #101 C-01 **Laravel 12.64** + C-05 trustProxies · #106 C-02 **MFA TOTP**.
  - **C2 cod pur pe main (MERGED, COMPLET):** #104 C-11 agregare furtuni alerte (+ fix bug Redis latent
    `ReliableRedisList::ack`) · #105 C-12 offsite verificat banner · #108 C-13 e2e restore staged/merge
    (plasă pentru Faza F pe RestoreBackup; safe-update deja acoperit de SafeUpdateServiceTest).
  - **Bonus:** `composer audit` pe main = **0 advisories** (L12 a adus deja versiuni patchate
    phpspreadsheet 5.9/jmespath 2.9.2/phpseclib 3.0.55). Lock pin-uit `config.platform.php=8.3.32`
    + `laravel/pint=1.27.1` (ca L12 să nu forțeze symfony v8/PHP 8.4 sau un reformat Pint 1.29).
  - **C2 infra pe main (MERGED):** #110 C-08 proven restore (sandbox WP izolat pe dasher, val 1) ·
    #111 C-10 negociere capabilități conector (**Manager-only** — conectorul expunea deja
    `/backup/capabilities`; sync reîmprospătează + gate staged restore pe capabilitate, refuz explicit,
    fără merge tăcut).
  - **Main HEAD `d317225`, tot verde (785/785).** ⚠️ Deploy: 4 migrări noi (GSC date, drop 12 orphans
    — pg_dump ÎNAINTE, 2FA cols, proven_restores + flag-uri sandbox); restart pgbouncer după DDL.
    Adminii se enrolează la MFA din Settings → Two-Factor.
  - **DEPLOY FĂCUT (22 iul):** tot ce era pe main e în producție — L12 + MFA + C-04 drops + restul.
    pg_dump 334M luat înainte; 4 migrări aplicate; pgbouncer repornit; 0 erori; MFA confirmat live de Andrei.
  - **C-09 (transport async restore) — WAVE 1 MERGED (#113):** conector 2.18.0 cu endpoint-uri
    `/backup/restore-async|execute|status` (model prepare-async: work detașat loopback→cron + transient
    `sam_restore_task_{token}`), capabilitate `async_restore`, `perform_typed_restore()` partajat.
    **Strict aditiv/backward-compatible — conectorul NU e împins pe flotă, Manager încă sincron → 0 impact.**
  - **C-09 WAVE 2 MERGED (#115):** transport async pe Manager — `RestoreBackup` face kick+poll pe
    `/backup/restore-status` când `async_restore` e anunțat (gate C-10) + kill-switch `ASYNC_RESTORE_ENABLED`;
    **reconciliere idempotentă** (token în cache per backup+type; transport failed dar conector `done` → succes,
    niciodată „fișiere noi + DB vechi"); fallback pe sincron dacă conectorul nu poate async. 792/792. **Inert
    în prod până la push conector.**
  - **RĂMÂNE DOAR C-09 wave 3 (op prod live, cu Andrei):** (1) DEPLOY codul C-09 pe prod (Manager async +
    conector 2.18.0 în filesystem — sigur, inert); (2) push conector 2.18.0 pe **DOAR** notificarialimente.ro
    + universulsacru.ro (`connector:update --site=ID`; self-update cu rollback); (3) sync → verifică
    `connector_version=2.18.0` + `backup_capabilities.async_restore=true`. Validarea cap-coadă a unui restore
    async real e invazivă (restaurează un backup) — de făcut pe sandbox sau deliberat.
  - Apoi subagent AUDITOR fază C → remediere → STOP → OK Andrei → Faza D.
  - C-08 val 2 (follow-up): dashboard global proof + validare live pe dasher după provizionarea sandbox-ului.
  - **Follow-up opțional (Faza F):** upgrade Pint 1.27→1.29 + reformat codebase (amânat deliberat).
  - **AUDIT: #118 raport-faza-C.md VERDICT TRECE** (0 P0/P1, 2 P2, 5 P3). P2-1 env deschis (Andrei lipește);
    P2-2 rezolvat (#119 reconciliere async). C-09 wave 3 live: conector 2.18.0 pe site 23+41, `async_restore=true`.

## PR-uri — TOATE MERGE-UITE PE MAIN
#94, #96, #97, #98, #99, #100, #103, #101, #104, #105, #106 — toate în `main` (HEAD `e6df429`).
(#102 s-a auto-închis la merge-ul bazei L12; MFA a re-intrat curat ca #106.)
## Pasul următor (reluare sesiune)
1. **Deploy** când Andrei decide (main e verde): pg_dump ÎNAINTE (C-04 drop tabele), `deploy`,
   migrate (3 migrări noi), restart pgbouncer după DDL. Adminii se enrolează la MFA din Settings → Two-Factor.
2. **C-13 e2e** cu FakeWordPressApiService (cod pur) — de făcut oricând, branch nou de pe main.
3. **C-08/09/10** — cer deciziile de infra ale lui Andrei (sandbox WP pe dasher în compose prod;
   când se poate push conector pe flotă).
4. După C2 complet: subagent AUDITOR fază C → remediere → STOP → OK Andrei → Faza D.
Clona metodologiei pentru Faza D: `../simplead-audit` (@ 9aeb9f4).

## (istoric) Pasul următor — depășit

C1-c MFA TOTP (branch nou stacked pe L12 — pachetul TOTP se alege pe L12). Decizii de design în
așteptare de la Andrei (pachet, strictețe enforcement Admin, acoperire SSO/portal). Apoi C2.
Follow-up mic: bump advisories tranzitive (phpspreadsheet/jmespath/phpseclib).
Clona metodologiei: `../simplead-audit` (@ 9aeb9f4). Migrarea C-04 la deploy: pg_dump ÎNAINTE + restart pgbouncer după (DDL).

## Note pentru sesiunea următoare (dacă se reia din context pierdut)

- Citește: promptul-program + acest fișier + `config.md`.
- Explorarea inițială a confirmat lista de probleme cunoscute cu nuanțe: ~9–10 tabele orfane (nu 14);
  `SiteSeoAudit` e componentă Livewire (787 linii), nu Job; trustProxies pe `env()` e revert
  DELIBERAT (P3-34, hotfix d7e26a3 — `config()` a picat prod; fix-ul real vine probabil cu L12).
- Conector 2.17.1: scrie deja chei Yoast/RankMath/AIOSEO (`class-seo-endpoint.php`); NU are
  handshake de capabilități (doar `plugin_version` în `/info` → `sites.connector_version`); NU are
  acțiune „install plugin from wordpress.org slug" (necesară la E1).
- Nu există PHP pe host — quality gates se rulează prin docker (vezi memoria proiectului / CI).
