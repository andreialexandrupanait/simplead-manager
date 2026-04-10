# Modul SEO — Specificație Funcțională Completă
**Platform:** SimpleAd Manager  
**Stack:** Laravel 11 / PHP 8.3 · Livewire 4 · PostgreSQL · Redis · Horizon  
**Versiune document:** 1.0 — Aprilie 2026  
**Status:** Draft pentru implementare

---

## 0. Principii de Design ale Modulului

### 0.1 Dualitate: Global ↔ Per-Site

Modulul SEO există în **două planuri simultane** care se oglindesc și se alimentează reciproc:

```
SIDEBAR PRINCIPAL (Global)          PAGINA PER-SITE (Contextual)
─────────────────────────           ──────────────────────────────
🔍 SEO                              /sites/{id}/seo
 ├─ Dashboard Global                 ├─ Overview SEO
 ├─ Crawler                          ├─ Audit SEO
 ├─ Keyword Research                 ├─ Crawl Site
 ├─ Conținut AI                      ├─ Keyword Tracking
 ├─ Calendar Editorial               ├─ Conținut AI (filtrat pe site)
 └─ Setări SEO                       └─ Raport SEO
```

**Regula de aur:** Orice acțiune pornită din contextul unui site rămâne legată de acel site. Orice acțiune pornită din modulul global poate fi asociată sau rămâne standalone.

### 0.2 Auto-detecție Site Cunoscut

Când utilizatorul introduce un URL în crawler sau keyword research:

```
URL introdus → normalizare (strip www, trailing slash)
      │
      ▼
   ┌─────────────────────────────────────┐
   │  SELECT * FROM sites                │
   │  WHERE domain ILIKE '%{host}%'      │
   └─────────────────────────────────────┘
      │
  ┌───┴───┐
  │ Găsit │ → badge "Site cunoscut: {nume}" + opțiune asociere automată
  │       │   → rezultatele crawl-ului se salvează și în tab-ul per-site
  └───────┘
      │
  ┌───┴────────┐
  │ Negăsit   │ → mod standalone pur, fără asociere
  └────────────┘
```

---

## 1. Navigare — Sidebar Principal

```
╔══════════════════════════════╗
║  SIDEBAR PRINCIPAL           ║
╠══════════════════════════════╣
║  Dashboard                   ║
║  Sites                       ║
║  Clienți                     ║
║  Backup & Recovery           ║
║  Monitoring                  ║
║  Securitate                  ║
║  Rapoarte                    ║
║ ┌──────────────────────────┐ ║
║ │  🔍  SEO            [N]  │ ║  ← Modul nou, entry point principal
║ │   ├─ Dashboard           │ ║
║ │   ├─ Crawler             │ ║
║ │   ├─ Keyword Research    │ ║
║ │   ├─ Conținut AI         │ ║
║ │   └─ Calendar Editorial  │ ║
║ └──────────────────────────┘ ║
║  Status Pages                ║
║  Notificări              [3] ║
║  Setări                      ║
╚══════════════════════════════╝
```

> **[N]** = badge cu numărul de crawl-uri active sau articole în așteptare publicare

---

## 2. Dashboard SEO Global

**Rută:** `/seo`

Vizualizare de ansamblu a tuturor activităților SEO din platformă, indiferent de site.

### 2.1 Carduri KPI (rând superior)
| Card | Valoare | Trend |
|---|---|---|
| Site-uri monitorizate SEO | N din totalul site-urilor | — |
| Crawl-uri active acum | N jobs running | — |
| Issues critice nerezolvate | N (agregat toate site-urile) | ↑↓ față de săptămâna trecută |
| Articole AI publicate luna aceasta | N | ↑↓ față de luna trecută |
| Keywords tracked (total) | N | — |
| Scor SEO mediu (toate site-urile) | 0–100 | ↑↓ |

### 2.2 Tabel Site-uri cu Status SEO
Coloane: Favicon + Nume Site · Client · Scor SEO · Issues Critice · Issues Warnings · Ultimul Crawl · Keywords Tracked · Articole AI · Acțiuni

Acțiuni rapide per rând:
- **Crawl acum** → pornește crawl cu setările default ale site-ului
- **Generează articol** → deschide generator AI pre-completat cu site-ul
- **Vezi detalii** → navighează la `/sites/{id}/seo`

### 2.3 Activitate Recentă
Feed cronologic: crawl-uri finalizate, articole publicate, keywords cu mișcare semnificativă de poziție (>5 locuri), issues noi detectate.

### 2.4 Grafice Globale
- Evoluție scor SEO mediu (ultimele 6 luni)
- Distribuție issues pe categorii (stacked bar)
- Top 5 site-uri cu cele mai multe issues critice

---

## 3. Crawler SEO (Screaming Frog Engine)

**Rută globală:** `/seo/crawler`  
**Rută per-site:** `/sites/{id}/seo/crawler`

### 3.1 Listing Crawl-uri

Tabel cu toate crawl-urile din platformă (sau filtrat pe site în context per-site).

**Coloane:**
- Nume crawl / URL Start
- Site asociat (badge "Cunoscut" sau "Standalone")
- Client (dacă asociat)
- Status: `Queued` · `Running` (cu %) · `Paused` · `Completed` · `Failed`
- Pagini crawlate / Total estimat
- Issues Critice · Warnings
- Durată
- Data
- Acțiuni: Vezi · Recrawl · Compară · Export · Șterge

**Filtre:**
`Toate` · `Running` · `Completed` · `Failed` · `Standalone` · `Site cunoscut` · `[Client]`

**Buton principal:** `+ Crawl Nou`

---

### 3.2 Configurare Crawl Nou

Modal sau pagină dedicată `/seo/crawler/create`.

