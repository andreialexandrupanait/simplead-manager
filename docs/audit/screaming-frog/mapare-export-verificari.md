# Maparea export Screaming Frog → verificările metodologiei v2

Tabelul complet al celor **82 de verificări** din metodologia Simplead v2, cu exportul
Screaming Frog exact care le probează (Tab: Filtru, raport sau bulk export), coloanele
relevante și — acolo unde SF nu acoperă integral întrebarea — sursa alternativă
explicită (fetch/curl, GSC, GA4, Bing WT, PSI, manual).

Convenții:
- **SF export** = numele exact `Tab: Filtru` din interfață / `--export-tabs`.
- Coloanele citate provin din exportul `Internal: All` (Address, Content Type, Status
  Code, Status, Indexability, Indexability Status, Title 1, Title 1 Length, Meta
  Description 1, H1-1, H2-1, Meta Robots 1, Canonical Link Element 1, Word Count,
  Crawl Depth, Inlinks, Unique Inlinks, Response Time) sau din exportul filtrului citat.
- Verificările fără acoperire SF sunt marcate explicit „nu se acoperă din SF".

**Important:** un filtru gol este o dovadă validă — CSV-ul gol la `URL: Uppercase`
înseamnă că 2.1.1 trece pe acel criteriu. Se exportă și se arhivează întotdeauna.

**Observație:** filtrele din tabul Sitemaps și raportul Orphan Pages necesită „Crawl
Linked XML Sitemaps" + Crawl Analysis; tabul Structured Data necesită extraction +
validation active (vezi [config-crawl.md](config-crawl.md)).

---

## 02 SEO ON-SITE

### 2.1 URL-uri SEO-friendly

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.1.1 | URL-uri lowercase, cu cratime, fără underscore/spații | URL: Uppercase; URL: Underscores; URL: Contains Space | Address | — (acoperire completă SF) |
| 2.1.2 | URL-uri fără diacritice / non-ASCII | URL: Non ASCII Characters | Address | — |
| 2.1.3 | URL-uri indexabile fără parametri; variantele cu parametri canonicalizate | URL: Parameters; Canonicals: Canonicalised | Address, Indexability, Canonical Link Element 1 | corectitudinea țintei de canonical per tip de parametru → manual pe eșantion |
| 2.1.4 | URL-uri ≤115 caractere, fără segmente repetate/slash-uri multiple | URL: Over X Characters; URL: Repetitive Path; URL: Multiple Slashes | Address | — |

### 2.2 Crawling și indexabilitate

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.2.1 | Paginile importante au index,follow | Directives: Noindex + Internal: All | Address, Meta Robots 1, Indexability, Indexability Status | lista „paginilor importante" → decizie manuală pe arhitectura site-ului |
| 2.2.2 | Self-canonical absolut pe toate paginile indexabile | Canonicals: Missing; Canonicals: Canonicalised; Canonicals: Canonical Is Relative; Canonicals: Multiple Conflicting + Reports > Canonicals > Canonical Chains, Non-Indexable Canonicals | Address, Canonical Link Element 1, Indexability | — |
| 2.2.3 | Toate paginile indexabile sunt în sitemap-ul XML | Sitemaps: URLs not in Sitemap | Address, Indexability | necesită Crawl Linked XML Sitemaps + Crawl Analysis; dacă site-ul nu are sitemap → fetch direct pe /sitemap.xml pentru dovadă |
| 2.2.4 | Nicio pagină importantă blocată în robots.txt | Response Codes: Blocked by Robots.txt | Address, Status | conținutul integral al regulilor → fetch /robots.txt |
| 2.2.5 | Toate paginile indexabile răspund 200 | Internal: All; Response Codes: Redirection (3xx); Response Codes: Client Error (4xx); Response Codes: Server Error (5xx) | Address, Status Code, Status, Indexability | — |
| 2.2.6 | Zero pagini orfane | Internal: All (Unique Inlinks = 0) + Reports > Orphan Pages | Address, Unique Inlinks, Inlinks; în raport: coloana Source | necesită GSC + GA4 conectate în crawl + Crawl Analysis; fără ele, orfanele cunoscute doar de Google nu apar |

