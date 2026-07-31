# PROMPT CLAUDE CODE — SimpleAd Manager: corectare completă + modul SEO/Audit unificat + integrări noi

**Versiune:** 1.1 — 22 iulie 2026 · **Repo:** `simplead-manager` (un singur repo — integrarea cu Hub/Tasks e program separat, NU o atinge aici) · **PROMPT AUTONOM: se lipește INTEGRAL ca prim mesaj într-o sesiune Claude Code deschisă în rădăcina repo-ului — nu are nevoie de niciun fișier extern.** Tot contextul necesar e inclus; agentul își clonează singur sursele de care are nevoie.

**Decizii deja luate de proprietar (nu le rediscuta):** modulul SEO existent va fi ÎNLOCUIT de modulul unificat SEO/Audit pe metodologia celor 82 de verificări · Screaming Frog rulează AUTOMATIZAT pe server (headless, licențiat), cu upload manual păstrat doar ca fallback · fix-urile generate cu AI se aplică DOAR după validare umană per fix (cu bulk-select), prin conector, cu backup + rollback · nu se clonează plugin-uri WPMU DEV Pro (licențiere ne-redistribuibilă) — doar inspirație funcțională + orchestrare de plugin-uri gratuite canonice.

---

## PREGĂTIRE (2 minute)

Clonă la zi a `simplead-manager` pe `main`, `composer install` funcțional → lipești tot fișierul ăsta în Claude Code. Atât.

## LA STARTUL SESIUNII (primul lucru pe care îl faci, agent)

Pune-i proprietarului (Andrei) DOAR aceste 3 întrebări, în chat, și notează răspunsurile în `docs/plan/config.md`:

1. Care sunt cele **2–3 site-uri pilot** pentru teste (fix-uri AI, webp)?
2. Pe ce host rulează deocamdată **Screaming Frog headless** (serverul actual al Manager-ului sau altul)? Licența SF e disponibilă pentru instalare pe server?
3. Există **cheie Anthropic** pentru generarea AI în .env-ul de producție? (dacă nu: se lucrează pe staging cu cheie de dev, iar activarea în prod devine un punct în raportul final)

Toate celelalte decizii sunt DEJA luate (vezi mai sus) — nu le rediscuta. Aprobator: Andrei; la fiecare STOP te oprești și aștepți răspunsul lui în chat.

---

## ROLUL TĂU

Ești un inginer senior Laravel (PHP 8.3, Livewire 4, PostgreSQL/PgBouncer, Horizon) și ORCHESTRATOR al acestui program pe SimpleAd Manager — platforma de administrare a site-urilor WordPress ale clienților agenției Simplead (manager.simplead.ro), cu plugin conector WP propriu semnat HMAC (~6.200 linii). Implementarea o faci tu, în valuri disciplinate; SUBAGENȚII (Task tool) se folosesc pentru: research (Faza B), audit adversarial la final de fază (Anexa 1) și verificări izolate. Citește întâi: `CLAUDE.md`, `docs/audit/2026-07-10-full-audit.md`, `CHANGELOG.md`. Contextul extern (analize, research, producție) e inclus în acest prompt — vezi Anexa 2; nu ai nevoie de alte fișiere.

## REGULI GLOBALE (valabile în toate fazele și pentru toți subagenții)

1. Lucrezi pe branch-uri + PR-uri mici, în valuri; **Pint + PHPStan + PHPUnit verzi la fiecare PR**; gate-ul din `deploy.sh` nu se ocolește. Niciodată direct pe `main`.
2. Fiecare modificare = teste + intrare CHANGELOG în același PR, cu raționament (stilul casei: fix-urile se leagă de ID-uri de audit/plan).
3. Migrări DB aditive, rollback documentat; DDL pe conexiunea `pgsql_direct` (PgBouncer e transaction pooling).
4. Comportamentul existent nu se schimbă decât unde programul o cere explicit.
5. Zero secrete în repo; orice variabilă nouă → `.env.example` cu comentariu.
6. Orice string nou de UI → `__()` cu EN + RO în același PR.
7. Orice rută/endpoint nou → Policy/middleware explicit + rate-limit + test negativ de autorizare.
8. Nu te atinge de: semnarea HMAC a conectorului (doar extinzi cu acțiuni noi semnate identic), formatul v3 de backup, rolurile Admin/Manager/Viewer.
9. Dependențe noi doar cu justificare scrisă în PR.
10. Context pe sfârșite → scrii `docs/plan/STATUS.md` (unde ai rămas, PR-uri deschise, pasul următor); sesiunea următoare pornește din STATUS.md + acest prompt.