```
┌─────────────────────────────────────────────────────┐
│  Crawl Nou                                          │
├─────────────────────────────────────────────────────┤
│                                                     │
│  URL Start *                                        │
│  ┌─────────────────────────────────────────────┐   │
│  │ https://                                    │   │
│  └─────────────────────────────────────────────┘   │
│  ✅ Site cunoscut detectat: "Exemplu SRL"           │
│     → Asociez automat rezultatele la acest site     │
│                                                     │
│  Nume crawl (opțional)                              │
│  ┌─────────────────────────────────────────────┐   │
│  │ ex: Audit initial Q2 2026                   │   │
│  └─────────────────────────────────────────────┘   │
│                                                     │
│  ── Configurare de Bază ──────────────────────────  │
│                                                     │
│  Limită URL-uri      Adâncime max    Viteză crawl   │
│  [Nelimitat  ▼]      [5        ]     [Normal  ▼]    │
│                                                     │
│  User-Agent          JS Rendering                   │
│  [Googlebot   ▼]     [ ] Activat (Puppeteer)        │
│                                                     │
│  Respectă robots.txt: [✅]                          │
│                                                     │
│  [▼ Setări Avansate]                                │
│  ┌─────────────────────────────────────────────┐   │
│  │ Scope: ◉ Domain only ○ + Subdomenii ○ Regex │   │
│  │ Include patterns: [________________]  [+ ]  │   │
│  │ Exclude patterns: [________________]  [+ ]  │   │
│  │ Auth: ○ Niciuna ○ Basic Auth ○ Cookie       │   │
│  │ Headers custom: [Key] [Value]         [+ ]  │   │
│  │ Custom extraction: [+ Adaugă selector]      │   │
│  └─────────────────────────────────────────────┘   │
│                                                     │
│  [Anulare]                    [▶ Pornire Crawl]     │
└─────────────────────────────────────────────────────┘
```

**Setări viteză crawl:**
| Opțiune | Requests/secundă | Folosit când |
|---|---|---|
| Politicos | 1 req/s | Site-uri cu hosting slab |
| Normal | 5 req/s | Default |
| Rapid | 15 req/s | Site-uri robuste / propriile site-uri |

---

### 3.3 Rezultate Crawl — Pagina Principală

**Rută:** `/seo/crawler/{session}`

**Header sesiune:**
- URL start · Site asociat (dacă există) · Status · Data · Durată · Pagini crawlate
- Butoane: `Recrawl` · `Compară cu...` · `Export XLS` · `Export CSV` · `Raport PDF`

**Navigare tab-uri:**

```
[Overview] [Pagini] [Link-uri] [Imagini] [Issues] [Structured Data] [Vizualizare] [Comparație]
```

---

#### TAB: Overview

**4 carduri KPI:**
- Total URL-uri crawlate
- Issues Critice 🔴
- Warnings 🟠
- Pagini Indexabile

**Distribuție Status Codes** (donut chart):
- 2xx (OK) · 3xx (Redirecturi) · 4xx (Client errors) · 5xx (Server errors)

**Issues pe categorii** (bar orizontal, sortat descrescător):

| Categorie | Critice | Warnings |
|---|---|---|
| Broken Links | N | — |
| Meta & Titles | — | N |
| Duplicate Content | N | N |
| Redirecturi | — | N |
| Imagini | — | N |
| Hreflang | N | N |
| Structured Data | — | N |
| Performance | — | N |
| Accesibilitate | — | N |

**Statistici generale:**
- Distribuție adâncime pagini (histogram: câte pagini per nivel 1–10+)
- Top 10 pagini cu cele mai multe link-uri interne primite
- Response time distribution (< 200ms / 200–500ms / 500ms–2s / > 2s)
- Distribuție dimensiuni pagini

---

#### TAB: Pagini

Tabel cu **toate URL-urile crawlate**. Exportabil complet în XLS.

**Coloane disponibile** (toggle vizibilitate per coloană):

| Coloană | Tip | Note |
|---|---|---|
| URL | text + link | Truncat, click = deschide |
| Status Code | badge colorat | 200=verde, 3xx=albastru, 4xx=roșu, 5xx=roșu închis |
| Indexable | ✓/✗ | robots + noindex + canonical check |
| Title | text | — |
| Title Length | number | Roșu dacă <30 sau >60 |
| Meta Description | text | — |
| Meta Desc. Length | number | Roșu dacă <80 sau >160 |
| H1 | text | Prima H1 |
| H1 Count | number | Roșu dacă >1 |
| H2 Count | number | — |
| Word Count | number | — |
| Canonical | URL | — |
| Meta Robots | text | — |
| Hreflang | text | — |
| Response Time | ms | Roșu dacă >2000 |
| Page Size | KB | — |
| Inlinks | number | Link-uri interne primite |
| Outlinks | number | Link-uri interne trimise |
| External Links | number | — |
| Depth | number | — |
| Content Hash | text | Pentru duplicate detection |
| Crawled At | datetime | — |

**Filtre rapide (pills):**
`Toate` · `2xx` · `3xx` · `4xx` · `5xx` · `Noindex` · `Duplicate Exact` · `Near Duplicate` · `Fără Title` · `Title prea lung` · `Fără Meta Desc` · `Fără H1` · `Multiple H1` · `Slow (>2s)` · `Adâncime >5`

**Click pe orice URL** → drawer lateral cu toate detaliile paginii: toate câmpurile, link-uri primite, link-uri trimise, imagini, structured data, issues detectate pe această pagină.

---

#### TAB: Link-uri

Sub-tabs: `Toate` · `Interne` · `Externe` · `Broken` · `Redirecturi` · `Nofollow`

**Coloane:**
- URL Sursă · URL Destinație · Anchor Text · Status Code · Tip (href/src/redirect/canonical) · Is Nofollow · Is Internal