### 2.3 H1 — heading principal

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.3.1 | Exact un H1 per pagină | H1: Missing; H1: Multiple | Address, H1-1 | — |
| 2.3.2 | H1 = cuvântul cheie principal | Internal: All | Address, H1-1 (+ coloanele GSC per URL dacă API-ul e conectat) | potrivirea cu keyword-ul → GSC (interogări per URL) + judecată manuală [CONȚINUT] |
| 2.3.3 | H1-uri unice la nivel de site | H1: Duplicate | Address, H1-1 | — |
| 2.3.4 | H1 fără decorațiuni (an, pipe, brand, emoji) | Internal: All | Address, H1-1 | detecția decorațiunilor → analiză manuală/script pe valorile exportate |

### 2.4 H2 — heading secundar

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.4.1 | Primul H2 conține cuvântul cheie secundar | Internal: All | Address, H2-1 | potrivirea cu keyword-ul secundar → manual [CONȚINUT] |
| 2.4.2 | H2 cu sufix orientat spre acțiune pe paginile comerciale | Internal: All | Address, H2-1 | evaluarea sufixului → manual pe template-uri |
| 2.4.3 | Fără H2 generice duplicate pe tot site-ul | H2: Duplicate | Address, H2-1 | — |

### 2.5 H3 — CTA de final

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.5.1 | H3 CTA la finalul listărilor/conținutului | nu se acoperă din SF | — | sursă: manual pe template-uri (opțional: funcția Custom Search din SF, configurare [de verificat în SF]) |

### 2.6 Internal linking

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.6.1 | Navigare părinte → copii → frați pe categorii | Bulk Export `Links:All Inlinks` | Source, Destination, Anchor, Link Position, Link Origin (confirmate pe SF 24.3) | validarea logicii de navigare → manual pe template-uri |
| 2.6.2 | Secțiune de articole relevante pre-footer pe paginile comerciale | nu se acoperă din SF | — | sursă: manual pe template-uri |
| 2.6.3 | Carusel de articole similare la finalul articolelor | nu se acoperă din SF | — | sursă: manual pe template-uri |
| 2.6.4 | Ancore interne descriptive, fără „click aici"/ancore goale | Links: Non-Descriptive Anchor Text In Internal Outlinks; Links: Internal Outlinks With No Anchor Text | Address + coloanele de ancoră ale exportului | — |

### 2.7 Meta title și meta descriere

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.7.1 | Title conform formulei „[Keyword] - Brand.ro" | Internal: All | Address, Title 1 | verificarea formulei → script/manual pe valorile exportate |
| 2.7.2 | Title unice, prezente, câte unul singur | Page Titles: Missing; Page Titles: Duplicate; Page Titles: Multiple; Page Titles: Same as H1 | Address, Title 1 | — |
| 2.7.3 | Title în limite 30–60 caractere | Page Titles: Over X Characters; Page Titles: Below X Characters | Address, Title 1, Title 1 Length | — |
| 2.7.4 | Meta descrieri unice, ≤155–160 caractere, cu intenție comercială | Meta Description: Missing; Meta Description: Duplicate; Meta Description: Over X Characters; Meta Description: Below X Characters | Address, Meta Description 1 | intenția comercială → manual [CONȚINUT] |
| 2.7.5 | Paginile de paginare au title cu prefix „Pagina X - " | Pagination: Paginated 2+ Pages încrucișat cu Internal: All | Address, Title 1 | — (încrucișarea se face pe Address) |

### 2.8 FAQ + Schema FAQPage

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.8.1 | Secțiune FAQ vizibilă cu 7–10 întrebări pe paginile importante | nu se acoperă din SF | — | sursă: manual pe template-uri |
| 2.8.2 | JSON-LD FAQPage identic cu conținutul vizibil | Structured Data: Contains Structured Data; Structured Data: Validation Errors | Address + coloanele de tip de schemă ale exportului | comparația vizibil ↔ JSON-LD → fetch HTML pe template-uri |

