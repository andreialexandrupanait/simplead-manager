# Progres Faza 1 — Ștergeri

Ramură: `faza-1-stergeri` (din `faza-0-baseline` @ `79e0adb`, suită verde).
Stare: **OPRIT la 1.1 — aștept decizie de scope înainte de prima ștergere.**

## Baseline de pornire (verde)

| Măsură | Valoare |
|---|---|
| Teste (bin/test, JUnit) | 1158 total · **0 roșii** · 32 skip |
| Modele (top-level) | 97 |
| ROSII_CUNOSCUTE | *goală* (orice roșu nou = regresie) |

## Sub-pas 1.1 — Motorul SEO duplicat: inventar complet

### Fișiere clar în motorul SEO (candidate la ștergere)

| Categorie | Fișiere |
|---|---|
| Modele (7) | SeoAudit, SeoImage, SeoIssue, SeoKeywordRanking, SeoLink, SeoMonitor, SeoPage |
| Servicii (5) | `Services/SeoAudit/`: AuditDiff, ExcelExport, Scoring, SiteAudit, UrlNormalizer |
| Livewire (3) | `Livewire/Seo/`: SeoOverview, SeoQuickAudit · `Sites/Detail/SiteSeoAudit` |
| Enum-uri (3) | SeoAuditStatus, SeoIssueCategory, SeoIssueSeverity |
| Joburi (7) | AnalyzeSeoPages, ApplySeoBulkFix, CalculateSeoScores, CheckBrokenResources, CrawlSitePages, FetchKeywordRankings, RunSeoAudit |
| Comandă | `Console/Commands/FixStuckSeoAudits` |
| Dispatchere (2) | SeoAuditDispatcher, BrokenResourceDispatcher (+ 2 intrări în `routes/console.php` scheduler) |
| Raport | `Services/Reports/Sections/SeoGatherer` |
| Blade-uri | seo-overview, seo-quick-audit, site-seo-audit, `reports/partials/seo` |
| Rute | `routes/web.php`: `/seo`, `/seo/quick-audit`, `sites/{}/seo` (+ `use App\Livewire\Seo`) |
| Teste | SiteSeoAudit{Signed,LatestAudit,BulkFix}, CalculateSeoScoresDuration, SeoScoringNormalization, CrawlSitePages{Coverage,Idempotency}, FetchKeywordRankingsTransaction, UrlNormalizerServiceTest |

> Rezumatul briefului spunea „~12 modele". Amprenta reală a motorului e ~35 fișiere. Nimic din asta nu e blocant în sine — motorul e coerent și se poate șterge întreg.

### ⚠️ Cuplaje în cod de MENTENANȚĂ care se PĂSTREAZĂ (declanșează oprirea)

| # | Fișier păstrat | Cum folosește SEO | Impact la ștergere |
|---|---|---|---|
| 1 | `Livewire/Sites/Detail/SiteRedirects.php` (**modul Redirecturi — observație Faza 7**) | `brokenLinks()` citește ultimul `SeoAudit` pentru sugestii de fix; **randat în blade** (`@if($this->brokenLinks->isNotEmpty())`) | Rupe un feature vizibil dintr-un modul păstrat |
| 2 | `Services/ReportGeneratorService.php` (**Rapoarte**) | instanțiază `SeoGatherer` în pipeline (linia 201) | Secțiune SEO în raportul lunar dispare |
| 3 | `resources/views/reports/maintenance-report.blade.php` + `partials/recommendations.blade.php` | includ secțiunea SEO | Curățare template raport |
| 4 | `Enums/ActivityType.php` (**Activitate**) | cazuri `Seo`, `SeoFix` (neutilizate în restul app) | Cosmetic — se pot lăsa sau scoate |
| 5 | `tests/Feature/Authorization/ViewerWriteGuardTest.php` (**Autorizare**) | folosește `SeoQuickAudit` ca fixture pt. guard de scriere | Triaj: schimbă fixture-ul (nu ce verifică) |

### Aliniere cu specificația

- **SPEC 12.2**: datele SEO vor veni prin API din aplicația separată → secțiunea SEO din raport (cuplaj #2/#3) **trebuie** oricum scoasă. Aliniat.
- **SPEC Faza 5.3**: link-checker-ul nou se construiește ulterior și „verifică întâi dacă modulul Redirecturi acoperă deja" → sugestiile din `brokenLinks()` (cuplaj #1) vor fi reînlocuite corect în Faza 5. Scoaterea acum e forward-compatibilă, dar **degradează temporar un modul păstrat**.

### Decizie primită (2026-07-28)

- **Scope:** șterge tot motorul SEO (nu doar lista literală).
- **Cuplaje păstrate:** scoate curat sub-feature-urile SEO din Redirecturi și Rapoarte.

### Rezultat 1.1 — GATA ✅

| Măsură | Înainte | După | Δ |
|---|---|---|---|
| Modele | 97 | 90 | −7 |
| Servicii | 162 | 156 | −6 |
| Componente Livewire | 114 | 111 | −3 |
| Joburi | 62 | 55 | −7 |
| Rute | 160 | 157 | −3 |
| Teste verzi (bin/test, JUnit) | 1158 · 0 roșii | **1128 · 0 roșii** · 32 skip | −30 (teste SEO șterse) |

- Șters: 7 modele, `Services/SeoAudit/` (5) + `SeoGatherer`, 3 Livewire, 3 enum-uri, 7 joburi, 1 comandă consolă, 2 dispatchere, `config/seo.php`, blade-uri, 9 fișiere de test — **43 fișiere**.
- Curățat cod păstrat: `HasSiteRelationships`, `ReportGeneratorService`, `SiteRedirects` (+ blade), 2 blade-uri de raport, 2 sidebar-uri, `ViewerWriteGuardTest`.
- Migrare drop: `2026_07_28_000001_drop_seo_engine_tables` (7 tabele `seo_*`, `DROP … CASCADE`).

**Cuplaje surpriză descoperite (informația cea mai valoroasă):**
1. Motorul SEO era țesut în **Redirecturi** (`SiteRedirects::brokenLinks()`, randat în UI) și în **Raportul lunar** (`SeoGatherer`) — module care se păstrează. Scoase curat.
2. `\App\Jobs\FetchKeywordRankings` era programat în `routes/console.php` sub un nume fără „Seo" — ratat de grep-ul inițial, prins la validarea de boot (`schedule:list`).
3. **Auditorul independent** a prins doi orfani pe care grep-ul „Seo" (case-sensitive) i-a ratat pentru că referă tabelul cu litere mici `seo_audits`: `RetentionPolicyService` (categorie de retenție fantomă) și `RetentionCleanupResilienceTest` (care RUPEA suita sub `RefreshDatabase`). Rezolvate: categoria scoasă; fixture-ul testului mutat pe `activity_logs` (categorie târzie reală) fără a slăbi aserția.

**Verdict auditor:** ștergere curată + migrare exactă 1:1; cele 2 probleme semnalate — rezolvate înainte de commit.

**Lăsat intenționat:** coloana `seo_issues_count` din `SiteMonthlySnapshot` (tabel de agregare păstrat, cu date istorice) — nu e referință spre ceva șters, doar o coloană rămasă nepopulată; dropul ei ar fi refactor riscant peste scope.
