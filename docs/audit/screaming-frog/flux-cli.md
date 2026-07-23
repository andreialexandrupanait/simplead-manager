# Fluxul CLI — crawl automat Screaming Frog pe Linux

Rularea headless a crawl-ului Simplead v2 pe server, cu toate exporturile necesare
metodologiei scoase dintr-o singură comandă. Corespondența export → verificare e în
[mapare-export-verificari.md](mapare-export-verificari.md); configurația din fișierul
`--config` e descrisă în [config-crawl.md](config-crawl.md).

Binarul pe Linux se numește `screamingfrogseospider`.

---

## 1. Activarea licenței pentru headless

**Important:** licența se activează pe mașina care rulează crawl-ul — cheia trebuie
instalată pe server, nu doar pe stația de lucru.

1. **Fișierul de licență** — `~/.ScreamingFrogSEOSpider/licence.txt`, două linii:

   ```
   linia 1: username-ul licenței
   linia 2: cheia de licență
   ```

2. **Acceptarea EULA** — obligatorie pentru rularea headless; se face prin setarea
   `eula.accepted` în `~/.ScreamingFrogSEOSpider/spider.config`. Pe SF 24.3 valoarea
   este `eula.accepted=15` (confirmat pe serverul Simplead, 12.07.2026 — fără ea,
   rularea headless iese imediat cu „User did not accept the licence agreement").

3. **Limita de memorie** — serverul Simplead are RAM limitat; în fișierul
   `~/.screamingfrogseospider` se setează explicit:

   ```
   -Xmx2g
   ```

   împreună cu Database Storage Mode în configurație (vezi config-crawl.md, §1).

4. **Smoke test** — prima comandă de rulat după instalare:

   ```bash
   screamingfrogseospider --help
   ```

   Sub-help-urile listează TOATE numele valide de argumente — sursa de adevăr pentru
   compunerea comenzii (validate pe SF 24.3, 12.07.2026):

   ```bash
   screamingfrogseospider -h export-tabs    # toate perechile Tab:Filtru valide
   screamingfrogseospider -h bulk-export    # toate bulk export-urile valide
   screamingfrogseospider -h save-report    # toate rapoartele valide
   ```

   **Observație:** filtrele cu prag numeric apar în CLI cu litera X, nu cu valoarea
   configurată: `Page Titles:Over X Characters`, `URL:Over X Characters`,
   `Images:Over X kB` etc. Pragul efectiv (60/30, 155/70, 115…) vine din preferințele
   salvate în config.

## 2. Comanda completă — crawl standard (Text Only)

Gata de copiat; se înlocuiesc URL-ul, folderul de output și calea configului:

```bash
screamingfrogseospider \
  --crawl https://www.exemplu.ro \
  --headless \
  --config /var/www/audit/screaming-frog/simplead-default.seospiderconfig \
  --output-folder /var/www/audit/crawls/exemplu-ro-2026-07-12 \
  --overwrite \
  --save-crawl \
  --export-format csv \
  --use-google-search-console "cont@simplead.ro" "https://www.exemplu.ro/" \
  --use-google-analytics-4 "cont@simplead.ro" "Cont GA4" "Proprietate" "Data Stream" \
  --use-pagespeed \
  --export-tabs "Internal:All,Internal:Images,Response Codes:Blocked by Robots.txt,Response Codes:Redirection (3xx),Response Codes:Client Error (4xx),Response Codes:Server Error (5xx),Response Codes:Internal Redirect Chain,Response Codes:Internal Redirect Loop,Page Titles:Missing,Page Titles:Duplicate,Page Titles:Multiple,Page Titles:Same as H1,Page Titles:Over X Characters,Page Titles:Below X Characters,Meta Description:Missing,Meta Description:Duplicate,Meta Description:Over X Characters,Meta Description:Below X Characters,H1:Missing,H1:Multiple,H1:Duplicate,H2:Duplicate,Images:Missing Alt Text,Images:Missing Alt Attribute,Canonicals:Missing,Canonicals:Canonicalised,Canonicals:Canonical Is Relative,Canonicals:Multiple Conflicting,Pagination:Paginated 2+ Pages,Pagination:Non-Indexable,Directives:Noindex,Security:HTTP URLs,Security:Mixed Content,Security:Missing HSTS Header,Security:Missing Content-Security-Policy Header,Security:Missing X-Content-Type-Options Header,Security:Missing X-Frame-Options Header,Security:Missing Secure Referrer-Policy Header,URL:Uppercase,URL:Underscores,URL:Contains Space,URL:Non ASCII Characters,URL:Parameters,URL:Over X Characters,URL:Repetitive Path,URL:Multiple Slashes,URL:Internal Search,Links:Non-Descriptive Anchor Text In Internal Outlinks,Links:Internal Outlinks With No Anchor Text,Structured Data:Contains Structured Data,Structured Data:Missing,Structured Data:Validation Errors,Structured Data:Validation Warnings,Structured Data:Rich Result Validation Errors,Sitemaps:URLs not in Sitemap,Sitemaps:Orphan URLs,Sitemaps:Non-Indexable URLs in Sitemap" \
  --bulk-export "Links:All Inlinks,Images:Images Missing Alt Text Inlinks" \
  --save-report "Crawl Overview,Redirects:Redirect Chains,Canonicals:Canonical Chains,Canonicals:Non-Indexable Canonicals,Orphan Pages" \
  --skip-empty
```

**Important:** în `--export-tabs`, fiecare element este o pereche `Tab:Filtru`, iar
numele tabului și al filtrului trebuie să corespundă EXACT etichetelor din interfață
(exemplu oficial: `--export-tabs "Internal:All,Response Codes:Client Error (4xx)"`).
`--save-report` folosește aceeași potrivire pe nume (ex.
`--save-report "Redirects:Redirect Chains"`).

**Notă (confirmate pe SF 24.3, 12.07.2026):** stringurile de raport sunt exact
`Redirects:Redirect Chains`, `Canonicals:Canonical Chains`,
`Canonicals:Non-Indexable Canonicals`, `Orphan Pages`, `Crawl Overview`; bulk
export-urile sunt `Links:All Inlinks` și `Images:Images Missing Alt Text Inlinks`
(prefixul de submeniu e obligatoriu — `All Inlinks` singur nu e acceptat). Exportul
`Links:All Inlinks` conține coloanele Type, Source, Destination, Alt Text, Anchor,
Status Code, Follow, Link Position, Link Origin.

**Important — Crawl Analysis în headless:** confirmat pe SF 24.3 că NU există flag CLI
dedicat (lista `--help` e completă, nu conține așa ceva). Empiric, la crawl-ul de
validare pe SF 24.3 exporturile Sitemaps și raportul Orphan Pages au ieșit populate cu
configurația implicită — analiza rulează automat la finalul crawl-ului headless când
exporturile cerute o necesită. Dacă pe alt site exporturile Sitemaps ies totuși goale,
se bifează „Auto Analyse at End of Crawl" în Crawl Analysis > Configure din interfață
și se salvează în fișierul `--config`.

**Notă:** `--use-google-search-console` primește două argumente (contul Google +
site-ul: `"cont@..." "https://www.exemplu.ro/"`), iar pentru GA4 flag-ul este
`--use-google-analytics-4 <cont> <account> <property> <data-stream>` —
`--use-google-analytics` simplu este marcat deprecat (Universal Analytics) în CLI.
Conturile Google OAuth se autorizează o singură dată din interfață, cu același
utilizator de sistem care rulează comanda headless; aplicația le memorează în profil.
Dacă cheia PSI se salvează în fișierul de config: [de verificat în SF la prima rulare
cu API-uri].

## 3. Al doilea crawl — randare JavaScript (verificarea 6.3)

Pentru site-urile JS-heavy se rulează și varianta cu randare JavaScript, în folder
separat, cu configul dedicat (identic cu cel standard, dar Rendering = JavaScript):

```bash
screamingfrogseospider \
  --crawl https://www.exemplu.ro \
  --headless \
  --config /var/www/audit/screaming-frog/simplead-js-rendering.seospiderconfig \
  --output-folder /var/www/audit/crawls/exemplu-ro-2026-07-12-js \
  --overwrite \
  --save-crawl \
  --export-format csv \
  --export-tabs "Internal:All"
```

Verificarea 6.3 se probează prin diff per URL între cele două exporturi `Internal:All`
(coloanele Word Count, H1-1, Title 1).

**Notă:** crawl-ul cu randare JS e mult mai lent și mai gurmand cu memoria — se rulează
în afara orelor de vârf și niciodată în paralel cu alt crawl pe serverul Simplead.

## 4. Alte flag-uri disponibile (confirmate)

Restul flag-urilor confirmate pe documentația oficială, pentru situații punctuale:

| Flag | Utilizare în fluxul Simplead |
|---|---|
| `--crawl-list <fișier>` | crawl pe o listă fixă de URL-uri (re-verificări punctuale post-implementare) |
| `--create-sitemap` | generarea unui sitemap XML din crawl (livrabil pentru clienți fără sitemap) |
| `--export-custom-summary` | sumar custom (nefolosit în fluxul standard) |
| `--use-majestic`, `--use-ahrefs` | date de backlink per URL (nefolosite: off-site se probează din GSC + manual, vezi 4.5) |
| `--export-format csv\|xlsx\|gsheet` | standardul Simplead este `csv` |
| `--help` | validarea flag-urilor la prima rulare pe server |

## 5. Checklist post-rulare

- [ ] Folderul de output conține toate CSV-urile din `--export-tabs` (57 de fișiere),
      `all_inlinks.csv` (sau echivalentul denumirii generate) și rapoartele salvate.
- [ ] `Internal:All` conține coloanele Address, Status Code, Indexability, Title 1,
      Meta Description 1, H1-1, Word Count, Unique Inlinks — plus coloanele GSC/GA4/PSI
      dacă API-urile au fost active.
- [ ] Exporturile Sitemaps au conținut (altfel: problema Crawl Analysis de la §2).
- [ ] Fișierul de crawl salvat (`--save-crawl`) e arhivat împreună cu exporturile.