## BUCLA STANDARD (LOOP-ul per fază — obligatoriu)

1. Deschizi faza cu un plan scurt: lista valurilor (PR-uri mici) → `docs/plan/faza-<N>-plan.md`.
2. Implementezi val cu val; quality gate verde per PR.
3. Final de fază → lansezi **subagentul AUDITOR** (prompt: Anexa 1; context curat — primește DOAR criteriile de acceptanță ale fazei + acces la cod): scrie `docs/plan/raport-faza-<N>.md` cu constatări P0–P3 (file:line, reproducere).
4. P0/P1 → val de remediere → re-audit DOAR pe punctele atinse. Max 3 runde; dacă nu converge → STOP, raportezi.
5. **STOP-point:** rezumat fază + raport auditor către Andrei; aștepți OK explicit înainte de faza următoare.

---

# FAZA A — Fundație & inventar (~o zi)

Baseline quality verde local · inventar scurt al modulelor existente → `docs/plan/inventar.md` · verifică punct cu punct starea problemelor CUNOSCUTE (confirmă în cod, nu presupune): Laravel 11.48 (EOL securitate) · MFA șters complet în PR #34 (migrarea `2026_07_11_000001_drop_two_factor_columns_from_users.php`, nimic în loc) · `.env.example` lipsă · ~14 tabele orfane SEO + `pgsql-schema.sql` stale · `bootstrap/app.php:22-29` citește `env()` direct (trustProxies) · `edoburu/pgbouncer:latest` nepinuit · `/restore-download/{token}` fără expirare · transport restore = 2 POST-uri sincrone 1800s · `file_mode=staged` trimis fără verificarea capabilității conectorului · furtuni de alerte nededuplicate cross-site · uptime 2 workeri (`config/horizon.php:296`), `check_locations` în schemă dar nefolosit · god-objects: `RestoreBackup.php` 1.132 / `CreateBackup.php` 1.118 / `CrawlSitePages.php` 797 / `SiteSeoAudit.php` 787 · `phpstan-baseline.neon` 50 KB. Raportul fazei = starea reală a fiecărui punct.

# FAZA B — Research & propuneri (subagenți în paralel; STOP cu aprobare la final)

Lansezi 4 subagenți ÎN PARALEL (fiecare scrie în `docs/plan/`):

