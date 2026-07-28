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

### Rezultat 1.2 — GATA ✅ (modulul de audit SEO/CRO)

| Măsură | Înainte (1.1) | După (1.2) | Δ |
|---|---|---|---|
| Modele | 90 | 83 | −7 |
| Servicii | 156 | 126 | −30 |
| Componente Livewire | 111 | 107 | −4 |
| Joburi | 55 | 54 | −1 |
| Rute | 157 | 152 | −5 |
| Teste verzi (JUnit) | 1128 · 0 roșii | **948 · 0 roșii** · 32 skip | −180 (teste audit) |

- Șters (**104 fișiere**): 7 modele (Audit*, Prospect), `Services/Audit/` (30), `DTOs/Audit/`, `Exceptions/Audit/`, `Livewire/Audit/` (4), `Jobs/Audit/RunSfCrawl`, 5 enum-uri, `PublicAuditReportController`, `MonitorAudits`, `AuditConfigServiceProvider`, 5 factories, `config/audit.php`, blade-uri, 29 fișiere de test.
- Curățat cod păstrat: `AppServiceProvider` (2 binding-uri audit), `bootstrap/providers.php` (înregistrare provider), `routes/web.php` (import + 5 rute), `global-sidebar` (item Audits), `DatabaseSeeder` (apel seeder).
- Migrare drop: `2026_07_28_000002_drop_audit_module_tables` (7 tabele).

**Cuplaje surpriză (informația valoroasă):**
1. Modulul de audit era **auto-conținut** — spre deosebire de SEO, ZERO cuplaj în feature-uri de mentenanță (Rapoarte/Redirecturi). Referințele „din afară" erau doar mai multe fișiere ale modulului (DTOs, Exceptions).
2. **Cuplaj în infrastructură, nu în feature-uri:** binding-uri de container în `AppServiceProvider`, un `AuditConfigServiceProvider` înregistrat în `bootstrap/providers.php`, și — cel mai delicat — **migrarea de create `2026_07_23_000001` cheamă `AuditChecksSeeder->run()` în `up()`**, care folosea modelul `AuditCheck`. Regula „nu edita migrări existente" + „șterge modelul" păreau în conflict.
3. **Rezolvare fără a edita migrarea:** `AuditChecksSeeder` rescris să folosească `DB::table('audit_checks')->upsert()` (fără model) și **păstrat** ca plumbărie de migrare (+ `database/data/audit-checks-v2.json`); apelul scos din `DatabaseSeeder` ca `db:seed` să nu atingă tabelul dropat. Migrarea de create rulează neschimbată; tabelele se creează, se seedează, apoi se dropează de migrarea 1.2.