---

#### TAB: Imagini

Sub-tabs: `Toate` · `Broken` · `Fără Alt Text` · `Mari (>200KB)` · `Fără Width/Height`

**Coloane:**
- Pagina Sursă · URL Imagine · Alt Text · Title · Width · Height · Size (KB) · Status Code

---

#### TAB: Issues

Grupate pe **severitate** și **categorie**. Fiecare issue are:
- Titlu + descriere explicativă (ce înseamnă, de ce contează)
- Badge severitate: 🔴 Critică / 🟠 Warning / 🔵 Info
- Nr. pagini afectate + link "Vezi toate paginile"
- **Buton "Creează task"** → deschide task management cu issue pre-completat
- **Buton "Marchează ca ignorat"** → scoate din raportare viitoare

**Lista completă issues detectate:**

🔴 **CRITICE**
- Broken internal links (4xx/5xx)
- Pagini cu eroare server (5xx)
- Redirect loops detectate
- Redirect chains > 3 hops
- Pagini fără `<title>`
- Duplicate `<title>` exact (mai mult de 2 pagini identice)
- Canonical care pointează spre pagini inexistente sau broken
- Pagini blocate în robots.txt dar linkate intern
- Broken images (4xx/5xx)
- Pagini cu noindex în zona principală dar cu inlinks importante
- Missing hreflang return tags
- Structured data cu erori critice de sintaxă JSON-LD

🟠 **WARNINGS**
- Title prea lung (>60 caractere)
- Title prea scurt (<30 caractere)
- Meta description lipsă
- Meta description prea lungă (>160 caractere)
- Meta description prea scurtă (<80 caractere)
- Duplicate meta descriptions
- Duplicate content exact (hash identic)
- Near-duplicate content (similaritate ≥80%)
- Multiple H1 pe aceeași pagină
- Pagini fără H1
- H1 identic cu title (oportunitate de diferențiere pierdută)
- Redirect 302 (temporar) pe resurse permanente
- Hreflang cu URL-uri broken
- Hreflang cu coduri limbă invalide (non ISO 639-1)
- Imagini fără atribut alt
- Imagini fără width/height (layout shift)
- Imagini prea mari (>200KB) fără compresie
- Pagini cu response time > 2s
- Pagini cu adâncime > 5 niveluri
- Pagini cu prea puțin conținut (<200 cuvinte)
- Structured data cu warnings (câmpuri recomandate lipsă)
- Broken external links
- Pagini cu meta refresh (`<meta http-equiv="refresh">`)
- Pagini fără canonical self-referencing
- External links cu redirecturi

🔵 **INFO**
- External links nofollow
- Pagini cu X-Robots-Tag in header
- AMP version prezentă/absentă
- Pagini cu parametri UTM în URL-uri interne
- Title între 30–50 caractere (funcțional, dar optimizabil)
- Imagini fără atribut title
- Pagini fără Open Graph tags
- OG tags fără og:image
- Pagini fără Twitter Card tags
- Heading hierarchy inconsistentă (H1→H3 fără H2)
- Link-uri interne cu anchor text "click here" / "aici" / "mai mult"

---

#### TAB: Structured Data

- Listing tipuri Schema.org găsite pe site (Article, Product, FAQ, BreadcrumbList, etc.)
- Per tip: nr. pagini valide · nr. pagini cu erori · exemple pagini
- Detaliu per pagină: JSON-LD raw + mesaje de eroare/warning Google Rich Results style

---

#### TAB: Vizualizare

**Selector:** `Tree View` / `Force Graph`

- **Tree View:** Arbore expandabil pe niveluri de adâncime, noduri colorate după status code
- **Force Graph:** Vizualizare interactive a link-urilor interne (noduri = pagini, muchii = linkuri). Dimensiunea nodului = nr. inlinks primite.

---

#### TAB: Comparație

- Dropdown: "Compară cu crawl din [data]" — listează toate crawl-urile anterioare ale aceluiași domeniu
- **Diff Dashboard:**
  - Issues noi detectate (față de crawl anterior)
  - Issues rezolvate
  - Issues persistente
  - Pagini noi / dispărute
  - Evoluție metrici: broken links, duplicate pages, avg response time, scor general

---

### 3.4 Export XLS — Structură Fișier

Fișier `.xlsx` cu multiple sheets, identic ca logică cu exportul Screaming Frog:

| Sheet | Conținut |
|---|---|
| **Overview** | Statistici generale crawl, KPI-uri, distribuție status codes |
| **Internal** | Toate URL-urile interne crawlate + toate coloanele |
| **External** | Toate link-urile externe găsite |
| **Images** | Toate imaginile + status + alt text + dimensiuni |
| **CSS** | Fișiere CSS găsite |
| **JavaScript** | Fișiere JS găsite |
| **Broken Links** | Doar URL-urile broken (4xx/5xx) cu sursă + anchor |
| **Redirects** | Toate redirecturile + lanțuri complete |
| **Page Titles** | Analiza completă titluri (duplicate, lungime) |
| **Meta Description** | Analiza completă meta descriptions |
| **H1** | Toate paginile cu H1-urile lor |
| **Hreflang** | Toate valorile hreflang per pagină |
| **Canonical** | URL canonical per pagină + status canonical |
| **Structured Data** | Schema.org per pagină |
| **Issues Critical** | Toate issue-urile critice |
| **Issues Warnings** | Toate warning-urile |
| **Custom Extractions** | Date extrase cu selectori custom (dacă configurat) |

---

### 3.5 Crawl-uri Programate (per-site)

Disponibile în `/sites/{id}/seo/crawler` → tab "Programare"

