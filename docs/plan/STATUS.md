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
- [~] **Faza C — C1 COMPLET + MERGE-UIT PE MAIN; C2 parțial**
  - **C1 pe main (MERGED):** #96 docs · #97 C-06 pin pgbouncer · #98 C-07 restore-download expiry ·
    #99 C-14 GSC date · #100 C-04 drop 12 tabele orfane · #103 C-03 runbook + `.env.example` ·
    #101 C-01 **Laravel 12.64** + C-05 trustProxies · #106 C-02 **MFA TOTP**.
  - **C2 cod pur pe main (MERGED, COMPLET):** #104 C-11 agregare furtuni alerte (+ fix bug Redis latent
    `ReliableRedisList::ack`) · #105 C-12 offsite verificat banner · #108 C-13 e2e restore staged/merge
    (plasă pentru Faza F pe RestoreBackup; safe-update deja acoperit de SafeUpdateServiceTest).
  - **Bonus:** `composer audit` pe main = **0 advisories** (L12 a adus deja versiuni patchate
    phpspreadsheet 5.9/jmespath 2.9.2/phpseclib 3.0.55). Lock pin-uit `config.platform.php=8.3.32`
    + `laravel/pint=1.27.1` (ca L12 să nu forțeze symfony v8/PHP 8.4 sau un reformat Pint 1.29).
  - **Main HEAD `e6df429`, tot verde (768/768).** ⚠️ Deploy: migrarea C-04 (drop tabele) cere pg_dump
    ÎNAINTE; 3 migrări noi (000001 GSC, 000002 drop orphans, 000003 2FA cols); restart pgbouncer după DDL.
  - **Rămâne C2 — DOAR itemii de infra (cer deciziile lui Andrei):** C-08 proven restore (container WP
    sandbox pe dasher în compose prod), C-09 transport async restore + reconciliere, C-10 negociere
    capabilități conector (schimbă conectorul + push pe flotă). Apoi subagent AUDITOR fază C → remediere → STOP → OK Andrei → Faza D.
  - **Follow-up opțional (Faza F):** upgrade Pint 1.27→1.29 + reformat codebase (amânat deliberat).
- [ ] Faza D — Modulul SEO/Audit unificat (D1–D6)
- [ ] Faza E — webp-uploads + integrări bifate
- [ ] Faza F — Șlefuire & datorie

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