### 2.9 Breadcrumbs + Schema BreadcrumbList

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.9.1 | Trail de breadcrumbs vizibil sub homepage | nu se acoperă din SF | — | sursă: manual pe template-uri |
| 2.9.2 | JSON-LD BreadcrumbList personalizat per pagină | Structured Data: Contains Structured Data | Address + tipurile de schemă detectate | personalizarea per pagină → fetch JSON-LD pe template-uri |
| 2.9.3 | Ultimul element din trail = text static (nu link) | nu se acoperă din SF | — | sursă: fetch HTML / manual pe template-uri |

### 2.10 Conținut on-page

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.10.1 | Paginile de categorie au descrieri proprii | Internal: All (filtrat pe URL-urile de categorie) | Address, Word Count | calitatea/unicitatea descrierii → manual [CONȚINUT] |
| 2.10.2 | Articolele lungi au cuprins clickabil | nu se acoperă din SF | — | sursă: manual pe eșantion de articole |
| 2.10.3 | Toate imaginile au alt text conform formulei | Images: Missing Alt Text; Images: Missing Alt Attribute + Bulk Export `Images:Images Missing Alt Text Inlinks` (confirmat pe SF 24.3) | Address; alt text-ul per imagine vine DOAR din bulk export — coloanele Source, Destination, Alt Text (exportul principal Images nu are coloană Alt Text) | conformitatea cu formula alt="[Titlu], [x], brand.ro" → script/manual pe alt-urile exportate |
| 2.10.4 | Articolele afișează autor, dată, categorie | Structured Data: Contains Structured Data (schema Article) | Address + tipurile detectate | afișarea vizibilă → manual pe template-uri |
| 2.10.5 | Referințele de an din conținut sunt actuale | nu se acoperă din SF | — | sursă: manual (opțional Custom Search SF pe anii vechi, [de verificat în SF]) |

### 2.11 Schema corectă per tip de pagină

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.11.1 | Paginile de produs au Product + Offer | Structured Data: Contains Structured Data; Structured Data: Missing | Address + tipurile de schemă detectate | prezența exactă Product+Offer per template → fetch JSON-LD |
| 2.11.2 | Articolele au schema Article | Structured Data: Contains Structured Data; Structured Data: Missing | Address + tipurile detectate | conținutul câmpurilor → fetch JSON-LD pe template |
| 2.11.3 | Organization/LocalBusiness DOAR pe homepage/contact | nu se acoperă direct din SF | — | sursă: fetch JSON-LD per template (homepage, contact, blog, articol) |
| 2.11.4 | Zero erori de validare pe datele structurate | Structured Data: Validation Errors; Structured Data: Rich Result Validation Errors (+ Validation Warnings, informativ) | Address + coloanele de erori ale exportului | — |

### 2.12 Paginare și categorii duplicate

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 2.12.1 | Paginile 2+ au self-canonical (nu spre pagina 1); /page/1 nu există | Pagination: Paginated 2+ Pages; Pagination: Non-Indexable + Canonicals: Canonicalised | Address, Canonical Link Element 1, Indexability | existența duplicatului /page/1 → fetch pe varianta /page/1 |
| 2.12.2 | Categoriile-duplicat au noindex,follow | Directives: Noindex | Address, Meta Robots 1 | lista categoriilor care dublează listing-ul → decizie manuală |
| 2.12.3 | URL-urile noindex sunt excluse din sitemap | Sitemaps: Non-Indexable URLs in Sitemap | Address, Indexability, Indexability Status | necesită Crawl Linked XML Sitemaps + Crawl Analysis |

