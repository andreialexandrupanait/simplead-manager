# Plan Faza D — Modulul SEO/Audit unificat (nucleul programului)

Sursa metodologiei: clona `/var/www/simplead-audit` @ `9aeb9f4` (`methodology-v2/checks.js` — 82 verificări,
5 secțiuni: SEO on-site 44 / tehnic 10 / off-site 5 / CRO 13 / LLM-AEO 10). Planul de port detaliat:
`docs/plan/r4-metodologie.md`. Autopsia modulului vechi (ce moare / supraviețuiește): `docs/plan/r3-autopsie-seo.md`.

**Decizii încuiate (din prompt + checks.js):** stări verificare `EXISTA/NU_EXISTA/NU_SE_APLICA`; stări
recomandare `IMPLEMENTAT/NEIMPLEMENTAT`; **singura agregare: „X din Y implementate" — ZERO scoruri, ZERO
ponderi**; audit atașabil unui site conectat SAU unui prospect (site_id XOR prospect_id); prompturile AI se
portează VERBATIM; garanția anti-fabricare e LEGE (verdict fără dovadă citată → respins); modulul SEO vechi
se ÎNLOCUIEȘTE (tranziție pe flag, ștergere doar după paritate). Supraviețuiesc: integrarea GSC, keyword
rankings, brațul bulk-fix/HMAC, `SearchConsoleGatherer`.

## Valuri (PR-uri mici, quality gate verde per PR)

### D1 — Schema + seed (fundația) ← ACEST VAL
- `checks.js` → `database/data/audit-checks-v2.json` (convertit cu node; sursă regenerabilă, nu rescris de mână).
- Migrări: `prospects`, `audits` (site_id XOR prospect_id, CHECK constraint), `audit_checks` (seed 82),
  `audit_check_results`, `audit_cards`, `audit_reports`. Toate `jsonb`.
- Enums PHP: `AuditStatus`, `CheckState` (EXISTA/NU_EXISTA/NU_SE_APLICA/null), `CardValidation`,
  `ImplStatus`, `AuditTeam`, `ImpactLevel`, `ProspectProfile`.
- Modele + relații. Seeder `AuditChecksSeeder` (idempotent upsert pe `key` „2.1.1").
- Teste: seed = 82 verificări, 5 secțiuni (44/10/5/13/10); constraint site XOR prospect; agregare X/Y.

### D2 — Screaming Frog automatizat pe dasher
- Container SF CLI (licență din env server; `eula.accepted=15`, `-Xmx2g`, DB storage, 1 URL/sec).
- Job `RunSfCrawl` pe coadă nouă `audit`, `WithoutOverlapping` global (1 crawl), timeout 30min, retry+alertă.
- Comanda de producție (57 export-tabs + 2 bulk + 5 rapoarte, `--skip-empty`, FĂRĂ `--config`).
- Fallback: UI upload manual exporturi. `IngestCrawl` normalizează ambele surse într-un model comun.

### D3 — Evaluarea
- Evaluatoare deterministe portate în PHP (`src/lib/evaluation/v2/`): semantica `--skip-empty` (fișier absent
  = dovadă pozitivă), santinele precondiții, `combineFilters` (plafon 500 URL evidence), praguri PSI (10/50
  KiB, 2500ms), fetch-checks cu cei 8 UA AI. Constante/regex/filtre copiate VERBATIM.
- `RunAiChecks`: prompturi VERBATIM (`EVAL_V2_SYSTEM_PROMPT`, `DRAFT_V2_SYSTEM_PROMPT`), tool-use strict,
  streaming, garanția anti-fabricare (verdict fără dovadă ≥8 char → retrogradat). Chei external-only nu se
  trimit la AI. PageSpeed din modulul existent; GSC unde e conectat.

### D4 — Soluții SEO cu AI (precondiție: cheie Anthropic în prod)
- `fix_type` per verificare (AI-generabil / tehnic-prin-conector / manual). Generare DOAR din dovezile
  crawl-ului + conținut + GSC, în limba site-ului. Editor de validare umană (aprobare per fix + bulk-select;
  nimic neaplicat nevalidat). „Aplică pe site" → detectare plugin SEO (Yoast/RankMath/AIOSEO/core) → scriere
  prin conector (fundația `class-seo-endpoint.php` există) cu backup + rollback per aplicare → re-crawl →
  auto-marcare „implementat". Buget AI + log costuri (default claude-sonnet).

### D5 — Monitorizare continuă
- Re-audit programat (lunar/la cerere), delta între audituri, alertă la regresii (EXISTA → NU_EXISTA).

### D6 — Raport public + migrare + sunset
- Rută publică `GET /r/{slug}?t=<token>` (constant-time, 404 uniform, noindex/no-store, toggle rate-limit
  60/min, republish păstrează slug+token) + export PDF Gotenberg.
- Migrare DB 11MB din audit.simplead.ro (clienți→prospects, rapoarte publicate bit-identice, ultimul audit v2).
- Redirect nginx `audit.simplead.ro` → Manager (query string păstrat). Sunset modul SEO vechi pe flag global
  unic (după paritate demonstrată). NU porta `wgood.h/out`.

## Final de fază
Subagent AUDITOR (Anexa 1) pe acceptanța D → `docs/plan/raport-faza-D.md` → remediere P0/P1 → STOP → OK Andrei.

## Acceptanță D (pe piloți notificarialimente.ro + universulsacru.ro)
Crawl SF din Manager cap-coadă → 82 verificări cu dovezi → ≥10 fix-uri AI generate → validate uman →
aplicate prin conector în pluginul SEO detectat → rollback demonstrat pe ≥1 → re-crawl auto-marchează
„implementat"; raport public funcțional; link vechi redirecționat; modul SEO vechi oprit pe flag fără pierderi.
