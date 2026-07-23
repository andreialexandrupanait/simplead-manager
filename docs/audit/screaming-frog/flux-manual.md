# Fluxul manual — crawl și exporturi din interfața Screaming Frog

Checklist bifabil, pas cu pas, pentru un audit Simplead v2 rulat din interfața grafică.
Fiecare export de mai jos alimentează una sau mai multe verificări din metodologie —
corespondența exactă e în [mapare-export-verificari.md](mapare-export-verificari.md).

**Important:** numele taburilor și ale filtrelor trebuie să corespundă EXACT etichetelor
din interfață. Exporturile se salvează ca CSV, cu numele implicit generat de aplicație,
într-un singur folder per client per dată (ex. `crawls/exemplu-ro-2026-07-12/`).

---

## Etapa 1 — Configurare

- [ ] Aplică integral configurația din [config-crawl.md](config-crawl.md) (sau încarcă
      fișierul de configurare salvat).
- [ ] Verifică explicit: Database Storage, viteză max 1 URL/sec, Structured Data
      extraction + validation, **Crawl Linked XML Sitemaps**, API-urile GSC/GA4/PSI
      conectate și autorizate.

**Notă:** dacă site-ul e JS-heavy, setează Rendering = JavaScript
(Configuration > Spider > Rendering). Pentru verificarea 6.3 se rulează ulterior și un
al doilea crawl, Text Only, în folder separat.

## Etapa 2 — Crawl

- [ ] Introdu URL-ul de start (gazda canonică a clientului, cu protocol) și pornește
      crawl-ul.
- [ ] Așteaptă 100% — nu se exportă nimic dintr-un crawl parțial.
- [ ] Salvează crawl-ul (fișierul de proiect) în folderul clientului, pentru
      re-deschidere ulterioară.

## Etapa 3 — Crawl Analysis

- [ ] **Crawl Analysis > Configure** — bifează analizele necesare.
- [ ] **Crawl Analysis > Start** — așteaptă finalizarea.

**Important:** fără acest pas, filtrele din Sitemaps (URLs not in Sitemap, Orphan URLs,
Non-Indexable URLs in Sitemap), Content (Exact/Near Duplicates) și raportul Orphan
Pages rămân goale, iar verificările 2.2.3, 2.2.6 și 2.12.3 nu pot fi probate.

## Etapa 4 — Exporturi de taburi (Tab: Filtru)

Selectează tabul, apoi filtrul din dropdown, apoi Export. Bifează pe măsură ce salvezi:

**Internal**
- [ ] Internal: All *(exportul-pivot al auditului — conține Title 1, Meta Description 1,
      H1-1, H2-1, Meta Robots 1, Canonical Link Element 1, Word Count, Status Code,
      Indexability, Unique Inlinks, Crawl Depth etc.)*
- [ ] Internal: Images

**Response Codes**
- [ ] Response Codes: Blocked by Robots.txt
- [ ] Response Codes: Redirection (3xx)
- [ ] Response Codes: Client Error (4xx)
- [ ] Response Codes: Server Error (5xx)
- [ ] Response Codes: Internal Redirect Chain
- [ ] Response Codes: Internal Redirect Loop

**Page Titles**
- [ ] Page Titles: Missing
- [ ] Page Titles: Duplicate
- [ ] Page Titles: Multiple
- [ ] Page Titles: Same as H1
- [ ] Page Titles: Over X Characters
- [ ] Page Titles: Below X Characters

**Meta Description**
- [ ] Meta Description: Missing
- [ ] Meta Description: Duplicate
- [ ] Meta Description: Over X Characters
- [ ] Meta Description: Below X Characters

**H1**
- [ ] H1: Missing
- [ ] H1: Multiple
- [ ] H1: Duplicate

**H2**
- [ ] H2: Duplicate

**Images**
- [ ] Images: Missing Alt Text
- [ ] Images: Missing Alt Attribute