**Verdict auditor: CURAT** — nicio referință orfană, migrare exactă 1:1, seeder rescris corect nu rupe migrarea de create, niciun test slăbit. Notă cosmetică: 4 comentarii-cod în migrările de create numesc enum-uri șterse (inofensive; nu se ating — regula „nu edita migrări").

**Lăsat intenționat:** coloana `sites.is_prospect` + scope-ul `scopePortfolio` (`where('is_prospect', false)`) — coloană activă pe tabel păstrat; dropul ar fi refactor riscant peste scope.

### Rezultat 1.3 — GATA ✅ (modele financiare de client)

| Măsură | Înainte (1.2) | După (1.3) | Δ |
|---|---|---|---|
| Modele | 83 | 81 | −2 |
| Componente Livewire | 107 | 104 | −3 |
| Rute | 152 | 150 | −2 |
| Teste verzi (JUnit) | 948 · 0 roșii | **943 · 0 roșii** · 32 skip | −5 (metode de test) |

- Șters (**7 fișiere**): `ClientCost`, `ClientRevenue`, `ClientProfitability`, `ClientForm`, `ClientFormData`, + 2 blade-uri orfane (`client-form`, `client-profitability`).
- Curățat cod păstrat: relațiile `Client::costs()/revenues()`, rutele `clients.create`/`clients.edit`, blade-uri (butoane Add/Edit + secțiune Profitabilitate), 5 metode de test (funcționalitate ștearsă), docblock stale.
- Migrare drop: `2026_07_28_000003_drop_client_financials_tables` (client_costs, client_revenues).

**Verificare `MaintenancePlan`:** nu are câmpuri de preț (fillable: name, description, is_default, sort_order, security_settings, tweak_settings, include_*, source_site_id, created_by) — deja strict configurare tehnică. **Neatins**, conform briefului.

**Cuplaje (informația valoroasă):** modelele financiare erau bine izolate — singurele consumatoare erau `ClientProfitability` (ștearsă) și relațiile de pe `Client`. Clienții rămân **doar-citire** (View + changeStatus + Delete păstrate; create/edit scoase).

**Verdict auditor: curat funcțional** — nimic din spec rupt, zero referințe orfane, migrare exactă, teste neslăbite. A prins **2 view-uri blade orfane** (componentele Livewire au clasă PHP + blade; ștersesem doar clasa) — **șterse** înainte de commit.

**Lăsat intenționat:** `ClientPolicy::create/update` (metode nefolosite acum, dar nefracturate — parte din politica de client păstrată).

### Rezultat 1.4 — GATA ✅ (status pages + portal client + invitații)

| Măsură | Înainte (1.3) | După (1.4) | Δ |
|---|---|---|---|
| Modele | 81 | 75 | −6 |
| Componente Livewire | 104 | 101 | −3 |
| Servicii | 126 | 125 | −1 |
| Joburi | 54 | 52 | −2 |
| Rute | 150 | 137 | −13 |
| Teste verzi (JUnit) | 943 · 0 roșii | **924 · 0 roșii** · 32 skip | −19 (3 fișiere + 2 metode) |

- Șters (**32 fișiere**): 5 modele StatusPage* + Invitation, 2 Livewire StatusPages + UserManagement, StatusPageController + ClientPortalController + AcceptInvitationController, 2 joburi incident + 2 listeners, StatusPageService, UserInvitationMail, StatusPagePolicy, blade-uri (status-page, client-portal/show, accept-invitation, invitation-expired, user-invitation, user-management, layout status-page), 3 fișiere de test.
- Curățat cod păstrat: rutele (users, status-pages ×3, portal ×3, invitation ×2, status ×4), rate limiters status-page, tab-uri settings (Users + Status Pages), `Client` (câmpuri/scope/hook portal), `ClientDetail` (2 metode portal), `SiteReports` + 2 UI-uri rapoarte (link-uri portal), `ActivityLogger` (3 metode), `JobQueueAssignmentTest` (2 joburi șterse).
- Migrare drop: `2026_07_28_000004_drop_status_page_and_portal_tables` (6 tabele).

**Cuplaj-cheie (informația cea mai valoroasă din fază):** portalul client era **țesut în sistemul de livrare a rapoartelor** — blade-ul `client-portal/report.blade.php` e PARTAJAT între `ClientPortalController` (șters, `isPublicView=false`) și `ReportViewController` (**păstrat** — linkul public per-raport `/r/{report}/{token}`, `isPublicView=true`). Granița: portalul multi-raport per-client se șterge; livrarea single-raport rămâne. Blade-ul + controllerul păstrate, curățate de link-urile portal.

**Decizie de scope:** rolurile (`UserRole`, coloana `users.role`, policies pe rol) **NU** sunt în lista 1.4 și sunt folosite de `RequireRole` + policies → **păstrate**. S-au scos doar UI-ul de gestiune utilizatori + invitațiile. Autentificarea și 2FA — intacte.

**Verdict auditor: CURAT** — auth/2FA/UserRole intacte, zero referințe orfane (rute, câmpuri portal, listeners auto-descoperiți, policy), migrare exactă 6 tabele, niciun test slăbit.

**Lăsat intenționat:** coloanele `clients.portal_token`/`portal_enabled` (tabel păstrat); `pgsql-schema.sql` squash-uit conține încă tabelele dropate — corect la runtime (schema încarcă → migrarea de drop elimină), doar igienă de regenerat la ocazie.
