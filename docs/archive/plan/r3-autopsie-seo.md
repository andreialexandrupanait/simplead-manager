# R3 — Autopsia modulului SEO existent

Analiză pe cod (nu pe presupuneri) a modulului SEO din SimpleAd Manager, în contextul deciziei de înlocuire cu modulul unificat SEO/Audit bazat pe metodologia cu 82 de verificări (`/var/www/simplead-audit/methodology-v2/checks.js`).

---

## 1. Ce face azi, de fapt

### Pipeline
`SeoAuditDispatcher` (programat la 5 min, `routes/console.php:96`) selectează `seo_monitors` cu `is_active=true` și `next_audit_at` scadent, cu guard de circuit-breaker și anti-concurență (`app/Dispatchers/SeoAuditDispatcher.php:21-44`), creează un `SeoAudit` și pornește lanțul:

1. **`RunSeoAudit`** (`app/Jobs/RunSeoAudit.php:42-74`) — cere conectorului WP `/seo/analysis` (plugin SEO instalat, search visibility, redirects), apoi înlănțuie:
2. **`CrawlSitePages`** (797 linii) — crawler HTTP propriu
3. **`AnalyzeSeoPages`** (655 linii) — heuristici pe datele crawl-ului
4. **`CalculateSeoScores`** — scor compozit + diff față de auditul precedent + reprogramare monitor

### Ce colectează crawler-ul (CrawlSitePages)
- Fetch secvențial cu `Http::get` + `DOMDocument` (`CrawlSitePages.php:114-118, 379-380`): title, meta description, meta robots, X-Robots-Tag, canonical + self-canonical, hreflang, structura H1–H6, word count, imagini (alt, lazy, src), linkuri interne/externe cu anchor, OG/Twitter tags, viewport, JSON-LD (tipuri + raw), `content_hash` (md5 pe body), dimensiune pagină, „TTFB".
- Sitemap (index + normal, `:685-742`), robots.txt (parsare proprie, `:744-777`), verificare linkuri externe rupte prin HEAD (max 500, `:300-334`), imagini rupte prin HEAD (max 100, `:589-627`).
- Limite: 500 pagini implicit / 2000 hard (`config/seo.php:10`), delay 200ms, buget de crawl 1080s, timeout job 1500s. Marchează `coverage_partial` când coada nu e epuizată (`:279-282`).

### Ce detectează analizorul (AnalyzeSeoPages) — inventarul complet al tipurilor de probleme (~36)
- **On-page:** title lipsă / prea scurt / prea lung / duplicat; meta description lipsă / scurtă / lungă / duplicată; H1 lipsă / multiplu; thin content (<300 cuvinte); conținut duplicat (hash md5 identic).
- **Imagini:** fără alt, imagini rupte, fără lazy loading (>3/pagină).
- **Linkuri:** pagini 4xx/5xx, linkuri interne/externe rupte, pagini orfane (de 2 ori — vezi §2), pagini adânci (depth>3), dead-end (0 linkuri interne ieșite), diluare (>100 linkuri).
- **Indexabilitate:** noindex în sitemap, noindex simplu, canonical mismatch, canonical lipsă, lanțuri de canonical.
- **Tehnic:** URL lung (>115) / cu underscore / cu majuscule; sitemap lipsă / pagini cu erori în sitemap / pagini indexabile lipsă din sitemap; robots.txt lipsă / fără Sitemap: / Disallow: /; hreflang fără x-default / fără self-reference / cu href gol.
- **Date structurate:** lipsă totală, homepage fără schema / fără Organization, fără BreadcrumbList, JSON-LD invalid, câmpuri obligatorii lipsă pentru 12 tipuri de schema (`:450-514`).
- **Social:** OG tags lipsă. **Mobile:** viewport lipsă.
- **Security (nu e SEO):** HSTS/XFO/XCTO/CSP lipsă, SSL expirat/expiră (`:546-619`).