| Câmp | Opțiuni |
|---|---|
| Frecvență | Manual / Zilnic / Săptămânal / Lunar |
| Zi/oră execuție | Configurabil |
| Comparație automată | ✓ Compară automat cu crawl-ul anterior |
| Notificare la finalizare | Email · Slack · Discord · Telegram (canalele existente) |
| Notificare doar dacă | Issues noi critice / Întotdeauna / Niciodată |
| Config crawl | Salvată per site (refolosită la fiecare rulare) |

---

## 4. Keyword Research

**Rută globală:** `/seo/keywords`  
**Rută per-site:** `/sites/{id}/seo/keywords`

### 4.1 Viziune

Modulul operează în două straturi:
- **Research** → descoperă și analizează cuvinte-cheie noi
- **Tracking** → monitorizează pozițiile cuvintelor-cheie existente ale unui site

### 4.2 Research — Descoperire Keywords

**Input:**
- Seed keyword(s) sau domeniu competitor
- Limbă + țară target
- (Opțional) Asociere site

**Surse de date (în ordinea priorității implementării):**

| Sursă | Date furnizate | Integrare |
|---|---|---|
| **Google Search Console** | Keywords reale pe care site-ul apare deja, CTR, poziție | API OAuth existent |
| **DataForSEO API** | Volum, CPC, dificultate, SERP features, keywords similare | API key (pay-per-use) |
| **Google Ads Keyword Planner** | Volum, concurență, CPC | API via OAuth Google existent |
| **Fallback: Sugestii Google** | Autocomplete + "People also ask" + Related searches | Scraping politic |

**Output — tabel rezultate:**

| Coloană | Descriere |
|---|---|
| Keyword | Cuvântul cheie |
| Volum lunar | Căutări/lună (național/global) |
| Dificultate | 0–100 (KD score) |
| CPC | Cost per click estimat |
| Trend | Grafic sparkline 12 luni |
| Intenție | Informațional · Navigațional · Comercial · Tranzacțional |
| SERP Features | Snippet · PAA · Shopping · Maps · etc. |
| Poziție curentă | Dacă site-ul e asociat și conectat la GSC |
| Acțiuni | Adaugă la tracking · Generează articol · Salvează în listă |

**Funcționalități research:**
- Grupare automată keywords pe topicuri/clustere
- Keyword gap analysis: compară keywords unui site cu ale unui competitor
- Long-tail suggestions per keyword seed
- Filtre: volum min/max · dificultate max · intenție · include/exclude cuvinte

### 4.3 Tracking — Monitorizare Poziții

Disponibil per site (keywords asociate unui site specific).

**Adăugare keywords tracked:**
- Manual (input individual sau bulk paste)
- Import din research (buton "Adaugă la tracking")
- Import CSV

**Tabel tracking:**

| Coloană | Descriere |
|---|---|
| Keyword | — |
| Poziție curentă | Locul în Google pentru URL-ul target |
| URL rankat | Pagina care rankează pentru keyword |
| Poziție anterioară | Față de ultima verificare |
| Mișcare | ↑ N / ↓ N / = (cu trend colorat) |
| Best position | Cel mai bun loc atins vreodată |
| Volum | — |
| Pagina 1 / Top 3 / Top 10 | Badge dacă e în acele zone |
| Ultima verificare | — |

**Grafic evoluție:** Selecție keywords → grafic pozițiile în timp (multi-line chart).

**Verificare automată pozitii:** La interval configurabil (zilnic recomandat).  
**Sursă date poziții:** Google Search Console API (primar) + verificare SERP directă (fallback).

**Notificări tracking:**
- Keyword a intrat în Top 10 / Top 3 / Locul 1
- Keyword a căzut cu mai mult de N locuri
- Keyword a ieșit din Top 100

---

## 5. Conținut AI — Generator Articole

**Rută globală:** `/seo/content`  
**Rută per-site:** `/sites/{id}/seo/content`

### 5.1 Filozofie

Nu un simplu "AI writer" — ci un **workflow complet** de la idee la publicare pe WordPress, cu control total asupra calității și tonului.

```
IDEE / KEYWORD
     │
     ▼
[Brief Articol]  ←  configurare manuală sau auto din keyword research
     │
     ▼
[Generare AI]    ←  Claude API (existent în platformă — Incident Response)
     │
     ▼
[Review & Edit]  ←  editor rich text in-platform
     │
     ▼
[Programare]     ←  dată + oră publicare
     │
     ▼
[Publicare]      ←  WP Connector → endpoint /posts (nou în plugin)
     │
     ▼
[Monitorizare]   ←  tracking poziție keyword target post-publicare
```

### 5.2 Brief Articol — Câmpuri de Configurare

**Secțiunea: Target**
| Câmp | Tip | Note |
|---|---|---|
| Site destinație * | Select | Site-urile din platformă cu WP Connector activ |
| Keyword principal * | Text | Keyword-ul pentru care optimizăm articolul |
| Keywords secundare | Tags | 3–5 keywords relevante |
| Intenție articol | Select | Informațional · How-to · Listicle · Comparativ · Review · Landing Page |
| Limbă | Select | Română (default) · Engleză · Altele |

**Secțiunea: Structură**
| Câmp | Tip | Note |
|---|---|---|
| Număr cuvinte target | Slider | 500 / 800 / 1200 / 1500 / 2000 / 3000 / Custom |
| Heading-uri dorite | Text | Utilizatorul poate specifica H2/H3 sau lasă AI să genereze |
| Include secțiuni | Checkbox | Introducere · TL;DR · FAQ · Concluzie · Call to Action |
| Densitate keyword | Select | Naturală (recomandat) · Agresivă · Minimă |

