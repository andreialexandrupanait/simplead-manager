# STATUS program — corectare completă + modul SEO/Audit unificat

**Ultima actualizare:** 22 iulie 2026
**Promptul-program:** `docs/plan/program-prompt.md` (v1.1) — sursa de adevăr pentru faze, reguli, acceptanță.

## Unde suntem

- [x] Întrebările de la startul sesiunii puse și consemnate în `config.md`
- [x] Pas 0 — setup program (PR #94)
- [x] **Faza A — Fundație & inventar** — `inventar.md` + `raport-faza-A.md` (13/13 puncte confirmate, 3 nuanțe)
- [x] Faza B — research R1–R4 rulat (r1-wpmudev, r2-webp, r3-autopsie-seo, r4-metodologie) + `propuneri.md`
- [ ] **→ STOP Faza B: Andrei bifează lista din `propuneri.md`** (nimic din D/E nebifat nu se construiește)
- [ ] Faza C — Corectare & întărire (C1 securitate, C2 operații critice)
- [ ] Faza D — Modulul SEO/Audit unificat (D1–D6)
- [ ] Faza E — webp-uploads + integrări bifate
- [ ] Faza F — Șlefuire & datorie

## PR-uri deschise

- #94 `chore/program-setup` — config + STATUS + promptul-program
- `docs/faza-a-b` — inventar + raport A + rapoartele R1–R4 + propuneri (stacked pe #94)

## Pasul următor

STOP Faza B: aștept bifele lui Andrei pe `propuneri.md`. După OK: plan detaliat Faza C
(`faza-C-plan.md` cu valurile de PR-uri), începând cu C1 (L12, MFA, .env.example, schema).
Clona metodologiei e la `../simplead-audit` (@ 9aeb9f4).

## Note pentru sesiunea următoare (dacă se reia din context pierdut)

- Citește: promptul-program + acest fișier + `config.md`.
- Explorarea inițială a confirmat lista de probleme cunoscute cu nuanțe: ~9–10 tabele orfane (nu 14);
  `SiteSeoAudit` e componentă Livewire (787 linii), nu Job; trustProxies pe `env()` e revert
  DELIBERAT (P3-34, hotfix d7e26a3 — `config()` a picat prod; fix-ul real vine probabil cu L12).
- Conector 2.17.1: scrie deja chei Yoast/RankMath/AIOSEO (`class-seo-endpoint.php`); NU are
  handshake de capabilități (doar `plugin_version` în `/info` → `sites.connector_version`); NU are
  acțiune „install plugin from wordpress.org slug" (necesară la E1).
- Nu există PHP pe host — quality gates se rulează prin docker (vezi memoria proiectului / CI).
