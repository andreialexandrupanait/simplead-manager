# R4 — Metodologia de audit (simplead-audit → SimpleAd Manager)

Sursă: `/var/www/simplead-audit` @ `9aeb9f4` (Next.js 16 + Prisma + worker propriu, prod `audit.simplead.ro`).
Țintă: modul unificat SEO/Audit în Manager (Laravel 11 + PostgreSQL + Horizon), care ÎNLOCUIEȘTE vechiul modul SEO (`app/Services/SeoAudit/`, `app/Livewire/Seo/`) și aplicația standalone.

---

## 1. Inventarul metodologiei (v2)

Sursa canonică mașină-lizibilă: **`/var/www/simplead-audit/methodology-v2/checks.js`** (obiectul `CHECKS_V2`, version 2.0, updated 2026-07). Conținut narativ: `METODOLOGIE.md` (58 KB), `STRUCTURA-RAPORT.md`, `exemplu-sectiune.md` — același folder.

**Numărătoare CONFIRMATĂ prin execuție (node): 82 verificări, 5 secțiuni:**

| Secțiune | key | Verificări | Substructură |
|---|---|---|---|
| 02 SEO on-site | `seo-onsite` | **44** | 12 subsecțiuni: 2.1 URL-uri (4), 2.2 Crawling/indexabilitate (6), 2.3 H1 (4), 2.4 H2 (3), 2.5 H3-CTA (1), 2.6 Internal linking (4), 2.7 Meta title/descriere (5), 2.8 FAQ+Schema (2), 2.9 Breadcrumbs (3), 2.10 Conținut (5), 2.11 Schema per tip pagină (4), 2.12 Paginare/duplicate (3) |
| 03 Recomandări tehnice | `tehnic` | **10** | fără subsecțiuni (3.1–3.10) |
| 04 SEO off-site | `seo-offsite` | **5** | 4.1–4.5 |
| 05 CRO | `cro` | **13** | 5.1–5.13 (5.10–5.13 `applicability: "ecommerce"`) |
| 06 LLM / AEO / GEO | `llm-aeo-geo` | **10** | 6.1–6.10 |