**Secțiunea: Ton & Stil**
| Câmp | Tip | Note |
|---|---|---|
| Ton | Select | Profesional · Casual · Autoritar · Prietenos · Educational · Conversațional |
| Persoana narativă | Select | Persoana I (eu/noi) · Persoana III · Neutru |
| Public țintă | Text | ex: "antreprenori mici", "mame cu copii 0-3 ani" |
| Evită | Text | Cuvinte/fraze de evitat (ex: "în concluzie", "în final") |

**Secțiunea: SEO On-Page**
| Câmp | Tip | Note |
|---|---|---|
| Meta Title | Text | Auto-generat, editabil. Counter caractere (max 60) |
| Meta Description | Textarea | Auto-generată, editabilă. Counter caractere (max 160) |
| Slug URL | Text | Auto-generat din keyword, editabil |
| Featured Image | File/URL | Upload sau URL extern |
| Schema.org Type | Select | Article · HowTo · FAQ · Review · None |

**Secțiunea: Publicare**
| Câmp | Tip | Note |
|---|---|---|
| Status la publicare | Select | Draft · Pending Review · Publish |
| Data publicare | Datetime | Imediată sau programată |
| Categorie WP | Select | Adusă live din site via WP Connector |
| Tag-uri WP | Tags | Aduse live sau introduse manual |
| Autor WP | Select | Adus live din site (utilizatorii WordPress) |

### 5.3 Generare AI

**Engine:** Claude API (același model folosit deja în Incident Response).

**Prompt system intern (template customizabil în Setări SEO):**
```
Ești un copywriter SEO expert în română. Scrii conținut de calitate înaltă,
optimizat pentru motoarele de căutare, dar natural și plăcut de citit.

Reguli:
- Folosești keyword-ul principal [KEYWORD] natural, de ~[DENSITATE] ori
- Incluzi keywords secundare: [KEYWORDS_SECUNDARE]
- Structura: [HEADINGS_DORITE]
- Ton: [TON], adresare: [PERSOANA]
- Public țintă: [PUBLIC]
- Lungime: ~[NUMAR_CUVINTE] cuvinte
- Evită: [EVITA]
- Formatezi cu Markdown: H2, H3, bold pentru termeni importanți, liste acolo unde ajută
- La final, generezi separat: meta title (max 60 chr) și meta description (max 160 chr)
```

**Flux generare:**
1. Utilizatorul apasă **"Generează"**
2. Progress indicator live (streaming response)
3. Articolul apare în editor rich text (editabil)
4. Dacă utilizatorul nu e mulțumit: **"Regenerează"** (întreg articolul) sau **"Regenerează secțiunea"** (selectează text → click)
5. Score SEO on-page calculat live pe măsură ce editează

### 5.4 Editor Rich Text

- Bazat pe **Trix** (deja în Laravel ecosystem) sau **TipTap** (recomandat pentru features avansate)
- Toolbar: Bold · Italic · H1-H4 · Liste · Link · Image · Quote · Code · Undo/Redo
- **Panel lateral SEO (live):**

```
┌─────────────────────────────────┐
│  SEO Score: 78/100  🟠          │
├─────────────────────────────────┤
│  ✅ Keyword în title (H1)       │
│  ✅ Meta title OK (54 chr)      │
│  ✅ Meta desc OK (148 chr)      │
│  🟠 Keyword density: 0.4%       │
│     (recomandat: 0.8–1.5%)      │
│  ✅ Conținut > 1000 cuvinte     │
│  ❌ Niciun link intern          │
│  ✅ Alt text pe toate imaginile │
│  🟠 Fără secțiune FAQ           │
│  ✅ Heading hierarchy OK        │
└─────────────────────────────────┘
```

### 5.5 Listing Articole Generate

**Coloane tabel:**
- Titlu articol
- Site destinație
- Keyword principal
- Status: `Draft` · `Schedulat` · `Publicat` · `Eroare publicare`
- Data programată / Data publicată
- Score SEO
- Autor
- Acțiuni: Editează · Publică acum · Reprogramează · Duplicate · Șterge

**Filtre:** `Toate` · `Draft` · `Schedulat` · `Publicat` · `Eroare` · `[Site]` · `[Client]`

---

## 6. Calendar Editorial

**Rută:** `/seo/calendar`

Vizualizare calendar (lunar/săptămânal) cu toate articolele programate din toate site-urile.

**Elemente pe calendar:**
- Fiecare articol = card colorat (culoare per site sau per client)
- Click pe card → drawer cu preview articol + acțiuni (editează/publică/reprogramează)
- Drag & drop pentru reprogramare
- **Vizualizare pe site:** Filtru site → calendar dedicat per site (disponibil și în `/sites/{id}/seo/calendar`)

**Indicatori pe calendar:**
- Zile cu publicări programate: punct colorat
- Zile cu publicări ratate (eroare): punct roșu
- Frecvență publicare recomandată: overlay vizual (dacă admin configurează target)

---

## 7. Modul SEO Per-Site (Îmbunătățit față de v7 actual)

**Rută:** `/sites/{id}/seo`

Păstrează structura actuală (7.1–7.6) și adaugă:

### Tab-uri noi adăugate:
- **Crawler** → acces direct la crawl-urile asociate acestui site + buton "Crawl nou"
- **Conținut AI** → articolele generate pentru acest site + buton "Articol nou"
- **Calendar** → calendarul editorial al acestui site

### Îmbunătățiri la tab-urile existente:

**7.2 Audit SEO** (actual: 10 verificări manuale)
- Rămâne intact ca audit rapid/manual
- Se adaugă: buton "Rulează crawl complet" care pornește crawler-ul și populează automat secțiunile relevante din audit

**7.5 Crawl Site** (actual: rudimentar)
- Înlocuit complet cu engine-ul nou — redirectează la `/sites/{id}/seo/crawler`

