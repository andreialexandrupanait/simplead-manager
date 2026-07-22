# STATUS program — corectare completă + modul SEO/Audit unificat

**Ultima actualizare:** 22 iulie 2026
**Promptul-program:** `docs/plan/program-prompt.md` (v1.1) — sursa de adevăr pentru faze, reguli, acceptanță.

## Unde suntem

- [x] Întrebările de la startul sesiunii puse și consemnate în `config.md`
- [x] Pas 0 — setup program (PR #94)
- [x] **Faza A — Fundație & inventar** — `inventar.md` + `raport-faza-A.md` (13/13 puncte confirmate, 3 nuanțe)
- [x] Faza B — research R1–R4 + `propuneri.md` → **STOP REZOLVAT**: C+D integral; piloți
  notificarialimente.ro + universulsacru.ro; E1 redefinit (conversie imagini PRIN CONECTOR, la
  cerere, fără plugin extern); E2 = linkuri moarte + IndexNow + scanare fișiere + SSO;
  scoase: Branda-light, Cloudflare geo/WAF (manual de Andrei)
- [~] **Faza C — în lucru** (plan: `faza-C-plan.md`)
  - **Val C1-a livrat (PR-uri deschise):** C-06 pin pgbouncer (#97) · C-07 expirare restore-download (#98)
    · C-14 fix decalaj GSC (#99) · C-04 drop 12 tabele orfane (#100) · C-03 runbook (branch
    `chore/env-example-runbook`; **`.env.example` de plasat de Andrei** — hook `protect-env-files.sh`
    blochează scrierea prin Claude; șablon în scratchpad + inline în runbook)
  - **Val C1-b livrat (#101):** Laravel 11.48→12.64 (upgrade curat, 744/744, PHPStan clean, advisories 24→8)
    + C-05 trustProxies (păstrat `env()` la boot — corect acolo; adăugat test de regresie care ar fi prins P3-34)
  - **Val C1-c livrat (#102, stacked pe #101):** C-02 MFA TOTP (pragmarx google2fa + bacon QR) —
    obligatoriu Admin cu grace, challenge la login normal + Google SSO, coduri recovery, activity log.
    Full suite 754/754, gate on authenticated route group (nu global, ca să nu rupă `/livewire/update`).
  - **C1 COMPLET** (C-01…C-07, C-14).
  - **C2 cod pur — parțial livrat:** C-11 agregare furtuni alerte (#104; + fix bug Redis latent
    `ReliableRedisList::ack` lrem arg order) · C-12 offsite verificat banner (#105).
  - **Rămâne C2:** C-13 e2e cu FakeWordPressApiService (backup→verificare→restore staged, safe-update
    rollback, callback-uri) — cod pur, de făcut. C-08/C-09/C-10 (proven restore sandbox pe dasher,
    transport async restore, negociere capabilități conector) — **ating conectorul + infra dasher, cer
    deciziile lui Andrei** (container WP sandbox în compose prod; push conector pe flotă). Apoi AUDITOR fază C + STOP.
  - **Advisory bump (phpspreadsheet 5.6→5.9 / jmespath 2.8→2.9.2 / phpseclib 3.0.50→3.0.55):** de făcut
    **DUPĂ ce #101 (L12) e merge-uit** — pe L11 trage symfony/string v8 într-un mix cross-major și lasă un
    advisory symfony; pe L12 (symfony deja curent) e un `composer update` trivial și curat.
- [ ] Faza D — Modulul SEO/Audit unificat (D1–D6)
- [ ] Faza E — webp-uploads + integrări bifate
- [ ] Faza F — Șlefuire & datorie

## PR-uri deschise

- #94 setup — **MERGED**
- #96 `docs/faza-a-b` — inventar + raport A + R1–R4 + propuneri + faza-C-plan + acest STATUS
- #97 C-06 pin pgbouncer · #98 C-07 restore-download expiry · #99 C-14 GSC date · #100 C-04 drop orphan tables
- #101 C-01/C-05 Laravel 12 upgrade + trusted-proxies test
- #102 C-02 MFA TOTP (stacked pe #101)
- #103 C-03 runbook + `.env.example` (plasat de Andrei)
- #104 C-11 agregare furtuni alerte (+ fix Redis ack)
- #105 C-12 offsite verificat banner

## Pasul următor (reluare sesiune)
1. Andrei revizuiește/merge-uiește backlog-ul de PR-uri (recomandat: #101→#102 stacked; restul independente;
   toate ating `## [Unreleased]` în CHANGELOG → merge pe rând, conflict trivial de listă).
2. C-13 e2e (cod pur) — de făcut oricând.
3. C-08/09/10 — după deciziile de infra ale lui Andrei (sandbox WP pe dasher, push conector flotă).
4. Advisory bump — după merge #101.
Clona metodologiei: `../simplead-audit` (@ 9aeb9f4). Migrarea C-04 la deploy: pg_dump ÎNAINTE + restart pgbouncer.

## Ordine de merge recomandată (pentru CHANGELOG fără conflicte)
Independente de main: #97, #98, #99, #100, #96 (docs), C-03 branch. Stacked: #101 → apoi #102.
Toate ating `## [Unreleased]` în CHANGELOG → merge-uite pe rând, rezolvă conflictul trivial de listă.

## Pasul următor

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
