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
  - **Val C1-b în lucru:** upgrade Laravel 11→12 + C-05 trustProxies pe mecanism nativ L12
  - Rămâne: C1-c MFA TOTP, C2 (proven restore, transport async, capabilități conector, agregare alerte, offsite, e2e)
- [ ] Faza D — Modulul SEO/Audit unificat (D1–D6)
- [ ] Faza E — webp-uploads + integrări bifate
- [ ] Faza F — Șlefuire & datorie

## PR-uri deschise

- #94 setup — **MERGED**
- #96 `docs/faza-a-b` — inventar + raport A + R1–R4 + propuneri + faza-C-plan + acest STATUS
- #97 C-06 pin pgbouncer · #98 C-07 restore-download expiry · #99 C-14 GSC date · #100 C-04 drop orphan tables
- `chore/env-example-runbook` — C-03 runbook (așteaptă `.env.example`)

## Pasul următor

C1-b: upgrade Laravel 11→12 (branch `chore/laravel-12-upgrade`), include C-05 trustProxies nativ +
test de regresie config:cache. Apoi C1-c MFA, C2. Clona metodologiei: `../simplead-audit` (@ 9aeb9f4).
Migrarea C-04 la deploy: pg_dump ÎNAINTE + restart pgbouncer după (DDL).

## Note pentru sesiunea următoare (dacă se reia din context pierdut)

- Citește: promptul-program + acest fișier + `config.md`.
- Explorarea inițială a confirmat lista de probleme cunoscute cu nuanțe: ~9–10 tabele orfane (nu 14);
  `SiteSeoAudit` e componentă Livewire (787 linii), nu Job; trustProxies pe `env()` e revert
  DELIBERAT (P3-34, hotfix d7e26a3 — `config()` a picat prod; fix-ul real vine probabil cu L12).
- Conector 2.17.1: scrie deja chei Yoast/RankMath/AIOSEO (`class-seo-endpoint.php`); NU are
  handshake de capabilități (doar `plugin_version` în `/info` → `sites.connector_version`); NU are
  acțiune „install plugin from wordpress.org slug" (necesară la E1).
- Nu există PHP pe host — quality gates se rulează prin docker (vezi memoria proiectului / CI).