- **R1 — WPMU DEV live:** analizează TOATE plugin-urile de pe https://wpmudev.com/plugins/ (paginile individuale de produs) + The Hub (hub-welcome). Constatări anterioare de VERIFICAT și extins (nu de copiat orb): catalogul avea la 22 iul 12 plugin-uri Pro + The Hub Client (Smush, Hummingbird, Defender, Snapshot, SmartCrawl, Branda, Beehive, Shipper, Forminator, Hustle, Dashboard, Video Tutorials); la uptime / safe-updates / backup Manager-ul e la paritate sau peste The Hub; gap-urile reale față de suita lor: optimizare imagini, broken links, white-label în wp-admin, malware scanning pe conținut, geo-block/WAF, client billing; capcană de licențiere: plugin-urile WPMU DEV Pro NU se pot redistribui/orchestra (livrate doar cu abonament + cheie API) — orchestrabile liber sunt doar cele de pe wordpress.org. Output: matrice funcționalitate → are/n-are Manager → oportunitate.
- **R2 — webp-uploads:** fișă tehnică la zi (https://wordpress.org/plugins/webp-uploads/ — versiune, cerințe WP/PHP/Imagick-AVIF, limitarea „doar upload-uri noi") + plan de orchestrare din conector.
- **R3 — autopsia modulului SEO existent:** DE CE nu satisface (proprietarul e nemulțumit de analizele SEO actuale). Citește codul real: crawler-ul propriu (`CrawlSitePages`), `SiteSeoAudit`, scoruri normalizate, bulk-fix, GSC keywords. Verdict per componentă: MOARE (înlocuit de modulul unificat) / SUPRAVIEȚUIEȘTE (se integrează în noul modul — candidați: GSC keywords, bulk-fix semnat) / SE TRANSFORMĂ. Plus: ce date istorice SEO trebuie păstrate/migrate.
- **R4 — metodologia simplead-audit:** clonează întâi repo-ul: `git clone https://github.com/andreialexandrupanait/simplead-audit.git ../simplead-audit` (folosește autentificarea git deja existentă pe mașină; dacă accesul e refuzat, oprește-te și cere-i lui Andrei acces — nu improviza). Apoi, din clonă: cele 82 de verificări din `methodology-v2/checks.js` (5 secțiuni: SEO on-site 44, tehnic 10, off-site 5, CRO 13, LLM/AEO 10), evaluatoarele deterministe, prompturile AI (locația exactă), garanția anti-fabricare (verdict doar cu dovadă citată din crawl — răspuns la un incident real din 12 iul), know-how-ul Screaming Frog 24.3 (~57 exporturi CSV), schema Prisma. Output: plan de port concret în Laravel (entități, joburi, ce se copiază verbatim).

Tu consolidezi totul în **`docs/plan/propuneri.md`**: (1) corecturi la existent (din Faza A), (2) funcționalități NOI propuse, fiecare cu argument + S/M/L + verdict recomandat (implementăm / mai târziu / nu — include lista deja validată: BLC, IndexNow, Branda-light, reguli Cloudflare geo/WAF, malware heuristic; anti-scope: minify/Critical CSS, WAF propriu, forms/popups, clone Smush/CDN), (3) designul modulului SEO/Audit unificat (Faza D), (4) planul webp (Faza E). **STOP → Andrei bifează ce intră.** Nimic din D/E nu se construiește nebifat.

# FAZA C — Corectarea & întărirea a tot ce există (fără funcții noi)

**C1 — Securitate platformă:** upgrade Laravel 11→12 (know-how: sad-erp e pe 12; suită completă pe Postgres real în CI; atenție Livewire uploads, casturi `encrypted`, cozi) · MFA: TOTP + coduri recovery, **obligatoriu Admin**, aplicat și pe Google SSO, rate-limit, audit log enroll/disable · `.env.example` complet + runbook instalare/DR în `docs/` · drop tabele orfane + regenerare `pgsql-schema.sql` (instalările noi = producția) · fix trustProxies pe `config()` cu test de regresie · pin pgbouncer pe digest · expirare `/restore-download/{token}`.

**C2 — Încrederea în operațiile critice:** **proven restore** — job săptămânal care restaurează cel mai recent backup al unui site (rotație) într-un sandbox WP containerizat izolat, health-checks (homepage 200, login, coerență DB cu manifestul), badge „ultimul restore dovedit" per site + global + alertă la eșec · **transport asincron restore** — handshake job-token, conectorul rulează detașat cu fișier de progres, Manager face poll semnat, finalizare idempotentă (restore „failed" de transport dar terminat de conector se reconciliază la poll — niciodată „fișiere noi + DB vechi"), kill-switch · **negocierea capabilităților** — conectorul își anunță capabilitățile la handshake; operațiile fără capabilitate sigură se REFUZĂ explicit („actualizează conectorul la ≥X"), fără fallback tăcut pe merge in-place · **agregarea furtunilor** — N site-uri down în T minute → 1 notificare agregată per canal + 1 la recovery; test: 20 site-uri simulate → numeri mesajele · **offsite verificat** — banner per site fără destinație offsite activă + job de validare credențiale și ultima replicare · **e2e cu `FakeWordPressApiService`**: backup→verificare→restore staged, safe-update cu rollback la health-check picat, callback-uri. Acestea devin plasa pentru Faza F.

**Acceptanță C:** L12 + MFA live; restore real dovedit automat vizibil în UI; restore întrerupt la transport se reconciliază (test); conector vechi = refuz explicit; furtună = 1 alertă; e2e verzi în CI; mediu nou reconstruibil din repo + runbook.

# FAZA D — Modulul SEO/Audit unificat cu soluții AI (nucleul programului)

*Decizie luată: acest modul ÎNLOCUIEȘTE modulul SEO existent. Componentele vechi care supraviețuiesc (conform R3 aprobat — probabil GSC keywords + bulk-fix) se integrează AICI. Tranziția pe feature flag; codul vechi se șterge DOAR după paritate demonstrată.*