## 03 RECOMANDĂRI TEHNICE

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 3.1 | O singură gazdă canonică, un singur 301 | Reports > Redirects > Redirect Chains | coloanele de lanț ale raportului | testul celor 4 variante (http/https × www/non-www) → fetch (curl -I pe fiecare variantă) |
| 3.2 | HTTPS complet, fără mixed content | Security: HTTP URLs; Security: Mixed Content | Address | — |
| 3.3 | Fără lanțuri/bucle de redirect intern | Reports > Redirects > Redirect Chains; Response Codes: Internal Redirect Chain; Response Codes: Internal Redirect Loop | Address, Status Code + coloanele raportului | — |
| 3.4 | Sitemap integru, cu lastmod real | Sitemaps: URLs in Sitemap; Sitemaps: XML Sitemap with over 50k URLs; Sitemaps: XML Sitemap over 50MB | Address | valorile lastmod și realismul lor → fetch sitemap + comparație cu datele reale de modificare (manual) |
| 3.5 | Imagini servite WebP cu srcset | Internal: Images | Address, Content Type | srcset → fetch HTML pe template-uri; confirmare „modern image formats" → PSI per URL (tab PageSpeed) |
| 3.6 | Lazy-load doar sub fold, LCP eager | tab PageSpeed (API PSI conectat) | coloanele CWV/Lighthouse per URL | atributele loading= din HTML → fetch HTML pe template-uri |
| 3.7 | Hărți/video încărcate la interacțiune | nu se acoperă din SF | — | sursă: manual pe paginile cu embed-uri |
| 3.8 | Pagină 404 customizată cu cod 404 real | nu se acoperă din SF | — | sursă: fetch pe URL inexistent (curl -I) — SF crawl-uiește doar URL-uri existente/linkuite |
| 3.9 | Search intern funcțional, cu rezultatele noindex | URL: Internal Search | Address | funcționalitatea căutării și noindex-ul pe rezultate → manual + fetch pe un URL de rezultate |
| 3.10 | Headerele de securitate prezente (HSTS, CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy) | Security: Missing HSTS Header; Security: Missing Content-Security-Policy Header; Security: Missing X-Content-Type-Options Header; Security: Missing X-Frame-Options Header; Security: Missing Secure Referrer-Policy Header | Address | — |

## 04 SEO OFF-SITE

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 4.1 | Profil Google Business activ, cu recenzii și răspunsuri | nu se acoperă din SF | — | sursă: manual (Google Maps / Google Business Profile) |
| 4.2 | Listări în directoarele relevante ale domeniului | nu se acoperă din SF | — | sursă: căutări web manuale |
| 4.3 | Mențiuni în presă / articole comparative | nu se acoperă din SF | — | sursă: căutări web manuale |
| 4.4 | Profiluri sociale cu NAP consecvent | nu se acoperă din SF | — | sursă: manual |
| 4.5 | Backlink-uri de la surse relevante | nu se acoperă din SF | — | sursă: GSC (raportul Linkuri) + verificare manuală |

**Notă:** SF oferă integrare Majestic/Ahrefs (`--use-majestic`, `--use-ahrefs`), dar
fluxul Simplead standard probează off-site-ul din GSC și verificări manuale, fără
abonamente suplimentare.

## 05 CRO

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 5.1 | Propunere de valoare clară în hero | nu se acoperă din SF | — | sursă: manual |
| 5.2 | CTA primar unic și consecvent pe funnel | nu se acoperă din SF | — | sursă: manual |
| 5.3 | Formularele afișează promisiune de timp de răspuns | nu se acoperă din SF | — | sursă: manual |
| 5.4 | Canal instant (chat/WhatsApp) | nu se acoperă din SF | — | sursă: manual |
| 5.5 | Dovezi sociale lângă punctele de decizie | nu se acoperă din SF | — | sursă: manual |
| 5.6 | Prețuri/intervale vizibile înainte de captarea lead-ului | nu se acoperă din SF | — | sursă: manual |
| 5.7 | Formulare fără câmpuri inutile | nu se acoperă din SF | — | sursă: manual |
| 5.8 | Pagină de mulțumire cu pașii următori | nu se acoperă din SF | — | sursă: manual |
| 5.9 | Evenimente GA4 pe conversii | nu se acoperă din SF | — | sursă: GA4 + verificare GTM manuală |
| 5.10 | (e-commerce) Guest checkout | nu se acoperă din SF | — | sursă: manual — NU SE APLICĂ la non-ecommerce |
| 5.11 | (e-commerce) Cost total vizibil devreme în checkout | nu se acoperă din SF | — | sursă: manual — NU SE APLICĂ la non-ecommerce |
| 5.12 | (e-commerce) Trust badges la checkout | nu se acoperă din SF | — | sursă: manual — NU SE APLICĂ la non-ecommerce |
| 5.13 | (e-commerce) Recenzii pe paginile de produs | nu se acoperă din SF | — | sursă: manual — NU SE APLICĂ la non-ecommerce |