**Canonicals**
- [ ] Canonicals: Missing
- [ ] Canonicals: Canonicalised
- [ ] Canonicals: Canonical Is Relative
- [ ] Canonicals: Multiple Conflicting

**Pagination**
- [ ] Pagination: Paginated 2+ Pages
- [ ] Pagination: Non-Indexable

**Directives**
- [ ] Directives: Noindex

**Security**
- [ ] Security: HTTP URLs
- [ ] Security: Mixed Content
- [ ] Security: Missing HSTS Header
- [ ] Security: Missing Content-Security-Policy Header
- [ ] Security: Missing X-Content-Type-Options Header
- [ ] Security: Missing X-Frame-Options Header
- [ ] Security: Missing Secure Referrer-Policy Header

**URL**
- [ ] URL: Uppercase
- [ ] URL: Underscores
- [ ] URL: Contains Space
- [ ] URL: Non ASCII Characters
- [ ] URL: Parameters
- [ ] URL: Over X Characters
- [ ] URL: Repetitive Path
- [ ] URL: Multiple Slashes
- [ ] URL: Internal Search

**Links**
- [ ] Links: Non-Descriptive Anchor Text In Internal Outlinks
- [ ] Links: Internal Outlinks With No Anchor Text

**Structured Data**
- [ ] Structured Data: Contains Structured Data
- [ ] Structured Data: Missing
- [ ] Structured Data: Validation Errors
- [ ] Structured Data: Validation Warnings
- [ ] Structured Data: Rich Result Validation Errors

**Sitemaps** *(după Crawl Analysis)*
- [ ] Sitemaps: URLs not in Sitemap
- [ ] Sitemaps: Orphan URLs
- [ ] Sitemaps: Non-Indexable URLs in Sitemap

**PageSpeed** *(doar cu API-ul PSI conectat)*
- [ ] Exportul tabului PageSpeed (CWV per URL, pentru 3.5 și 3.6)

## Etapa 5 — Bulk Exports

- [ ] Bulk Export > **All Inlinks** *(navigare părinte → copii → frați, ancore — 2.6.1)*
- [ ] Bulk Export > Images — exportul imaginilor fără alt text (eticheta verbatim a
      exportului: [de verificat în SF]) *(alt text per imagine — 2.10.3)*

**Observație:** exportul principal al tabului Images NU conține coloană de Alt Text —
alt text-ul per imagine vine exclusiv din Bulk Export > Images.

## Etapa 6 — Rapoarte (Reports)

- [ ] Reports > Redirects > **Redirect Chains** *(3.1, 3.3)*
- [ ] Reports > Canonicals > **Canonical Chains** *(2.2.2, 2.12.1)*
- [ ] Reports > Canonicals > **Non-Indexable Canonicals** *(2.2.2)*
- [ ] Reports > **Orphan Pages** *(2.2.6 — combină Sitemaps/GA/GSC, coloana Source;
      necesită GSC + GA4 conectate și Crawl Analysis rulat)*

## Etapa 7 — Verificare finală a pachetului de exporturi

- [ ] Toate CSV-urile din etapele 4–6 sunt în folderul clientului, nedeschise/nemodificate
      în Excel (Excel strică encodingul diacriticelor și formatele de URL).
- [ ] `Internal:All` are rânduri și coloanele-cheie: Address, Status Code, Indexability,
      Title 1, Meta Description 1, H1-1, Word Count, Unique Inlinks.
- [ ] Filtrele Sitemaps au date (dacă sunt goale: Crawl Analysis nu a rulat sau
      „Crawl Linked XML Sitemaps" nu a fost bifat — se reia Etapa 3, respectiv crawl-ul).
- [ ] Pentru site-uri JS-heavy: există și folderul celui de-al doilea crawl
      (Text Only vs JavaScript) pentru verificarea 6.3.

**Notă:** filtrele goale sunt un rezultat valid (ex. zero URL-uri cu Uppercase =
verificarea 2.1.1 trece). Se exportă oricum — CSV-ul gol este dovada.