### Scoring (ScoringService + config/seo.php)
Penalizări per severitate (critical 15 / high 8 / medium 3 / low 1 / info 0), per-page mediate pe numărul de pagini, site-wide întregi (`ScoringService.php:23-39`); scorul de performanță amestecat 60/40 cu PageSpeed (`:41-48`); scor global ponderat technical 40 / on_page 30 / performance 20 / other 10 (`config/seo.php:12`).

### Restul modulului
- **UI:** `SeoOverview` (portofoliu + distribuție scoruri + top issues), `SeoQuickAudit` (audit prospect — creează un `Site` real cu `is_prospect=true` per URL, `SeoQuickAudit.php:133-145`), `SiteSeoAudit` (787 linii: tabs issues/pages/links/images/redirects/keywords/history, setări monitor, fix modals, bulk fix, export XLSX).
- **GSC:** `GoogleSearchConsoleService` (14 metode: overview, top queries/pages, țări, device-uri, search appearance, sitemaps, `inspectUrl`, `getExternalLinks`, istorii de poziție) peste `GoogleApiService` (OAuth, `GoogleConnection`); `FetchSearchConsoleData` populează `search_console_cache`; `FetchKeywordRankings` scrie zilnic top 200 queries în `seo_keyword_rankings` cu tracking manual de cuvinte cheie.
- **Bulk-fix:** `ApplySeoBulkFix` — pe issue-title, prin clientul HMAC semnat, către `/seo/update-meta|canonical|og`, cu regula „nu împinge valori goale" (`ApplySeoBulkFix.php:86-99`); fix-uri individuale (meta/robots/canonical/OG/search-visibility) direct din `SiteSeoAudit.php:586-766`.
- **Rapoarte:** `SeoGatherer` (agregat masiv pe ultimul audit) + `SearchConsoleGatherer` (din cache).
- **Retenție:** `seo_audits` completate șterse la 90 zile (`RetentionPolicyService.php:104-112`).

---

## 2. De ce nu satisface — constatări concrete

### Crawler superficial
1. **Zero randare JS** — `Http::get` + `DOMDocument` (`CrawlSitePages.php:114-118, 379-380`). Orice meta/schema/conținut injectat de JS (buildere, teme moderne, cookie-consent care rescrie DOM) e invizibil. Un audit profesional (Screaming Frog cu JS rendering — sursa principală din checks.js) vede altă pagină decât acest crawler.
2. **„TTFB" e de fapt timpul total de descărcare** — cronometrul se oprește după ce `->get()` a citit tot body-ul (`CrawlSitePages.php:113-120`), nu la primul byte. Metrica din UI/rapoarte e mislabeled.
3. **Redirect chains nemăsurate** — `redirect_chain_length` e hardcodat 1 (`CrawlSitePages.php:130`); `config('seo.analysis.max_redirect_chain')` nu e citit nicăieri (grep pe tot `app/`). Tab-ul „redirects" sortează după o coloană care e mereu 1.
4. **robots.txt parsat naiv** — doar grupul `User-agent: *`, matching pe prefix, fără wildcard `*`/`$` (`CrawlSitePages.php:744-796`). În plus crawler-ul **nu respectă** robots (crawlează și paginile blocate, doar le marchează), iar sitemap-ul e citit **înainte** de robots.txt (`:94` vs `:97`), deci URL-urile `Sitemap:` din robots nu sunt folosite niciodată.
5. **Secvențial și lent** — `config('seo.crawler.concurrency' => 3)` e mort (nefolosit); crawl-ul e un `while` cu `usleep(200ms)` per pagină. La 500 pagini × (fetch + delay) bugetul de 1080s se termină și `coverage_partial=true` devine norma pe site-uri medii.
6. **`withoutVerifying()` peste tot** (`:116, 317, 605, 691`) — problemele reale de certificat din lanț sunt mascate chiar în modulul care pretinde că verifică SSL-ul.
7. **`depth` = numărul de segmente din path, nu click-depth** (`CrawlSitePages.php:668-676`). „Deep pages (>3 clicks from homepage)" (`AnalyzeSeoPages.php:353-354`) e o minciună structurală pe orice site cu URL-uri ierarhice.
8. **`word_count` cu `str_word_count`** (`CrawlSitePages.php:504`) — funcție ASCII-only: diacriticele românești (ă, â, î, ș, ț) sparg cuvintele. Pe portofoliul SimpleAd (site-uri RO) „Thin content" e calculat pe date corupte. Numără și nav/footer/cookie-banner, nu conținutul principal.
9. **Duplicate = md5 identic pe tot body-ul** (`:503-507`) — detectează doar clone perfecte; near-duplicates (majoritatea cazurilor reale) trec nedetectate.