**Structura unui check** (chei din checks.js): `id` (ex. „2.1.1"), `question` (întrebare binară), `source`/`sources` (obiecte `{type, tab, filters, columns, report, target, note}`), `team` (DEV/CONTINUT/MARKETING), `lenses` `{seo, ai, users}` (cele 3 lentile narative), `recommendationTemplate` (șablon cu placeholder-e `[URL]`/`[KEYWORD]`…), opțional `applicability`.

**Distribuția tipurilor de sursă** (un check poate avea mai multe): `sf_export` 44, `manual` 39, `fetch` 16, `sf_report` 3, `web` 3, `psi` 2, `gsc` 2, `sf_bulk_export` 2, `ga4` 1, `bing` 1.

**Decizii încuiate (respectate peste tot în cod):** stări verificare `EXISTA / NU_EXISTA / NU_SE_APLICA` (+ `null` = nesetat/manual); stări recomandare `IMPLEMENTAT / NEIMPLEMENTAT`; **singura agregare permisă: „X din Y recomandări implementate"** — zero scoruri, zero ponderi. Prioritizare doar calitativă: impact/efort ∈ {mare, mediu, mic}.

## 2. Evaluatoarele deterministe

Locație: **`src/lib/evaluation/v2/`** — `evaluators.ts` (SF + PSI), `exports.ts` (registrul exporturilor + rezoluția fișierelor), `csv.ts` (parser CSV cu plafon de rânduri; `DEFAULT_PARSE_ROW_LIMIT`, bulk 5.000), `fetch-checks.ts` (verificări prin fetch direct), `index.ts` (orchestratorul `evaluateV2Audit` — întoarce `{state, evidence}` pentru TOATE cele 82).

**Semantica fundamentală (de portat identic):** crawl-ul rulează cu `--skip-empty` → un filtru SF gol NU produce fișier; pentru etichetele CERUTE la crawl, **fișier absent = filtru gol = dovadă POZITIVĂ (EXISTA)**. Excepții cu precondiții (Structured Data → extraction/validation; Sitemaps → Crawl Linked XML Sitemaps + Crawl Analysis): evaluatorii folosesc fișiere-santinelă (ex. `eval2114`: dacă nici `Contains Structured Data` nici `Missing` nu există, nu se declară verdict). Motorul generic `combineFilters(failLabels, options)`: toate filtrele goale → EXISTA; oricare populat → NU_EXISTA + lista URL-urilor afectate în `evidence.affected` (plafon `MAX_EVIDENCE_URLS = 500`, flag `truncated`), opțiuni `indexableOnly` (rândurile ne-Indexable nu pică verificarea), `infoLabels` (doar dovadă), `extra` (coloane suplimentare per rând).

**Evaluatoare speciale (logică de portat 1:1 în PHP):**
- `eval213` (2.1.3): pică doar URL-urile cu parametri rămase indexabile (necanonicalizate).
- `eval225` (2.2.5): din `Internal:All` — indexabile cu status ≠ 200.
- `eval226` (2.2.6): orfane = HTML 200 cu `Unique Inlinks` = 0.
- `eval275` (2.7.5): regex `^pagina\s+\d+\s*[-–—]` pe titlurile paginilor 2+ (încrucișare `Pagination:Paginated 2+ Pages` ↔ `Internal:All`).
- `eval2121` (2.12.1): NU_SE_APLICA dacă nu există paginare; altfel `Pagination:Non-Indexable`.
- 3.2 (HTTP/mixed content), 3.3 (redirect chains/loops), 3.10 (5 headere Security + `homepageHeaders` din fetch).
- **PSI 3.5/3.6** (`evalPsi35/36`): praguri documentate `PSI_IMG_NEGLIJABIL_OCTETI=10 KiB`, `PSI_IMG_SEMNIFICATIV_OCTETI=50 KiB`, `PSI_LCP_BUN_MS=2500`; zona gri → `state=null` (conservator); PSI mobil median-of-3; PSI indisponibil → null + notă, nu pică jobul.
- **Fetch-based** (`fetch-checks.ts`): 6.1 robots.txt permite cei **8 UA AI** (`GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-User, PerplexityBot, Google-Extended, bingbot` — `AI_USER_AGENTS_V2` + stringurile UA complete `AI_UA_STRINGS`); 6.2 request GET real per UA (blocat = status ∈ {401,403,406,418,429,451,503}); 6.5 llms.txt există și e plauzibil; 3.1 gazdă canonică unică, un singur 301 pe cele 4 variante http/https×www; 3.8 probă 404 pe URL garantat inexistent. Eroare de rețea → `state=null` cu eroarea în evidence (niciodată verdict fabricat).
- **Applicability e-commerce**: 5.10–5.13 → automat NU_SE_APLICA pe profil ≠ ECOMMERCE.
- Restul (~28 chei) primesc `state=null` + evidence de context (eșantioane H1/title/scheme din SF) — le decide auditorul sau evaluarea AI (§3).

**Ce se portează:** logica se REIMPLEMENTEAZĂ în PHP (e filtrare de CSV, simplă), dar **constantele, pragurile, regex-urile, listele de filtre per check și textele notelor din evidence se copiază verbatim**. Atenție deosebită la rezoluția numelor de fișiere (`exports.ts`): normalizare lowercase+underscore, potrivire „fuzzy" pentru praguri numerice (`Over X Characters` → `page_titles_over_60_characters.csv`), cratimele SF le ELIMINĂ, nu le înlocuiește (`security_missing_contentsecuritypolicy_header.csv` — confirmat pe crawl real). Colectorii HTTP din `src/lib/collectors/` (robots, redirects, llmstxt, headers, psi, page-content) au teste + fixtures — de folosit ca specificație la reimplementare.

## 3. Prompturile AI — locații EXACTE + garanția anti-fabricare (port VERBATIM)

Model: `claude-sonnet-5` (`src/lib/ai/client.ts`, `DRAFT_MODEL`); cost: $2/$10 per Mtok (`src/lib/ai/cost.ts`). Ambele apeluri: STREAMING + `finalMessage()` (non-streaming producea hang-uri — commit 9aeb9f4), `thinking: disabled`, system prompt cu `cache_control: ephemeral`, `tool_choice` forțat pe unealtă strictă, un retry la `max_tokens`.

**(a) Evaluarea stărilor calitative** — `src/lib/ai/evaluate-v2.ts`:
- `EVAL_V2_SYSTEM_PROMPT` (liniile 100–114) — **de copiat verbatim**. Miez: „*Evaluezi DOAR pe baza conținutului furnizat… NU inventa, NU presupune… Fiecare verdict (`dovada`) CITEAZĂ EXACT ce ai văzut în conținut… Dacă nu poți determina din conținutul disponibil → stare NECUNOSCUT… NU forța EXISTA/NU_EXISTA când nu ai dovadă.*"
- Unealta `evalueaza_verificarile` (strict:true): `checkKey` e **enum restrâns la cheile calitative ale modulului** (structural nu poate atinge altceva); stări `EXISTA/NU_EXISTA/NU_SE_APLICA/NECUNOSCUT`.
- **Garanția structurală în cod** (`mapEvalV2Output`): NECUNOSCUT → `state=null` + `deVerificat=true`; **un verdict cu `dovada` < `MIN_DOVADA_LEN=8` caractere e RETROGRADAT la null + de verificat** („fără citat = fără verdict"). `EVAL_V2_MAX_TOKENS=8000`, `PAGE_JSON_MAX_CHARS=3500`.
- `EXTERNAL_ONLY_KEYS` = {4.1, 4.4, 4.5, 5.9, 6.4, 6.10} (`src/lib/jobs/evaluate-v2.ts`) — cer cont extern/uman și **nu se trimit deloc la AI** „ca să nu fie tentat să inventeze".

**(b) Draftul recomandărilor (carduri)** — `src/lib/ai/draft-v2.ts`:
- `DRAFT_V2_SYSTEM_PROMPT` (liniile 97–113) — **de copiat verbatim**. Miez anti-fabricare: „*NU inventa NIMIC. Fiecare rând din tabelul per-URL trebuie să pornească de la un URL REAL din lista `urlActuale`… Dacă o verificare acoperită nu are URL-uri concrete… NU construi tabel… setează deVerificat=true. Valorile ACTUALE vin exclusiv din dovezi.*"
- Unealta `propune_recomandari` (strict): `checkIds` e enum al cheilor **NU_EXISTA** ale modulului — modelul nu poate structural raporta pe o verificare EXISTA/NU_SE_APLICA; zero goluri → **zero apel API**.
- Garanții în cod (`mapDraftV2Output`): tabelul se **ELIMINĂ** dacă evidence-ul nu conține URL-uri reale (`extractEvidenceUrls` — doar `evidence.affected[].url`), + callout „De verificat manual" + `needsVerification=true`. `MAX_RECS_PER_MODULE=8`, `MAX_EVIDENCE_URLS_PER_CHECK=60`, `DRAFT_V2_MAX_TOKENS=20000`.
- Garanția „niciun gol fără rezolvare": `ensureEveryGapCovered`/`buildFallbackFindings` — golurile neacoperite de AI primesc card de rezervă din `recommendationTemplate`, mereu `needsVerification=true`.

**(c) Auto-aprobarea** — `src/lib/methodology/auto-approve.ts`: se auto-aprobă DOAR cardurile cu `needsVerification=false` ale căror verificări au TOATE sursele deterministe (`DETERMINISTIC_SOURCE_TYPES` = {sf_export, sf_report, sf_bulk_export, fetch, psi}); **judecata AI (manual/web/ai/gsc/ga4/bing) NU se auto-aprobă NICIODATĂ**.

**Incidentul din 12.07.2026** e documentat în `/var/www/simplead-audit/AGENTS.md`: „*NU fabricați date pe audituri reale… Încălcarea acestei reguli a produs un raport cu informații false către client (12.07.2026 — constatarea «sitemap gol»)*". Toată arhitectura de mai sus e răspunsul la el — se portează integral, prompturi + gardurile structurale din cod.

## 4. Know-how Screaming Frog 24.3

Docs: `methodology-v2/screaming-frog/{config-crawl.md, flux-cli.md, flux-manual.md, mapare-export-verificari.md}` — **de copiat ca atare în Manager** (docs/audit sau storage).

- Binar Linux: `screamingfrogseospider`. Licență: `~/.ScreamingFrogSEOSpider/licence.txt` (2 linii: user + cheie), **EULA headless: `eula.accepted=15`** în `spider.config` (fără ea iese imediat). RAM limitat: `-Xmx2g` în `~/.screamingfrogseospider` + **Database Storage Mode** obligatoriu. Viteză: max **1 URL/sec** (politică — nu degradăm site-urile clienților). UA implicit „Screaming Frog SEO Spider", nu se maschează fără acordul clientului.
- Comanda de producție (`buildSfArgs`, `src/lib/jobs/sf-crawl.ts`): `--crawl <url> --headless --output-folder <dir> --overwrite --save-crawl --export-format csv --export-tabs <57> --bulk-export <2> --save-report <5> --skip-empty`. **FĂRĂ `--config` și fără flag-urile `--use-*`** în jobul automat — configurația implicită e validată pe SF 24.3 (12.07.2026). Timeout **30 min → SIGKILL**; **un singur crawl SF simultan** (guard pe joburi RUNNING).
- Exporturile: **57 `Tab:Filtru`** (`EXPORT_TABS` în `exports.ts`, verbatim din flux-cli.md — Internal, Response Codes, Page Titles, Meta Description, H1/H2, Images, Canonicals, Pagination, Directives, Security, URL, Links, Structured Data, Sitemaps) + **2 bulk** (`Links:All Inlinks`, `Images:Images Missing Alt Text Inlinks`) + **5 rapoarte** (`Crawl Overview`, `Redirects:Redirect Chains`, `Canonicals:Canonical Chains`, `Canonicals:Non-Indexable Canonicals`, `Orphan Pages`). Total ~64 fișiere posibile; sub `--skip-empty` apar doar cele populate.
- Gotcha-uri validate: filtrele cu prag apar în CLI cu „X" dar fișierul iese cu pragul efectiv; Crawl Analysis **nu are flag CLI** — rulează automat la final în headless când exporturile o cer; `--use-google-search-console`/`--use-google-analytics-4` există pentru fluxul manual asistat (OAuth autorizat o dată din UI, același user de sistem). Al doilea crawl cu randare JS (config separat) pentru 6.3 — neautomatizat (verificare rămâne null).
- Scripts utile ca referință: `scripts/smoke-sf-crawl-c2.ts`, `smoke-validare-c3.ts` (smoke-uri), `worker/index.ts` (bucla worker — la noi o înlocuiește Horizon).

## 5. Maparea Prisma → tabele Laravel propuse

Schema: `/var/www/simplead-audit/prisma/schema.prisma` (4 migrații: init, metodologia_v2, report_implemented_state, finding_auto_approved).

| Prisma | Laravel (propus) | Note |
|---|---|---|
| `Client` (name, domain, profile B2B_SERVICII/ECOMMERCE/LOCAL, contact*, notes) | **`prospects`** | Manager are deja `Site.is_prospect` și `Client` (facturare) — `prospects` e entitate nouă de audit; nu se confundă cu `clients` existent. |
| `Audit` (clientId, type, status, url, contextNotes, createdById) | **`audits`** | `site_id` NULLABLE (FK sites) **SAU** `prospect_id` NULLABLE — exact una setată (constraint CHECK). Status enum PHP: CONFIGURAT→COLECTARE→DRAFT→IN_VALIDARE→VALIDAT→PUBLICAT. `methodologyVersion` dispare (portăm doar V2). |
| `Module` + `Check` (seed v2: key „2.1.1", question, sourcesJson, team, lensesJson, recommendationTemplate, subsection*, applicability) | **`audit_checks`** (seed din checks.js portat) | Secțiunea devine coloană (`section_key`, `section_nr`); `sources`, `lenses` → `jsonb`. Seeder = port al `prisma/seed-v2.ts` + `methodology-v2/checks.js`. |
| `ModuleInstance` | — (se elimină) | Exista doar pentru ponderile v1; v2 o folosea neutru (weight=1). Rezultatele se leagă direct audit↔check. |
| `CheckResult` (state, evidence, collectedAt) | **`audit_check_results`** | `audit_id`+`audit_check_id` unique; `state` enum nullable; `evidence` **jsonb**; + `state_set_by` (auto/ai/manual — azi e în evidence `stareSetataDe`). Câmpurile v1 `type`/`value` NU se portează. |
| `Finding` (title, team, impact, effort, recommendation, evidenceText, checkIds[], payload, validation, implementation, needsVerification, autoApproved, sortOrder) | **`audit_cards`** | `check_ids` jsonb; `payload` jsonb `{table, codeBlocks, callouts, mockup}` (validare = port al `findingPayloadV2Schema` din `src/lib/schemas.ts`); enums validation DRAFT_AI/APROBAT/EDITAT/RESPINS, implementation IMPLEMENTAT/NEIMPLEMENTAT. `severity` (doar oglindă a impactului în v2) se poate elimina. |
| `Report` (slug unique, accessToken, tokenRequired, version, html, implementedState, publishedAt) | **`audit_reports`** | Snapshot HTML complet la publicare; `implemented_state` jsonb; republicarea reține slug+token și incrementează version (link stabil). |
| `Job` (coadă proprie, polling 2,5 s) | — Horizon | Joburile devin joburi Laravel; progres/log/tokeni per audit → coloane pe `audits` sau tabel `audit_runs` (după pattern-ul JobTracker existent). |
| `User`/`Session` | — auth Manager existent | Nu se portează. |
| `ScoreSnapshot`, `GeoPrompt`, `GeoSnapshot` | NU se portează | Scoruri = v1 (mort); Geo = schelet fără UI („amânate" în schema însăși). |

## 6. Mecanismul raport public + plan de redirect

- **Servire**: `GET /{slug}` (`src/app/[slug]/route.ts`) servește snapshot-ul HTML; `tokenRequired` → `?t=<token hex 64>`; comparație în **timp constant** (`safeTokenEqual`, `src/lib/report/token.ts`); slug inexistent ȘI token greșit → **același 404** (nu se dezvăluie existența); headere `X-Robots-Tag: noindex, nofollow` + `Cache-Control: no-store`; **tokenul nu se loghează niciodată** (regulă critică).
- **Toggle client**: `POST /api/r/{slug}/toggle?t=<token>` body `{recId, implemented}` — token obligatoriu la scriere indiferent de tokenRequired; **rate-limit 60 req/min per slug** (429); plafon 1000 chei; starea persistă în `Report.implementedState` și e injectată în HTML la servire (`impl-state.ts`).
- **Slug**: derivat din domeniu (`baseSlugFromDomain`: „https://Select-Soft.ro/x" → „select-soft-ro"), sufix -2/-3 la coliziune, listă `RESERVED_SLUGS`. **Republicarea păstrează slug + token + toggle-uri** (`publish-core.ts`).
- **Plan redirect (linkurile sunt LA CLIENȚI — trebuie să meargă în continuare):**
  1. Migrează rândurile `Report` cu slug/token/tokenRequired/implementedState/html **identice** în `audit_reports`.
  2. În Manager: rută publică `GET /r/{slug}` (recomandat prefix `/r/` ca să nu se bată cu rutele existente) cu aceeași semantică 404-uniform + token constant-time + endpoint toggle.
  3. Pe `audit.simplead.ro`: vhost nginx minimal cu `return 301 https://manager.simplead.ro/r/$request_uri_slug$is_args$args` — **păstrând query string-ul** (tokenul e în `?t=`); excludem `/login`, `/audituri` etc. (pot merge pe 301 către manager root). Redirectul rămâne permanent cât timp există linkuri trimise.

## 7. Ce NU se portează + producție

**Mort / de ignorat:** `wgood.h` + `wgood.out` (dump-uri de debugging HTTP din 12.07 — confirmat: headere + payload RSC); toată **metodologia v1** (`src/lib/scoring/`, `src/lib/methodology/weights.ts`, `src/lib/ai/draft.ts` — jobul draft v1 — dar **`truncateEvidenceJson` din el se portează**, e folosit de v2; `src/lib/jobs/collect.ts`; `prisma/data/methodology.js`; `seed.ts` v1; enums `CheckResultType`/`Severity`/ponderi); `ScoreSnapshot`; `GeoPrompt/GeoSnapshot`; rutele `/dev/*`; scripts one-off (`recreate-selectsoft.ts`, `cleanup-sf-smoke-c2.ts`, `validate-selectsoft.ts`); README boilerplate Next.js.

**Producție/date:** repo-ul NU conține nimic despre backupul DB (cron 3:17 pe rudolf / 11 MB — informație operațională din afara repo-ului; de verificat pe host la migrare). Relevante pentru migrare: datasource `postgresql`, 4 migrații (schema finală de mai sus), volumele mici (DB ~11 MB) → migrarea de date se face cu un script Laravel (sau `pg_dump` + comandă artisan de import) care mapează: Client→prospects, Audit→audits, Check(version=2)→audit_checks (match pe `key`), CheckResult→audit_check_results, Finding→audit_cards, Report→audit_reports (slug/token/implementedState BIT-identice). Crawl-urile de pe disc (`/var/www/audit/crawls/<auditId>`) se pot arhiva; evidence-ul e deja în DB.

## 8. Plan de port concret (Faza D)

**Entități noi:** `prospects`, `audits`, `audit_checks` (seed 82), `audit_check_results`, `audit_cards`, `audit_reports` (toate `jsonb`, nu `json`). Enums PHP în `app/Enums/`: `AuditStatus`, `CheckState`, `CardValidation`, `ImplStatus`, `AuditTeam`, `ImpactLevel`, `ProspectProfile`.

**Servicii (`app/Services/Audit/`):** `SfExportRegistry` (cele 57+2+5 etichete + rezoluția fuzzy a fișierelor), `SfCsvParser`, `DeterministicEvaluatorService` (port §2), `FetchCheckService` (6.1/6.2/6.5/3.1/3.8 — Laravel HTTP client, politeță 1 req/s), `PsiEvaluatorService` (refolosește/extinde `PageSpeedService` existent), `PageContentCollector` (port `page-content.ts` — plafoanele identice), `AiEvaluationService` + `AiDraftService` (prompturile VERBATIM din §3, tool use strict, streaming, retry pe max_tokens, garanțiile structurale portate 1:1), `CardAutoApprovalService`, `ReportAssembler` + `ReportPublisher` (slug/token/republish), `AuditCostTracker` ($2/$10 Mtok).

**Joburi Horizon (coadă `audit`):** lanț `RunSfCrawlJob` (timeout 1800 s, `WithoutOverlapping` global pe SF — un singur crawl, `-Xmx2g`) → `EvaluateChecksAiJob` (paginile reprezentative + un apel/modul) → `DraftCardsAiJob` (un apel/modul cu goluri + fallback-uri + auto-approve). Fiecare job scrie progres per secțiune + consum de tokeni. Regula „regenerarea nu atinge APROBAT/EDITAT/RESPINS umane" se păstrează.

**Se copiază VERBATIM:** `EVAL_V2_SYSTEM_PROMPT`, `DRAFT_V2_SYSTEM_PROMPT`, schemele celor două unelte, listele `EXPORT_TABS`/`BULK_EXPORTS`/`SAVE_REPORTS`, `EXTERNAL_ONLY_KEYS`, `DETERMINISTIC_SOURCE_TYPES`, pragurile PSI, cei 8 UA AI + stringurile lor, notele standard din evidence (`SKIP_EMPTY_NOTE` etc.), conținutul `methodology-v2/` (checks.js → seeder; cele 4 doc-uri SF ca documentație), template-ul HTML al raportului (`src/lib/report/v2/template.ts`).

**Ordinea lucrărilor:**
1. Migrații + enums + modele + seeder `audit_checks` (din checks.js).
2. Registry SF + parser CSV + evaluatoarele deterministe + fetch/PSI (cu testele portate din `*.test.ts` ca specificație).
3. `RunSfCrawlJob` end-to-end (crawl real pe un site propriu, validare ingest 82 rezultate).
4. Serviciile AI (evaluate + draft) cu prompturile verbatim + auto-approve; lanțul complet de joburi.
5. UI Livewire: listă audituri/prospects, wizard nou, editor validare (stări + carduri), progres live + cost.
6. Publicare + rută publică `/r/{slug}` + toggle + rate-limit.
7. Migrarea datelor din DB-ul audit (script), redirect nginx `audit.simplead.ro` → Manager, verificarea TUTUROR linkurilor publicate.
8. Decomisionare: aplicația standalone (după o perioadă de redirect) + vechiul modul SEO din Manager (`app/Services/SeoAudit/`, `app/Livewire/Seo/`) — înlocuit de noul modul.
