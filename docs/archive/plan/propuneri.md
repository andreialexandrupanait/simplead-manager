# Propuneri consolidate — STOP Faza B (22 iulie 2026)

Consolidarea Fazei A (starea reală a existentului) + Fazei B (research R1–R4). **Andrei bifează ce
intră; nimic din D/E nu se construiește nebifat.** Sursele detaliate: `raport-faza-A.md`,
`r1-wpmudev.md`, `r2-webp.md`, `r3-autopsie-seo.md`, `r4-metodologie.md`.

Legendă verdict recomandat: ✅ implementăm (în faza indicată) · ⏳ mai târziu · ❌ nu.

---

## 1. Corecturi la existent (Faza C — toate confirmate în cod la Faza A)

| # | Corectură | Dovadă | Fază | Verdict |
|---|---|---|---|---|
| C-01 | Upgrade Laravel 11.48 → 12 (EOL securitate; 24 advisories în composer audit) | composer.lock | C1 | ✅ |
| C-02 | MFA TOTP + coduri recovery, obligatoriu Admin, aplicat și pe Google SSO | migrarea drop din PR #34 | C1 | ✅ |
| C-03 | `.env.example` complet + runbook instalare/DR | lipsă în repo | C1 | ✅ |
| C-04 | Drop ~9–12 tabele orfane SEO/keyword + regenerare `pgsql-schema.sql` (stale din 14 mai) | raport-faza-A §4 | C1 | ✅ |
| C-05 | trustProxies: **rezolvare prin config nativ L12**, NU re-aplicarea `config()` (a picat prod — P3-34/d7e26a3); test de regresie cu config:cache simulat | bootstrap/app.php:22-29 | C1 | ✅ |
| C-06 | Pin `edoburu/pgbouncer` pe digest | docker-compose.prod.yml:285 | C1 | ✅ |
| C-07 | Expirare token `/restore-download/{token}` | routes/web.php:39-50 | C1 | ✅ |
| C-08 | **Proven restore** — sandbox WP containerizat, rotație săptămânală, health-checks, badge per site + alertă | — | C2 | ✅ |
| C-09 | **Transport asincron restore** — handshake job-token, progres, poll semnat, reconciliere idempotentă, kill-switch | RestoreBackup.php:999 (POST sincron 1800s) | C2 | ✅ |
| C-10 | **Negocierea capabilităților conectorului** — anunț la handshake, refuz explicit fără capabilitate (azi `file_mode=staged` se trimite orb) | RestoreBackup.php:997 | C2 | ✅ |
| C-11 | **Agregarea furtunilor de alerte** — N site-uri down în T min → 1 mesaj/canal + 1 la recovery | CheckUptime.php:442 | C2 | ✅ |
| C-12 | **Offsite verificat** — banner site fără destinație activă + job validare credențiale/ultima replicare | — | C2 | ✅ |
| C-13 | E2E cu `FakeWordPressApiService` (backup→verificare→restore staged; safe-update cu rollback; callback-uri) — plasa pentru Faza F | — | C2 | ✅ |
| C-14 | Fix decalaj 3 zile `FetchKeywordRankings.recorded_date` (descoperit la R3 §2.18) | FetchKeywordRankings.php:52,97 | C sau D | ✅ |

## 2. Funcționalități NOI propuse (matricea R1 + shortlist-ul din Anexa 2)

### Recomandate ✅ (lista deja validată în prompt + deblocările descoperite la research)

| # | Propunere | Argument | Effort | Fază | Verdict |
|---|---|---|---|---|---|
| N-01 | **Conector: install-from-slug wordpress.org** (`POST /plugins/install`, `plugins_api()` + `Plugin_Upgrader::install()`) | „cheia" care deblochează orice orchestrare de plugin free (webp, BLC, Smush free…); azi conectorul are doar update/activate/deactivate/delete | **S** | E1 (precondiție) | ✅ |
| N-02 | **webp-uploads / Modern Image Formats** — orchestrare completă (detalii §4) | gap „optimizare imagini" confirmat vs WPMU DEV; decizie deja luată | S | E1 | ✅ (obligatoriu) |
| N-03 | **Broken Link Checker pe crawl-ul SF** — linkurile moarte ies nativ din exporturile SF → raportare + fix prin bulk-fix/redirects | cu SF pe server, BLC devine „gratis" (fără plugin în WP, fără load pe site) | S–M | E2 | ✅ |
| N-04 | **IndexNow** — ping la publish + automat după fix-urile D4 aplicate | S, valoare directă pe indexare rapidă post-fix | S | E2 | ✅ |
| N-05 | **Branda-light în conector** — white-label wp-admin (login, branding agenție, ascundere meniuri per rol) | gap confirmat; retenție client; extinde site-tweaks existente | S–M | E2 | ✅ |
| N-06 | **Reguli Cloudflare geo-block/WAF** pe integrarea existentă (zone/purge deja funcționale) | gap confirmat; zero cod în WP, doar API CF | S–M | E2 | ✅ |
| N-07 | **Malware scanning heuristic** peste scanerul de integritate existent (semnături pe fișiere prin conector) | gap confirmat (azi doar integritate core/teme + vulnerabilități) | M–L | E2 (ultimul) | ✅ |