### Analiza superficială și cu bug-uri
10. **Paginile orfane sunt raportate de două ori** — `checkLinks` (`AnalyzeSeoPages.php:195-198`) și `checkOrphanPages` (`:516-544`) emit fiecare câte un issue Medium pentru aceleași pagini → penalizare dublă în scor și zgomot în UI.
11. **Coverage față de cele 82 de verificări:** cele ~36 tipuri detectate acoperă parțial doar 2 din cele 5 secțiuni ale metodologiei. **Zero acoperire** pentru: CRO (13 verificări), LLM/AEO/GEO (10 — llms.txt, acces crawleri AI, citabilitate), off-site (5 — backlinks, GBP; ironic, `GoogleSearchConsoleService::getExternalLinks` există dar nu e folosit de audit), calitatea conținutului per tip de pagină, H2/H3 ca structură editorială, FAQ + FAQPage per pagină, paginare/categorii duplicate, URL-uri cu diacritice/non-ASCII (check 2.1.2 — modulul verifică doar underscore/majuscule/lungime, `AnalyzeSeoPages.php:264-280`).
12. **Fără evidence** — un `SeoIssue` e (title, description trunchiată, url, recommendation generică hardcodată). Metodologia cere sursă per verificare (`source: sf_export/gsc/psi/...`), stări `EXISTA/NU_EXISTA/NU_SE_APLICA`, lentile seo/ai/users și template de recomandare cu valorile concrete. Nimic din acestea nu există în modelul de date actual.
13. **„Fix"-urile nu generează nimic** — `ApplySeoBulkFix` doar re-împinge în WP exact valorile scrapuite (`ApplySeoBulkFix.php:88-99`). Pentru „Missing title tag" / „Missing meta description" — issue-urile pentru care e oferit butonul (`SiteSeoAudit.php:454-465`) — pagina nu are valoare, `array_filter` golește payload-ul și pagina e **skipped**: fix-ul emblematic e un no-op exact pe problema pe care pretinde că o rezolvă. Nu există generare de conținut (LLM sau altfel) pentru titluri/descrieri lipsă.

### Scorul compozit — opac și contrazis de metodologie
14. Scorul e o funcție netransparentă: penalizări arbitrare (15/8/3/1) × mediere pe pagini × ponderi 40/30/20/10 × blend 60/40 cu PageSpeed (`ScoringService.php:23-55`) — nimeni nu poate explica unui client de ce are 73. Blend-ul PageSpeed nu verifică vechimea datelor (`pagespeed_max_age_days` din config e mort).
15. **Inconsistență internă:** contoarele afișate (`critical_count` etc.) se calculează pe grupuri unice title+severity (`AnalyzeSeoPages.php:643-647`), dar penalizarea scorului se face pe fiecare rând per-pagină (`ScoringService.php:27-34`) — UI-ul și scorul numără lucruri diferite.
16. Metodologia nouă interzice explicit acest model: „*Singura agregare permisă: «X din Y recomandări implementate». Zero scoruri, zero ponderi*" (`/var/www/simplead-audit/methodology-v2/checks.js:5`). ScoringService nu e „de îmbunătățit" — e conceptual eliminat.

### Igienă
17. Config mort în `config/seo.php`: `crawler.concurrency`, `analysis.max_redirect_chain`, `analysis.large_image_threshold_kb`, `analysis.pagespeed_max_age_days`, `retention_days` (retenția reală e în `RetentionPolicyService`, nu aici).
18. `FetchKeywordRankings` etichetează datele de acum 3 zile (fereastra GSC `now()-3d`, `FetchKeywordRankings.php:52-53`) cu `recorded_date = azi` (`:72,97`) — istoric decalat sistematic cu 3 zile.
19. `SeoQuickAudit` creează un rând `Site` real per prospect (`SeoQuickAudit.php:133`) — poluează tabelul sites și forțează `is_prospect` guards prin tot codebase-ul.