**7.3 Tracking Cuvinte Cheie** (actual: basic)
- Înlocuit cu noul modul Keyword Research (view filtrat per site)

---

## 8. Setări SEO Global

**Rută:** `/seo/settings` (sau în `/settings` → tab SEO)

| Secțiune | Setări |
|---|---|
| **Crawler** | User-agent default · Viteză default · Limită URL default · Timeout request · Concurență maximă jobs simultane |
| **JS Rendering** | Activat/dezactivat global · Max browsere Puppeteer simultane |
| **Retenție date** | Păstrează crawl-uri: 6 luni / 12 luni / 24 luni / Nelimitat |
| **Keyword Research** | API keys: DataForSEO · Interval verificare poziții default |
| **Conținut AI** | Prompt system default (editabil) · Model AI · Max tokens · Tone default · Lungime default |
| **Publicare** | Delay între publicări (anti-spam WP) · Status default la publicare |
| **Notificări SEO** | Canal notificări crawl finalizat · Canal notificări keyword mișcare · Praguri alertă |

---

## 9. Integrare cu Modulele Existente

### 9.1 → Rapoarte (Modul 10)

Secțiunea SEO din raport se îmbogățește cu:
- Scor SEO crawl (față de crawl anterior)
- Issues critice noi / rezolvate în perioada raportată
- Evoluție poziții keywords tracked
- Articole publicate în perioadă (cu link-uri)
- Grafic evoluție scor SEO ultimele 6 luni

### 9.2 → Notificări (Modul 14)

Evenimente noi adăugate în sistemul de notificări:
- Crawl finalizat (cu sumar issues)
- Crawl eșuat
- Issues critice noi detectate față de crawl anterior
- Keyword intrat în Top 10 / Top 3 / Locul 1
- Keyword căzut cu > N locuri
- Articol publicat cu succes
- Articol — eroare la publicare (acțiune necesară!)
- Articol programat în mai puțin de 24h (reminder)

### 9.3 → Incident Response AI (Modul 15)

Playbook nou: **"SEO Critical Drop"**
- Trigger: scor SEO scade cu >20 puncte față de crawl anterior SAU apar >10 issues critice noi
- Acțiuni automate: colectare context (crawl data, GSC data) → analiză Claude → recomandări specifice → notificare echipă

### 9.4 → WordPress Connector Plugin (Modul 18)

Endpoint-uri noi necesare în plugin (v2.11.0):

| Endpoint | Metodă | Funcționalitate |
|---|---|---|
| `/posts` | POST | Creare post nou cu toate metadatele |
| `/posts/{id}` | PUT | Actualizare post existent |
| `/posts/{id}/schedule` | POST | Programare publicare la dată/oră |
| `/posts/categories` | GET | Listare categorii disponibile |
| `/posts/tags` | GET | Listare tag-uri disponibile |
| `/media/upload` | POST | Upload featured image |
| `/yoast` sau `/rankmath` | GET/POST | Setare meta SEO via plugin SEO WordPress |

### 9.5 → Health Score Site (Dashboard principal)

Scorul de sănătate al unui site (carduri din Dashboard) include acum componenta SEO:

```
Health Score = (Uptime × 0.25) + (Security × 0.25) +
               (Performance × 0.20) + (SEO × 0.20) +
               (Backup × 0.10)

SEO Score = 100 - (issues_critice × 5 + issues_warnings × 2 + issues_info × 0.5)
            [clamped 0–100]
```

---

## 10. Arhitectură Tehnică (Laravel Stack)

### 10.1 Modele Eloquent noi

```
CrawlSession        → sesiunile de crawl
CrawlPage           → fiecare URL crawlat
CrawlLink           → link-urile găsite
CrawlImage          → imaginile găsite
CrawlIssue          → issues detectate (agregate)
CrawlSchedule       → crawl-uri programate per site
CrawlCustomExtraction → extracții custom

SeoKeyword          → keywords tracked per site
SeoKeywordPosition  → istoricul pozițiilor (time series)
SeoKeywordResearch  → sesiuni de research (cache rezultate)

SeoContent          → articolele generate/în progress
SeoContentRevision  → versiuni articol (pentru undo)
SeoContentPublishLog → log publicări (succes/eroare)
```

### 10.2 Jobs Asincrone noi (Horizon)

```
CrawlPageJob        → procesare individuală URL (dispatchat per pagină)
CrawlAggregateJob   → agregare issues după finalizare crawl
CrawlScheduleJob    → dispatcher crawl-uri programate
KeywordPositionCheckJob → verificare poziții keywords
SeoContentPublishJob    → publicare articol pe WordPress la ora programată
SeoContentGenerateJob   → generare AI (dacă async)
```

### 10.3 Cron Jobs noi (în Schedulatorul Laravel existent)

```php
// Crawl-uri programate — verificare la fiecare 5 minute
$schedule->job(new CrawlScheduleDispatcher)->everyFiveMinutes();

// Verificare poziții keywords — zilnic
$schedule->job(new KeywordPositionCheckDispatcher)->dailyAt('06:00');

// Publicare articole programate — la fiecare minut
$schedule->job(new SeoContentPublishDispatcher)->everyMinute();

// Cleanup crawl-uri vechi (conform politică retenție)
$schedule->job(new CrawlRetentionCleanup)->weeklyOn(0, '04:00');
```

### 10.4 Componente Livewire noi (estimat)