### Descoperite la research — de bifat separat

| # | Propunere | Argument | Effort | Verdict propus |
|---|---|---|---|---|
| N-08 | **SSO 1-click în wp-admin** din Manager (token de login semnat HMAC — conectorul are deja `/login-url`) | câștig operațional zilnic; paritate cu Hub; HMAC-ul existent o face ieftină | S–M | ✅ recomand E2 |
| N-09 | Safe-update cu **diff vizual pe screenshot-uri** (before/after homepage + N pagini) | Hub-ul o are; extensie naturală la health-check-ul safe-updates | M | ⏳ propun mai târziu |
| N-10 | Restore selectiv (doar DB / doar fișiere) | Snapshot o are; backup-ul v3 are deja manifest per componentă | S–M | ⏳ |
| N-11 | GA4 în portal/rapoarte (secțiune PDF, nu dashboard live) | gap minor; `GoogleApiService` OAuth există deja | M | ⏳ (era deja „mai târziu" în shortlist) |
| N-12 | Billing client + suspendare la neplată (SmartBill/Oblio înainte de Stripe) | gap strategic, dar alt calibru | L | ⏳ (era deja „mai târziu") |

### Anti-scope ferm (nu se implementează — decizie din prompt, confirmată de R1)
❌ minify/Critical CSS ca serviciu · ❌ WAF propriu în PHP · ❌ forms/popups · ❌ clone Smush/CDN ·
❌ redistribuirea plugin-urilor WPMU DEV Pro (codul e GPL, dar motorul e în serviciile lor cloud —
non-GPL, neredistribuibile; orchestrăm doar free-uri de pe wordpress.org).

## 3. Designul modulului SEO/Audit unificat (Faza D) — sinteza R3 + R4

**De ce moare modulul vechi (R3, pe cod):** crawler fără JS rendering cu metrici structural greșite
(„TTFB" = timp total download, `depth` = segmente URL, redirect chains hardcodate 1, word_count
ASCII corupt pe diacritice RO); analiză cu bug-uri (orfane raportate dublu) și 0% acoperire pe
CRO/LLM-AEO/off-site; scor compozit opac interzis explicit de metodologia nouă; bulk-fix-ul pe
„Missing title/description" e un no-op (re-împinge valori scrapuite care tocmai lipsesc — zero generare).

**Ce supraviețuiește (se integrează în modulul nou):** integrarea GSC completă
(`GoogleSearchConsoleService` — `inspectUrl`/`getExternalLinks` mapează direct pe verificări noi),
keyword rankings + istoric, brațul de aplicare bulk-fix/HMAC (alimentat de-acum cu valori **generate**),
`SearchConsoleGatherer`, pattern-ul dispatcher + `seo_monitors` ca purtător de config.
**Se transformă:** `SeoOverview`, `SiteSeoAudit` (UI-uri rescrise pe modelul X din Y).
**Moare:** crawler, analizoare, scoring, `SeoGatherer`, pattern-ul Site-per-prospect din QuickAudit.

**Arhitectura nouă (R4 — port din simplead-audit @ 9aeb9f4, confirmat 82 verificări / 5 secțiuni):**
- **D1 Schema:** `prospects`, `audits` (site_id XOR prospect_id — CHECK constraint), `audit_checks`
  (seed direct din `methodology-v2/checks.js`), `audit_check_results` (stări EXISTA/NU_EXISTA/
  NU_SE_APLICA + evidence jsonb), `audit_cards`, `audit_reports`. Fără scoruri — agregarea unică
  „X din Y implementate".
- **D2 SF headless pe dasher:** container SF CLI, licență din env, `eula.accepted=15`, `-Xmx2g`,
  DB storage, 1 URL/sec, comanda de producție fără `--config` (57 export-tabs + 2 bulk + 5 rapoarte,
  `--skip-empty`), `RunSfCrawl` pe coada `audit`, max 1 crawl concurent (`WithoutOverlapping`),
  timeout 30 min → SIGKILL, retry + alertă. Fallback: upload manual exporturi (prospecți crawl-ați
  de pe PC). `IngestCrawl` normalizează ambele surse.
- **D3 Evaluare:** evaluatoare deterministe portate în PHP (semantica `--skip-empty`: fișier absent
  = dovadă pozitivă; santinele pentru precondiții; plafon 500 URL-uri evidence; praguri PSI 10/50 KiB,
  2500 ms; fetch-checks cu cei 8 UA AI) — constante/regex/filtre copiate verbatim, colectorii TS ca
  specificație + testele lor. `RunAiChecks`: prompturile `EVAL_V2_SYSTEM_PROMPT` + `DRAFT_V2_SYSTEM_PROMPT`
  VERBATIM, tool-use strict cu enum de chei, streaming, **anti-fabricare = lege**: verdict fără dovadă
  citată ≥8 caractere → retrogradat la „de verificat"; cheile external-only nu se trimit la AI deloc;
  auto-approve doar pe surse 100% deterministe. PageSpeed din modulul existent; GSC unde e conectat.
- **D4 Fix-uri AI:** `fix_type` per verificare (AI-generabil / tehnic-prin-conector / manual);
  generare DOAR din dovezile crawl-ului + conținutul paginii + GSC, în limba site-ului; editor de
  validare umană (aprobare per fix + bulk-select; nimic neaplicat nevalidat); „Aplică pe site" →
  detectare plugin SEO (Yoast/RankMath/AIOSEO/core) și scriere în cheile ACELUI plugin — conectorul
  face deja asta în `class-seo-endpoint.php`; backup valori vechi + rollback per aplicare; re-crawl
  → auto-marcare „implementat"; buget AI per audit + log de costuri (default claude-sonnet, $2/$10 Mtok).
  Pentru prospecți: fix-urile devin livrabile copy-paste în raport.
- **D5 Monitorizare:** re-audit programat (lunar/la cerere), delta între audituri, alertă la regresii.
- **D6 Raport public + migrare:** rută `GET /r/{slug}?t=<token>` cu semantica portată identic
  (constant-time, 404 uniform, noindex/no-store, toggle rate-limited 60/min, republish păstrează
  slug+token); migrare bit-identică a rapoartelor publicate (linkurile sunt la clienți!) + prospecți
  + ultimul audit v2 per client; nginx 301 `audit.simplead.ro` → Manager cu query string păstrat;
  restul DB (11 MB) arhivă dump; `wgood.h/out` ignorate.
- **Tranziție pe flag global unic** (nu `seo_monitors.is_active`!) citit în toate cele 4 puncte de
  intrare ale modulului vechi (dispatcher, rute, runAudit/runQuickAudit, BrokenResourceDispatcher);
  ștergerea codului vechi DOAR după paritate demonstrată; la final cheia `seo` intră corect în
  `ModuleConfigService::MODULE_MAP`.

**Precondiții D:** cheia Anthropic în prod (Andrei — config.md §3) · licența SF pe dasher (disponibilă
— config.md §2) · site pilot: notificarialimente.ro (+ recomand un al doilea înainte de acceptanță).

## 4. Planul webp (Faza E1) — sinteza R2

Plugin canonic: **Modern Image Formats (webp-uploads) v2.7.1**, WP 6.9+/PHP 7.4+, 100k+ instalări.
1. **Pre-check per site** — endpoint nou conector `/media-capabilities`: Imagick vs GD, suport
   AVIF/WebP (`wp_image_editor_supports()` ca sursă de adevăr), versiune ImageMagick/libheif.
2. **Instalare** prin N-01 (install-from-slug) + activare semnată.
3. **Configurare remote prin opțiuni** (fără UI WP): `perflab_modern_image_format` = avif (cu
   fallback WebP automat), `perflab_generate_webp_and_jpeg` = on (fallback JPEG), 
   `webp_uploads_use_picture_element` = on.
4. **Stare vizibilă în Manager** (badge per site: format activ, capabilități server).
5. **Măsurare before/after** cu PageSpeed-ul existent (LCP lab + CrUX) pe notificarialimente.ro —
   atenție: fără regenerarea istoricului, delta pe site-uri cu media veche va fi ~0.
6. **Val 2 (opțional, bifă separată):** regenerarea istoricului FĂRĂ shell_exec — endpoint conector
   batched (`wp_generate_attachment_metadata()` în loturi 3–5 imagini, cursor, progres în Manager);
   riscuri: timeouts shared hosting, memorie, dublarea spațiului pe disc. **Verdict propus: ✅ cu
   prudență pe pilot întâi.**

---

## Decizia proprietarului (STOP Faza B — REZOLVAT, 22 iulie 2026)

- [x] **Faza C integral** (C-01…C-14) — DA
- [x] **Faza D integral** (designul §3) — DA; piloți: **notificarialimente.ro + universulsacru.ro**
- [x] **E1 REDEFINIT de Andrei**: NU se instalează plugin extern; conversia imaginilor se face
  **prin conector** (care e deja plugin pe fiecare site), **la cerere din Manager** (nu automat):
  endpoint `/media-capabilities` (Imagick/GD, suport WebP/AVIF) + conversie batched cu progres,
  **păstrarea originalelor + rollback**, măsurare LCP before/after pe piloți prin PageSpeed-ul
  existent. Detaliile de servire (extensie/URL-uri/fallback) se decid la designul Fazei E.
  → N-02 (plugin webp-uploads) **anulat**; N-01 (install-from-slug) rămâne fără consumator imediat → ⏳.
- [x] **E2 bifat**: N-03 linkuri moarte pe SF · N-04 IndexNow · N-07 scanare fișiere (malware
  heuristic) · **N-08 SSO 1-click** (da la punctul 5)
- [x] **Scoase**: N-05 Branda-light (nebifat) · N-06 Cloudflare geo/WAF (Andrei o face manual din Cloudflare)
- [x] N-09 diff vizual · N-10 restore selectiv · N-11 GA4 · N-12 billing — confirmate ⏳ mai târziu