---

## 3. Verdict per componentă

| Componentă | Verdict | Argument |
|---|---|---|
| **Crawler (`CrawlSitePages`)** | **MOARE** | Fără JS rendering, secvențial, robots naiv, metrici greșite (TTFB, depth, redirect chains, word count). Metodologia v2 își ia datele din surse dedicate (`sf_export`, `fetch`, `gsc`, `psi` — checks.js). Nu merită reparat. |
| **Analizoare (`AnalyzeSeoPages`)** | **MOARE** | Heuristici hardcodate, dublări (orfane ×2), fără evidence/stări/lentile; acoperă <40% din on-site și 0% din CRO/LLM/off-site. Câteva idei (validare JSON-LD pe câmpuri obligatorii `:450-514`, hreflang `:420-448`) pot fi re-implementate în checker-ele noi, dar codul moare. |
| **Scoring (`ScoringService` + weights)** | **MOARE** | Interzis explicit de metodologie („zero scoruri, zero ponderi"). Înlocuit de „X din Y recomandări implementate". |
| **`SeoOverview`** | **SE TRANSFORMĂ** | Vederea de portofoliu rămâne necesară, dar toate statisticile sunt score-centrice (`SeoOverview.php:75-129`) → se rescrie pe agregatul X/Y + stări per secțiune. |
| **`SeoQuickAudit`** | **MOARE** (pattern-ul), funcția se re-face | Auditul de prospect e valoros comercial, dar hack-ul Site-per-prospect și pipeline-ul pe care stă dispar; noul modul are nevoie de o entitate proprie de audit-prospect. |
| **`SiteSeoAudit`** | **SE TRANSFORMĂ** | Tabs issues/pages/links/images/redirects + history de scor mor odată cu datele. Supraviețuiesc și migrează: tab-ul keywords (`:258-380`), fix-modals (`:577-766`), setările de programare. |
| **Integrare GSC (`GoogleSearchConsoleService`, `GoogleApiService`, `FetchSearchConsoleData`, cache)** | **SUPRAVIEȚUIEȘTE** | Confirmat de owner. Metodologia are `source.type: "gsc"` — serviciul e chiar o dependență a noului modul; `inspectUrl` și `getExternalLinks` mapează direct pe verificări (indexare, off-site). |
| **Keyword rankings (`FetchKeywordRankings`, `seo_keyword_rankings`)** | **SUPRAVIEȚUIEȘTE** | Istoric acumulat valoros + tracking manual funcțional. De reparat decalajul `recorded_date` (§2.18). |
| **Bulk-fix / push semnat (`ApplySeoBulkFix` + endpoints conector + fix modals)** | **SUPRAVIEȚUIEȘTE** | Confirmat de owner: e brațul de execuție „IMPLEMENTAT" al noului modul (recomandare → aplicare pe WP prin HMAC). Trebuie însă alimentat cu valori **generate** (azi doar re-împinge scraped values — §2.13). |
| **`SeoGatherer` (rapoarte)** | **MOARE** (se rescrie) | Citește exclusiv modelul vechi (score, category_scores, seo_pages/links). Secțiunea SEO din rapoarte se reconstruiește pe noul model. |
| **`SearchConsoleGatherer`** | **SUPRAVIEȚUIEȘTE** | Independent de pipeline-ul de audit; citește doar `search_console_cache`. |
| **`SeoAuditDispatcher` + `seo_monitors`** | **SE TRANSFORMĂ** | Pattern-ul (circuit breaker, cleanup stale `:47-55`, anti-concurență) e bun și reutilizabil pentru rulările noului audit. Tabelul `seo_monitors` rămâne ca purtător de config (interval, sitemap_url), dar semantica „crawl la 500 pagini săptămânal" dispare. Atenție: `crawl_enabled` alimentează separat `BrokenResourceDispatcher` — de decis soarta lui explicit. |

---

## 4. Date istorice: de păstrat / de aruncat

**De păstrat (migrare obligatorie):**
- `search_console_connections` + `google_connections` — token-uri OAuth; pierderea lor = re-onboarding manual pe fiecare client. **Nu se atinge.**
- `seo_keyword_rankings` — singurul istoric de poziții/clicks/impressions acumulat; noul modul îl consumă direct (tabel independent de `seo_audits`, nu are FK spre pipeline-ul vechi).
- `search_console_cache` — alimentează `SearchConsoleGatherer` și rapoartele; rămâne.
- `seo_monitors` — se păstrează ca purtător de configurare (is_active, interval, sitemap_url), cu re-semantizare.

**De păstrat ca snapshot (opțional, ieftin):**
- `seo_audits` (doar rândurile-antet: score, category_scores, counts, scanned_at, ssl_info, security_headers) — pentru continuitate în rapoartele lunare cu trend („scorul vechi"). Oricum retenția le taie la 90 zile (`RetentionPolicyService.php:110`), deci fereastra e mică; un export/tabel de arhivă `seo_score_history` la momentul tranziției e suficient dacă se dorește trendul.

**De aruncat (regenerabile, grele, fără valoare istorică):**
- `seo_pages`, `seo_links`, `seo_images`, `seo_issues` — snapshot-uri de crawl regenerabile oricând; modelul de issue e incompatibil cu stările EXISTA/NU_EXISTA/NU_SE_APLICA + evidence din metodologie. Drop după tranziție.

---

## 5. Implicații pentru feature-flag-ul tranziției

**Azi nu există un flag unic.** Gating-ul e fragmentat în trei locuri independente:
1. `seo_monitors.is_active` — singurul întrerupător real al pipeline-ului automat (`SeoAuditDispatcher.php:22`), per-site.
2. `ModuleConfigService::MODULE_MAP` **nu are cheie `'seo'`** — există doar intrări moarte în `DEFAULT_INTERVALS`/`DEFAULT_ON` (`ModuleConfigService.php:96,112`) pe care bucla `configureModule` (care iterează doar `MODULE_MAP`, `:136`) nu le atinge niciodată. Planurile de mentenanță nu pot activa/dezactiva SEO.
3. Rutele sunt necondiționate (`routes/web.php:106,139,140`) — UI-ul vechi e vizibil indiferent de orice config.

Plus căi de ocolire a oricărui flag: `runAudit()` manual din ambele componente (`SeoOverview.php:168`, `SiteSeoAudit.php:500`) și `SeoQuickAudit::runQuickAudit()` pornesc pipeline-ul direct, iar `crawl_enabled` pornește separat `BrokenResourceDispatcher`.

**Recomandare pentru tranziție:**
- Introdu un flag unic (ex. `config('seo.v2_enabled')` sau modul `'seo_audit'` adăugat corect în `MODULE_MAP`) citit în **toate** cele 4 puncte de intrare: dispatcher-ul programat (`routes/console.php:96`), cele 3 rute, `runAudit`/`runQuickAudit`, și `BrokenResourceDispatcher`.
- Etapa 1 (coexistență): flag off ⇒ comportament actual; flag on ⇒ dispatcher-ul vechi nu mai emite audituri noi, rutele servesc UI-ul nou, GSC/keywords/bulk-fix funcționează neschimbate (sunt independente de flag).
- Etapa 2 (curățare): oprire definitivă dispatcher vechi, snapshot `seo_audits` (dacă se vrea trendul), drop `seo_pages/links/images/issues`, ștergere joburi/analizoare/scoring + config mort din `config/seo.php` (§2.17), adăugare cheie `'seo'` reală în `MODULE_MAP` ca planurile să guverneze noul modul.
- Nu folosi `seo_monitors.is_active` ca flag de tranziție — e per-site și are deja altă semantică (opt-out client); flag-ul de versiune trebuie să fie global.