| Componentă | Descriere |
|---|---|
| `SeoDashboard` | Dashboard global SEO |
| `CrawlerIndex` | Listing crawl-uri |
| `CrawlerCreate` | Modal/form configurare crawl nou |
| `CrawlerSession` | Pagina rezultate crawl (cu toate tab-urile) |
| `CrawlerPagesTable` | Tabel pagini cu filtre (separat pentru performanță) |
| `CrawlerLinksTable` | Tabel link-uri |
| `CrawlerImagesTable` | Tabel imagini |
| `CrawlerIssuesList` | Lista issues grupate |
| `CrawlerVisualisation` | Componenta grafuri (JS interactiv) |
| `CrawlerComparison` | Diff între două crawl-uri |
| `KeywordResearch` | Interfața research keywords |
| `KeywordTracking` | Tabel tracking + grafice poziții |
| `SeoContentIndex` | Listing articole generate |
| `SeoContentEditor` | Editor complet cu panel SEO lateral |
| `SeoContentGenerator` | Form brief + progress generare |
| `SeoCalendar` | Calendar editorial |
| `SeoSettings` | Setări globale modul |
| `SiteSeoDashboard` | Tab SEO în pagina per-site |

### 10.5 Export XLS

Folosind **Laravel Excel (Maatwebsite)** — deja compatibil cu stack-ul.

```php
class CrawlSessionExport implements WithMultipleSheets {
    public function sheets(): array {
        return [
            new OverviewSheet($this->session),
            new InternalPagesSheet($this->session),
            new ExternalLinksSheet($this->session),
            new ImagesSheet($this->session),
            new BrokenLinksSheet($this->session),
            new RedirectsSheet($this->session),
            new PageTitlesSheet($this->session),
            new MetaDescriptionsSheet($this->session),
            new H1Sheet($this->session),
            new HreflangSheet($this->session),
            new CanonicalSheet($this->session),
            new StructuredDataSheet($this->session),
            new IssuesCriticalSheet($this->session),
            new IssuesWarningsSheet($this->session),
            new CustomExtractionsSheet($this->session),
        ];
    }
}
```

---

## 11. Lista Completă Funcționalități SEO

### 11.1 Crawler & Audit Tehnic
- [x] Crawl complet website (HTTP/HTTPS)
- [x] Crawl cu JavaScript rendering (Puppeteer)
- [x] Detecție broken links interne și externe (4xx/5xx)
- [x] Audit redirect-uri (301, 302, chain-uri, loop-uri)
- [x] Analiză Page Titles (lipsă, duplicate, lungime)
- [x] Analiză Meta Descriptions (lipsă, duplicate, lungime)
- [x] Detecție pagini noindex
- [x] Audit canonical tags
- [x] Audit hreflang (prezență, validitate, return tags)
- [x] Detectie duplicate content (exact + near-duplicate)
- [x] Analiză H1/H2/headings hierarchy
- [x] Audit robots.txt vs meta robots (conflicte)
- [x] Validare sitemap.xml (existență, pagini lipsă, pagini broken)
- [x] Generare sitemap.xml din crawl
- [x] Audit structured data (JSON-LD, Microdata, RDFa)
- [x] Validare Schema.org (tipuri, câmpuri, sintaxă)
- [x] Analiză imagini (alt text, dimensiuni, broken, greutate)
- [x] Audit Open Graph + Twitter Cards
- [x] Detectie meta refresh
- [x] Analiză adâncime pagini (crawl depth)
- [x] Analiză response time per pagină
- [x] Analiză dimensiune pagini
- [x] Audit X-Robots-Tag header
- [x] Audit link-uri interne (anchor text, nofollow)
- [x] Custom extraction (CSS/XPath/Regex selectors)
- [x] Configurare scope crawl (domeniu/subdomenii/custom regex)
- [x] Include/exclude URL patterns
- [x] Autentificare Basic Auth / Cookie / Form
- [x] Custom headers HTTP
- [x] Configurare User-Agent (Googlebot/Chrome/custom)
- [x] Rate limiting configurat per crawl
- [x] Salvare și refolosire configurații crawl
- [x] Crawl-uri programate (zilnic/săptămânal/lunar)
- [x] Comparație crawl-uri (diff issues, pagini, metrici)
- [x] Vizualizare site (Tree View + Force Graph)
- [x] Export XLS (15+ sheets, identic Screaming Frog)
- [x] Export CSV per categorie
- [x] Raport PDF executiv per crawl
- [x] Auto-detecție site cunoscut în platformă
- [x] Asociere automată sau manuală cu site/client

### 11.2 Keyword Research & Tracking
- [x] Research keywords din seed keyword sau domeniu
- [x] Date: volum, dificultate, CPC, trend 12 luni
- [x] Clasificare intenție de căutare (informațional/comercial/etc.)
- [x] Detectie SERP features (snippet, PAA, maps, shopping)
- [x] Long-tail keyword suggestions
- [x] Keyword clustering pe topicuri
- [x] Keyword gap analysis vs. competitor
- [x] Import/export liste keywords
- [x] Tracking poziții keywords per site
- [x] Grafic evoluție poziții în timp
- [x] Notificări mișcare poziții semnificative
- [x] Overlay date GSC pe keywords tracked
- [x] Detectie keyword cannibalization (mai multe pagini rankând pt același keyword)

### 11.3 Conținut AI
- [x] Generator articole SEO optimizate (română + multilingv)
- [x] Brief configurabil complet (ton, lungime, structură, public)
- [x] Generare meta title + meta description automată
- [x] Generare slug URL SEO-friendly
- [x] Schema.org auto-selectat per tip articol
- [x] Regenerare parțială (secțiune selectată)
- [x] Editor rich text in-platform
- [x] SEO score live în editor (keyword density, heading, length, etc.)
- [x] Publicare directă pe WordPress via WP Connector
- [x] Publicare programată (dată + oră specifică)
- [x] Setare categorie/tag-uri/autor WP din platformă
- [x] Upload featured image din platformă
- [x] Setare meta SEO (Yoast/RankMath) via WP
- [x] Status tracking: Draft → Review → Schedulat → Publicat
- [x] Versioning articole (revizii salvate)
- [x] Duplicate articol ca template
- [x] Calendar editorial vizual (drag & drop)
- [x] Bulk generare articole din listă keywords

