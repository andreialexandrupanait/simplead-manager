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

### Rezultat 1.5 — GATA ✅ (remediere automată)

| Măsură | Înainte (1.4) | După (1.5) | Δ |
|---|---|---|---|
| Modele | 75 | 73 | −2 |
| Servicii | 125 | 114 | −11 |
| Componente Livewire | 101 | 100 | −1 |
| Joburi | 52 | 51 | −1 |
| Enum-uri | 17 | 15 | −2 |
| Rute | 137 | 136 | −1 |
| Teste verzi (JUnit) | 924 · 0 roșii | **840 · 0 roșii** · 32 skip | −84 (17 fișiere + metode) |

- Șters (**38 fișiere**): `IncidentResponse`, `IncidentResponseAction`, `Services/IncidentResponse/` (11 incl. Playbooks/Contracts/AI), `IncidentResponseStatus` + `IncidentTriggerType` enum, `IncidentResponseDispatcher`, listeners `TriggerIncidentResponse` + `RecordIncidentRecovery`, jobul `RunIncidentResponse`, `AiIncidentResponseSettings` + blade, 2 factories, 17 fișiere de test.
- Curățat cod păstrat: `SiteTodoService` (bloc todo incident), `RetentionPolicyService` (categorie), rute web (`ai-incident-response`) + console (dispatcher + stale-sweep), `HasSiteRelationships` (relație), 2 teste păstrate (`CrossTenantActionsTest` −2 metode, `RetentionNewCategoriesTest` −aserție).
- Migrare drop: `2026_07_28_000005_drop_incident_response_tables` (2 tabele).

**Cuplaj-cheie (atinge lista „Ce NU se atinge"):** motorul de remediere automată **partaja configul AI** (`config/incident-response.php`, `ai.api_key`+`ai.model`) cu `PluginRiskAssessmentService` — serviciu **protejat** (se conectează în Faza 6). Ștergerea configului + a `IncidentResponseConfigServiceProvider` (care hidratează cheia din SettingsService) ar fi **rupt serviciul protejat**. **Decizie:** ambele PĂSTRATE ca plumbărie de config AI; verificat că `config('incident-response.ai.model')` rezolvă la boot.

**A doua capcană:** cuvântul „incident" acoperă DOUĂ sisteme — **uptime** (`UptimeIncident`, jobul `NotifyIncident`, monitorizare — PĂSTRAT) vs **incident-response** (auto-remediere — ȘTERS). Distinse cu grijă; `NotifyIncident` (constructor `UptimeIncident`) a fost cât pe ce să fie șters greșit.

**Verdict auditor: CURAT** — PluginRiskAssessmentService + config rezolvă, uptime/auth/2FA/Rollback intacte, migrare exactă 2 tabele, slăbiri de test legitime.

**Lăsat intenționat (housekeeping de review):** supervizorii Horizon orfani `supervisor-incident-response` (din 1.5) și `supervisor-audit` (din 1.2) în `config/horizon.php` — cozi goale, workeri idle inofensivi. Nu i-am șters în fluxul automat: editarea config-ului de cozi de PRODUCȚIE în 4+ locuri (defaults + 3 medii + waits) e risc disproporționat față de beneficiu (o greșeală rupe procesarea cozilor). Recomand curățare într-o schimbare dedicată, revizuită manual.

---

## Raport final — Faza 1 (ștergeri) încheiată

### 1. Cifre înainte / după

| Măsură | Baseline (Faza 0) | După Faza 1 | Δ |
|---|---|---|---|
| Modele | 97 | **73** | −24 |
| Servicii | 162 | **114** | −48 |
| Componente Livewire | 114 | **100** | −14 |
| Joburi | 62 | **51** | −11 |
| Rute | 160 | **136** | −24 |
| Teste verzi (JUnit) | 1158 · 0 roșii | **840 · 0 roșii** · 32 skip | −318 |
| Fișiere șterse (total) | — | **~224** | (43+104+7+32+38) |

Suita a rămas **verde la fiecare sub-pas** (0 erori, 0 eșecuri), confirmat autoritar prin JUnit după fiecare fază.

> **Notă la criteriul „~57 modele":** estimarea din brief (§14.3, „~40 modele") a presupus mai multe modele per modul. Amprenta reală a fost dominată de servicii/DTOs/teste, nu modele — de aici 73, nu ~57. S-a șters **exact** ce cereau listele explicite ale sub-pașilor; reducerea de FIȘIERE (~224) e masivă.

### 2. Ce s-a rupt neașteptat și cum am rezolvat