**Observație:** deși CRO nu se probează din exporturi SF, crawl-ul rămâne util pentru
context: Internal: All dă inventarul complet al paginilor de funnel, iar coloanele GA4
per landing page (cu API-ul conectat) arată unde pierderile de conversie contează.

## 06 LLM / AEO / GEO

User-agenții verificați: GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-User,
PerplexityBot, Google-Extended, bingbot.

| ID | Verificarea (scurt) | Export SF | Coloane care o probează | Ce NU acoperă SF → sursă alternativă |
|---|---|---|---|---|
| 6.1 | robots.txt permite cei 8 user-agenți AI | nu se acoperă din SF | — | sursă: fetch /robots.txt (parsare reguli per user-agent) |
| 6.2 | Serverul/WAF-ul nu blochează user-agenții AI (test efectiv) | nu se acoperă din SF | — | sursă: fetch cu curl -A per user-agent (200 vs 403/challenge) |
| 6.3 | Conținutul critic prezent în HTML-ul inițial, fără dependență de JS | dublu-crawl: Internal: All din crawl Text Only vs Internal: All din crawl JavaScript (Configuration > Spider > Rendering) | Address, Word Count, H1-1, Title 1 — diff per URL între cele două exporturi | confirmare punctuală → fetch raw pe template-uri |
| 6.4 | Indexarea confirmată în Bing Webmaster Tools | nu se acoperă din SF | — | sursă: Bing WT (manual) |
| 6.5 | llms.txt există și e curat | nu se acoperă din SF | — | sursă: fetch /llms.txt |
| 6.6 | Schemă de entitate consecventă (Organization pe homepage, sameAs) | nu se acoperă direct din SF | — | sursă: fetch JSON-LD homepage/contact |
| 6.7 | FAQ conversațional pe paginile cheie | nu se acoperă din SF | — | sursă: manual (leagă de 2.8) |
| 6.8 | Rezumat răspuns-direct la începutul paginilor importante | nu se acoperă din SF | — | sursă: manual pe template-uri |
| 6.9 | Prezență off-site citabilă de LLM-uri | nu se acoperă din SF | — | sursă: căutări web (leagă de 04) |
| 6.10 | Set de prompturi de monitorizare pe categoriile de interes | nu se acoperă din SF | — | sursă: livrabil produs de auditor (manual) |

---

## Bilanț de acoperire

- **Total verificări: 82** (SEO on-site 44, tehnic 10, off-site 5, CRO 13, LLM/AEO 10).
- **Probate integral sau parțial din exporturi SF: 44** — 35 din secțiunea 02, 8 din
  secțiunea 03 (fără 3.7 și 3.8), plus 6.3.
- **Fără acoperire SF (sursă exclusiv alternativă): 38** — 2.5.1, 2.6.2, 2.6.3, 2.8.1,
  2.9.1, 2.9.3, 2.10.2, 2.10.5, 2.11.3 (9 din secțiunea 02), 3.7 și 3.8, întreaga
  secțiune 04 (5), întreaga secțiune 05 (13) și secțiunea 06 fără 6.3 (9).

**Notă:** exporturile care depind de precondiții de configurare (Sitemaps → Crawl
Linked XML Sitemaps + Crawl Analysis; Structured Data → extraction + validation;
Orphan Pages → GSC/GA4 + Crawl Analysis; PageSpeed → API PSI) sunt marcate în tabele.
Dacă o precondiție a lipsit la crawl, verificarea NU se declară „NU EXISTĂ" pe baza
unui export gol — se reface crawl-ul cu configurația corectă.