**D1 — Schema + seed:** tabele noi (`audits`, `audit_checks`, `audit_check_results`, `audit_cards`, `audit_reports` + prospecți); cele 82 de verificări binare (EXISTĂ / NU EXISTĂ / NU SE APLICĂ) se importă ca **seed direct din `methodology-v2/checks.js`** (din clona `../simplead-audit` făcută la R4) — nu se rescriu de mână; fără scoruri compuse (agregarea rămâne „X din Y implementate" — decizie de design, păstreaz-o); audit atașabil unui site conectat SAU unui **prospect** (site_id/client_id nullable) — e și unealtă de vânzare.

**D2 — Screaming Frog automatizat pe server:** serviciu SF headless containerizat pe host-ul stabilit la startul sesiunii (imagine cu SF CLI; licența DOAR din env pe server; verifică termenii de seat înainte de instalare) · crawl programat + on-demand din Manager: job Horizon `RunSfCrawl` pe coadă nouă `audit` → config de crawl per site (limite URL, viteză politicoasă, user-agent onest) → exporturile CSV standardizate (setul din know-how-ul SF 24.3 al repo-ului audit) → storage cu retenție și quota de disc · max 1–2 crawl-uri concurente (SF e greu) + timeout + retry + alertă la eșec · **fallback păstrat:** UI de upload manual al exporturilor SF (pentru prospecți crawl-ați de pe PC-ul lui Andrei) · `IngestCrawl` parsează și normalizează într-un model comun, indiferent de sursă (automat/manual).

**D3 — Evaluarea:** evaluatoarele deterministe portate în PHP din repo-ul audit + `RunAiChecks` pentru verificările calitative — **prompturile se portează verbatim**, streaming, și **garanția anti-fabricare rămâne lege: orice verdict AI cere dovadă citată din datele crawl-ului, altfel se respinge**. PageSpeed vine din modulul existent (nu port de PSI separat); GSC unde e conectat.

**D4 — Soluții SEO cu AI (cerința centrală a proprietarului):** fiecare verificare primește `fix_type`: **(a) generabil cu AI** — meta title ~50–60 caractere, meta description ~150–160, H1, alt-texte imagini, FAQ/schema, directive robots, hărți de redirecturi — generate DOAR din dovezile crawl-ului + conținutul paginii + GSC unde există, în limba site-ului; **(b) aplicabil tehnic prin conector**; **(c) manual** (design, off-site). Fluxul: „Generează cu AI" per verificare + bulk per secțiune → **editor de validare umană** (aprobare per fix, cu bulk-select; NIMIC nu se aplică nevalidat — decizie luată) → **„Aplică pe site"** prin conector: detectează pluginul SEO instalat (**Yoast / RankMath / AIOSEO / niciunul → core meta unde se poate**) și scrie în cheile meta ale ACELUI plugin (scriem în pluginul clientului, nu în paralel), acțiune semnată HMAC, **backup valori vechi + rollback per aplicare** → re-crawl → verificarea trece automat pe „implementat" → progres „X din Y" vizibil și în raportul PDF lunar. Pentru prospecți: fix-urile devin livrabile copy-paste în raport. Buget AI per audit + log de costuri per generare (model configurabil în .env, default claude-sonnet).

**D5 — Monitorizare continuă pe aceeași metodologie:** re-audit programat (lunar/la cerere) per site, delta între audituri, alertă la regresii (o verificare care era EXISTĂ devine NU EXISTĂ).

**D6 — Raport public + migrare + sunset:** pagină publică token-izată pe slug (modelul portal existent: `hash_equals`, revocare, throttle) cu **toggle-uri persistente pentru client**; export PDF prin Gotenberg · migrarea datelor din aplicația veche (DB 11 MB): clienți mapați, rapoartele publicate (slug+token — linkurile sunt deja la clienți!), ultimul audit v2 per client; restul arhivă dump · `audit.simplead.ro` → redirect permanent către URL-urile noi · nu porta gunoaiele (`wgood.h`/`wgood.out` = dump-uri de debugging).

**Acceptanță D (pe site-urile pilot stabilite la start):** crawl SF pornit din Manager rulează automat cap-coadă → 82 verificări evaluate cu dovezi → ≥10 fix-uri AI generate (meta title/description + alt-texte) → validate uman → aplicate prin conector în pluginul SEO detectat → rollback demonstrat pe cel puțin unul → re-crawl le marchează singur „implementat"; raport public funcțional; link vechi redirecționat; modulul SEO vechi oprit pe flag fără pierdere de funcții supraviețuitoare.

# FAZA E — webp-uploads + integrările aprobate la Faza B

**E1 — webp-uploads (obligatoriu):** orchestrare din conector a pluginului canonic „Modern Image Formats" (WordPress Performance Team, gratuit): pre-check per site (Imagick/GD + suport AVIF — endpoint nou în conector), instalare/activare/configurare semnată (AVIF cu fallback WebP, `<picture>` on), stare vizibilă în Manager, **măsurare before/after în PageSpeed-ul existent (LCP)** pe site-urile pilot; val 2 opțional: „regenerează istoricul" ca job WP-CLI cu progres (atenție: limitarea „doar upload-uri noi" + timeouts pe shared hosting).

**E2+ — doar ce a bifat Andrei la STOP-ul Fazei B**, în ordinea valoare/efort (referință: BLC — cu SF pe server linkurile moarte ies nativ din crawl, deci devine raportare + fix prin bulk-fix; IndexNow — ping la publish + după fix-urile D4; Branda-light în conector; reguli Cloudflare geo/WAF pe integrarea existentă; malware scanning heuristic peste scanerul de integritate). **Anti-scope ferm:** fără minify/Critical CSS ca serviciu, fără WAF propriu în PHP, fără forms/popups, fără clone Smush/CDN, fără redistribuirea plugin-urilor WPMU DEV Pro.

**Acceptanță E:** fiecare integrare livrată = acțiune semnată în conector + UI în Manager + secțiune în raportul PDF lunar + teste; webp activ pe site-urile pilot cu delta PageSpeed măsurată și raportată.

# FAZA F — Șlefuire & datorie (după E; Andrei poate amâna la STOP)

Descompunerea god-objects sub protecția e2e-urilor din C2 (`RestoreBackup`, `CreateBackup`; `CrawlSitePages`/`SiteSeoAudit` probabil mor odată cu modulul vechi — confirmă) · reducere `phpstan-baseline.neon` + level 6 · scăpările i18n hardcodate (ex. `site-backups.blade.php:474,615`) · pregătire multi-locație uptime pe `check_locations` + `HORIZON_UPTIME_WORKERS` · decizie AI incident response (activare cu buget sau ștergere — azi construit, dezactivat, fără cheie) · flux off-boarding „predare site".

---

# DEFINITION OF DONE (programul întreg)

Toate fazele cu raport de auditor curat + OK de la Andrei · L12 + MFA + restore dovedit + transport asincron · modulul SEO/Audit unificat live: SF automat pe server, 82 verificări, fix-uri AI validate și aplicate prin conector cu rollback, monitorizare continuă cu delta, raport public, date migrate, `audit.simplead.ro` redirecționat, modulul SEO vechi eliminat · webp-uploads + integrările bifate live și prezente în raportul PDF · `docs/plan/` conține: inventar, propuneri aprobate, rapoartele tuturor fazelor, STATUS.md final · CHANGELOG spune povestea completă.

# ANEXA 1 — Promptul subagentului AUDITOR (se folosește la fiecare final de fază)

> Ești un auditor tehnic advers, cu context curat — NU ai implementat nimic din ce verifici. Primești: criteriile de acceptanță ale Fazei <N> (copiate din plan) + acces la repo și la mediul de test. Sarcina: verifică FIECARE criteriu pe cod și, unde se poate, prin rulare (teste, tinker, http local); caută activ regresii în modulele atinse și încălcări ale Regulilor globale (secrete, autorizare, i18n, migrări). NU repari nimic. Scrie `docs/plan/raport-faza-<N>.md`: fiecare constatare cu severitate P0 (blochează) / P1 (grav) / P2 / P3, file:line, reproducere, criteriul afectat. Încheie cu verdictul: TRECE / NU TRECE + lista exactă a ce trebuie remediat. Fii zgârcit cu P0-urile și onest cu ce n-ai putut verifica.

# ANEXA 2 — Fapte de context (tot ce trebuie să știi din analizele anterioare)

Manager: HEAD `0351c29` (13 iul 2026), Laravel 11.48, 104 componente Livewire, 91 modele, 60 joburi, 30 comenzi, ~700 metode de test, CI blocant + gate fail-closed în deploy.sh; producție pe dasher (46.225.98.92), 9 containere, zero drift față de repo. Module: uptime/SSL/DNS/domenii + incidente + status pages · backup full+incremental v3 cu verificare A/B + staged restore · safe updates cu rollback + feed Wordfence · securitate WP (hardening semnat, 2FA email site-uri clienți, IP ban, integritate) · PageSpeed programat · SEO (de înlocuit) · rapoarte PDF lunare RO/EN white-label + portal client token (`routes/web.php:250-252`) · notificări 5 canale cu escaladare · profitabilitate manuală + planuri mentenanță · Cloudflare (zone, purge) · error logs · AI incident response (dezactivat, fără cheie în prod). SAD Audit (sursa metodologiei): repo `simplead-audit`, HEAD `9aeb9f4`, Next.js 16 + Prisma, 509 teste vitest, construit 12–13 iul, producție audit.simplead.ro (DB 11 MB, backup zilnic 3:17 cron pe rudolf). Shortlist-ul de integrări deja evaluat (22 iul, valoare/efort): 1. orchestrare webp-uploads (S) · 2. broken links pe crawl-ul SF (M) · 3. IndexNow (S) · 4. Branda-light în conector (S/M) · 5. geo-block/WAF prin Cloudflare-ul integrat (S/M) · 6. malware scanning heuristic (M/L) · mai târziu: page caching, staging, GA4 în portal, billing (SmartBill/Oblio înainte de Stripe).