- **1.1** — `tearDown` MinIO + fixture Vite erau deja reparate în Faza 0; ștergerea SEO a rupt `RetentionCleanupResilienceTest` (insera în `seo_audits` dropat) și lăsa o categorie fantomă în `RetentionPolicyService`. **Prins de auditor** (grep pe `seo_audits` cu litere mici — ratat de grep-ul meu `Seo`). Rezolvat: categorie scoasă, fixture mutat pe `activity_logs`.
- **1.2** — migrarea de create `2026_07_23_000001` cheamă `AuditChecksSeeder` (folosea modelul șters). Rezolvat fără a edita migrarea: seeder rescris pe `DB::table`.
- **1.3** — 2 view-uri blade orfane (clasa Livewire ștearsă, blade uitat). Prins de auditor, șterse.
- **1.4** — portalul client era țesut în sistemul de livrare a rapoartelor (blade partajat cu `ReportViewController` păstrat). Granița trasată chirurgical.
- **1.5** — configul AI partajat cu serviciul protejat `PluginRiskAssessmentService`; „incident" ambiguu (uptime vs auto-remediere). Rezolvate prin păstrarea plumbăriei de config + distincție atentă.

### 3. Ce a semnalat fiecare auditor independent

| Sub-pas | Verdict | Ce a prins |
|---|---|---|
| 1.1 | PROBLEME | 2 orfani `seo_audits` (litere mici) ratați de grep — reparați |
| 1.2 | CURAT | — |
| 1.3 | PROBLEME | 2 view-uri blade orfane — șterse |
| 1.4 | CURAT | (notă: `pgsql-schema.sql` neregenerat — cosmetic) |
| 1.5 | CURAT | (notă: supervizori Horizon orfani — deferați) |

Auditul independent (diff + spec + lista de protejat, fără raționamentul meu) și-a dovedit valoarea la 1.1 și 1.3 — a prins regresii reale pe care grep-ul meu case-sensitive le ratase.

### 4. Cuplaje descoperite (cea mai valoroasă informație — arhitectura reală)

1. **Motorul SEO era țesut în module de mentenanță păstrate:** `SiteRedirects::brokenLinks()` (Redirecturi, Faza 7) și secțiunea SEO a raportului lunar (`SeoGatherer`). Nu era izolat.
2. **`FetchKeywordRankings`** (job SEO) era programat în scheduler sub un nume fără „Seo" — invizibil unui grep pe „Seo".
3. **Modulul de audit se auto-însămânța prin migrare:** `AuditChecksSeeder` invocat din `up()`-ul migrării de create — cuplaj cod↔migrare.
4. **Portalul client == livrarea de rapoarte, parțial:** blade-ul `client-portal/report` e partajat între portalul (șters) și `ReportViewController` (livrare rapoarte, păstrat) prin flagul `isPublicView`.
5. **Remedierea automată partaja configul AI cu `PluginRiskAssessmentService`** (protejat) — `config('incident-response.ai.*')`. Ștergerea „completă" ar fi rupt un serviciu de pe lista de protejat.
6. **Ambiguitatea „incident":** uptime (păstrat) vs incident-response (șters) — două sisteme cu nume similare, ușor de confundat.

**Pattern recurent:** grep-ul case-sensitive pe numele clasei (`Seo`, `Audit`) ratează consecvent (a) nume de TABELE cu litere mici (`seo_audits`), (b) consumatori cu nume fără prefixul modulului (`FetchKeywordRankings`, `NotifyIncident`). Baleierea trebuie să includă mereu numele de tabele + numele de clase individuale, nu doar prefixul modulului.

### 5. Ce am lăsat nerezolvat și de ce

| Element | De ce |
|---|---|
| Supervizori Horizon orfani (`supervisor-incident-response`, `supervisor-audit`) | Config de cozi de PRODUCȚIE; editare în 4+ locuri, risc de a rupe procesarea; auditor: non-blocant |
| `pgsql-schema.sql` neregenerat (conține tabele dropate) | Auto-generat, se auto-corectează la runtime (schema încarcă → drop elimină); regenerarea cere migrate complet + risc de drift |
| Coloane moarte: `sites.is_prospect`, `clients.portal_token/portal_enabled`, `site_monthly_snapshots.seo_issues_count` | Drop de coloană pe tabele PĂSTRATE cu date istorice — refactor riscant peste scope-ul fazei |
| Enum-uri istorice: `ActivityType::{Seo, SeoFix, IncidentResponse}` | String-backed; scoaterea ar rupe citirea rândurilor vechi din `activity_logs` |
| `ClientPolicy::create/update` | Metode nefolosite acum, dar parte din politica de client păstrată; nefracturate |

### 6. Recomandare pentru Faza 2 (un singur site, cap-coadă)

- **Alege un site simplu real** (nu cel mai important) și demonstrează lanțul complet: conectare → monitorizare → un update cu rollback → un backup restaurat → un raport livrat pe email. Livrabil: `E2E-VERIFICAT.md`.
- **Regenerează `pgsql-schema.sql`** ca prim pas de igienă (acum, cât cele 5 migrări de drop sunt proaspete) — pe o bază de test, apoi `schema:dump`. Elimină toate tabelele dropate din dump dintr-o mișcare.
- **Curăță supervizorii Horizon orfani** într-o schimbare mică, dedicată, revizuită manual (nu în fluxul E2E).
- **Verifică fluxul de raport** cu atenție la `ReportViewController` + blade-ul `client-portal/report` partajat — e singura zonă unde granița ștergerii a fost subtilă.
- **Nu porni Faza 3** (navigație) până când toți cei 5 pași E2E nu merg fără intervenție, conform briefului.