### 11.4 Performance & Core Web Vitals
- [x] Integrare PageSpeed Insights API (existent, îmbunătățit)
- [x] CWV: LCP, FID/INP, CLS, FCP, TTFB, SI, TBT
- [x] Scoruri mobile + desktop
- [x] Trend CWV în timp per site
- [x] Overlay CWV pe URL-urile crawlate
- [x] Performance budgets + alerte la depășire

### 11.5 Search Console & Analytics Overlay
- [x] Overlay impresii/clicks/CTR/poziție pe URL-urile crawlate
- [x] Detectie pagini cu crawl OK dar performanță GSC slabă
- [x] Detectie pagini indexate în GSC dar dispărute din crawl
- [x] Detectie pagini cu trafic organic dar CTR scăzut (title/meta desc. slab)
- [x] Detectie canibalizar keyword (mai multe URL-uri pentru același keyword în GSC)

### 11.6 Accesibilitate (baza)
- [x] Detectie imagini fără alt text
- [x] Detectie link-uri fără text anchor descriptiv
- [x] Detectie heading hierarchy broken
- [x] Detectie form-uri fără labels

---

## 12. Instrumente SEO Recomandate pentru Integrare

### 12.1 Integrări de Date (API)

| Tool | Ce adaugă | Prioritate |
|---|---|---|
| **DataForSEO** | Volum keywords, KD, SERP data, competitor analysis | ⭐⭐⭐ Înaltă |
| **Google Search Console** | Trafic organic, poziții, indexare | ✅ Existent |
| **Google Analytics 4** | Comportament utilizatori post-landing | ✅ Existent |
| **Google PageSpeed Insights** | Core Web Vitals | ✅ Existent |
| **Ahrefs API** | DR, backlinks, organic traffic estimate | ⭐⭐ Medie |
| **Moz API** | DA/PA, link metrics, spam score | ⭐ Opțional |
| **Majestic API** | Trust Flow, Citation Flow | ⭐ Opțional |
| **SEMrush API** | Keywords, competitor research | ⭐⭐ Medie |
| **LanguageTool API** | Spell & grammar check (self-hostable) | ⭐⭐ Medie |

### 12.2 Instrumente Complementare (Fără Integrare API — Referință)

| Tool | Utilizare |
|---|---|
| **Screaming Frog** | Referință UX/features pentru crawler-ul propriu |
| **Google Search Console** | Verificare indexare manuală |
| **Google Rich Results Test** | Validare structured data |
| **Schema Markup Validator** | Validare Schema.org |
| **GTmetrix / WebPageTest** | Analiză performanță detaliată |
| **Ahrefs Webmaster Tools** | Backlink monitoring gratuit |
| **Google Keyword Planner** | Research keywords via Ads API |
| **AnswerThePublic** | Long-tail + questions research |
| **AlsoAsked** | "People Also Ask" data |
| **Surfer SEO** | Content optimization (competitor comparison) |
| **Frase / Clearscope** | AI content brief generation |

---

## 13. Roadmap Implementare

### Faza 1 — Crawler MVP + Sidebar SEO (Prioritate maximă)
- [ ] Sidebar principal cu secțiunea SEO (navigare)
- [ ] Dashboard SEO global (carduri + tabel site-uri)
- [ ] Infrastructură crawler: Queue jobs (Horizon existent) + CrawlSession/CrawlPage models
- [ ] Crawler HTTP de bază (fără JS rendering)
- [ ] Detectare: broken links, redirect-uri, page titles, meta, H1, canonical, noindex
- [ ] Duplicate detection (hash)
- [ ] Tab-uri: Overview, Pagini, Link-uri, Issues
- [ ] Export XLS (sheets de bază: Internal, Broken Links, Titles, Meta)
- [ ] Auto-detecție site cunoscut
- [ ] Integrare sidebar per-site (tab Crawler)
- [ ] Widget SEO Health în dashboard site

### Faza 2 — Crawler Complet + Keyword Tracking
- [ ] Hreflang audit
- [ ] Structured Data extragere + validare
- [ ] Near-duplicate content (simhash)
- [ ] Images tab + audit imagini
- [ ] Crawl Comparison (diff)
- [ ] Vizualizare site (Tree View)
- [ ] JS Rendering (Puppeteer — proces separat)
- [ ] Crawl-uri programate + notificări
- [ ] Export XLS complet (15 sheets)
- [ ] Custom extraction
- [ ] Keyword Tracking (monitorizare poziții via GSC)
- [ ] Overlay GSC pe rezultatele crawlului

### Faza 3 — Conținut AI + Calendar
- [ ] Endpoint-uri noi WP Connector plugin (posts, media, categories)
- [ ] Generator articole AI (brief + generare + editor)
- [ ] SEO score live în editor
- [ ] Publicare programată pe WordPress
- [ ] Listing articole + status tracking
- [ ] Calendar Editorial
- [ ] Integrare per-site (tab Conținut AI)

### Faza 4 — Keyword Research + Advanced
- [ ] Keyword Research (DataForSEO API sau Google Ads API)
- [ ] Keyword clustering + gap analysis
- [ ] Force Graph vizualizare crawl
- [ ] Accessibility audit
- [ ] Spell & Grammar (LanguageTool)
- [ ] Playbook Incident Response "SEO Critical Drop"
- [ ] Integrare completă rapoarte (secțiune SEO îmbogățită)
- [ ] Bulk generare articole din keywords

---

*Document complet — se actualizează pe măsura implementării fazelor.*  
*Next step: Schema DB detaliată + API endpoints WP Connector v2.11.0*
