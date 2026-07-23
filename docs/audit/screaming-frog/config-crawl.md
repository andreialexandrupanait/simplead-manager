# Configurația de crawl Simplead — Screaming Frog SEO Spider

Acest document definește configurația standard de crawl pentru auditurile Simplead v2.
Configurația se setează o singură dată în interfață, se salvează într-un fișier de
configurare și se refolosește identic în fluxul manual ([flux-manual.md](flux-manual.md))
și în cel automat ([flux-cli.md](flux-cli.md)).

**Important:** exporturile din Screaming Frog sunt fundația de date a metodologiei v2
(vezi [mapare-export-verificari.md](mapare-export-verificari.md)). Orice abatere de la
configurația de mai jos poate produce exporturi incomplete — în special filtrele din
taburile Sitemaps, Content și Structured Data, care depind de opțiuni ce NU sunt active
implicit.

---

## 1. Storage Mode — Database Storage

- Meniu: **File > Settings > Storage Mode** (în versiunile mai vechi: Configuration >
  System > Storage Mode).
- Se selectează **Database Storage** (disk-based). Este modul implicit și recomandat de
  producător; pe serverul Simplead este obligatoriu.

**Important:** serverul Simplead are RAM limitat (plus 2 GB swap). Crawl-urile rulează
exclusiv disk-based, cu limita de memorie Java setată explicit la `-Xmx2g` în fișierul
`~/.screamingfrogseospider` (detalii în [flux-cli.md](flux-cli.md), secțiunea de
activare). Memory Storage se folosește doar pe stații de lucru cu RAM generos, pentru
site-uri mici.

## 2. Viteza de crawl — politicoasă

- Meniu: **Configuration > Speed**.
- Pe site-urile clienților: **maximum 1 URL/secundă**. Limităm viteza, nu doar numărul
  de thread-uri, ca să nu punem presiune pe serverele de producție ale clientului.

**Notă:** un crawl la 1 URL/sec pe un site de 10.000 de URL-uri durează aproximativ
3 ore. Este un cost acceptat — auditul nu are voie să degradeze site-ul auditat.

## 3. User-agent

- Implicit, Screaming Frog se identifică drept **Screaming Frog SEO Spider**. Acesta
  este user-agent-ul standard pentru auditurile Simplead: clientul poate vedea în
  loguri exact cine i-a accesat site-ul.
- Schimbarea user-agent-ului (calea exactă de meniu: [de verificat în SF]) se face
  doar în două situații:
  1. Site-ul servește conținut diferit pe user-agent (cloaking suspect) — se compară
     crawl-ul default cu unul pe Googlebot.
  2. WAF-ul clientului blochează spider-ul, iar clientul nu poate adăuga excepție —
     se cere acordul explicit al clientului înainte de a schimba identificarea.

**Important:** nu se maschează niciodată crawl-ul fără acordul clientului.

## 4. Randarea JavaScript

- Meniu: **Configuration > Spider > Rendering** → opțiunile: **Text Only**,
  **Old AJAX**, **JavaScript** (headless Chrome). Setarea se salvează în fișierul de
  configurare.
- Regula Simplead:
  - **Text Only** — crawl-ul standard, pentru majoritatea site-urilor.
  - **JavaScript** — se activează când site-ul e JS-heavy (SPA, framework-uri React/
    Vue/Next cu hidratare, conținut injectat client-side): dacă un fetch fără JS
    întoarce HTML aproape gol, crawl-ul principal se face cu randare JavaScript.
- **Dublu-crawl pentru verificarea 6.3** (conținut critic prezent în HTML-ul inițial):
  se rulează DOUĂ crawl-uri identice, unul Text Only și unul JavaScript, în foldere de
  output separate, și se compară per URL coloanele Word Count, H1-1 și Title 1 din
  exportul Internal:All. Diferențe mari = dependență de JS = verificarea 6.3 pică.

**Notă:** randarea JavaScript încetinește sever crawl-ul și consumă mult mai multă
memorie. Pe serverul Simplead, crawl-urile cu randare JS se rulează doar noaptea și
doar cu Database Storage activ.

## 5. Structured Data — extraction + validation

- Meniu: **Configuration > Spider > Extraction** → se activează extragerea și
  validarea datelor structurate (JSON-LD).
- Fără această opțiune, tabul **Structured Data** iese gol — pică verificările 2.8.2,
  2.9.2, 2.11.1, 2.11.2, 2.11.4.

## 6. Crawl Linked XML Sitemaps

- Meniu: **Configuration > Spider > Crawl** → se bifează **Crawl Linked XML Sitemaps**.
- Necesară pentru filtrele din tabul **Sitemaps** (URLs not in Sitemap, Orphan URLs,
  Non-Indexable URLs in Sitemap) — verificările 2.2.3, 2.2.6, 2.12.3, 3.4.

**Observație:** filtrele din Sitemaps se populează abia după Crawl Analysis
(secțiunea 8) — bifarea opțiunii singură nu e suficientă.

## 7. Conectarea API-urilor — Configuration > API Access

Se conectează ÎNAINTE de pornirea crawl-ului, ca exporturile să iasă direct cu date de
trafic, indexare și CWV per URL:

| API | Ce aduce per URL | Verificări deservite |
|---|---|---|
| **Google Search Console** | clicks, impressions, CTR, poziție; filtru „No Search Analytics Data" | 2.2.6 (orfane), 2.3.2 (interogări per URL) |
| **Google Analytics 4** | sesiuni, engagement, conversii per landing page | 2.2.6 (orfane), context CRO (05) |
| **PageSpeed Insights** | CWV de teren (CrUX) + Lighthouse per URL, în tabul **PageSpeed** | 3.5, 3.6 |

- Conturile Google se autorizează prin OAuth o singură dată; aplicația le memorează în
  profilul utilizatorului, deci funcționează și la rulările headless ulterioare.
- Dacă cheia PageSpeed Insights se salvează în fișierul de configurare folosit cu
  `--config`: [de verificat în SF].

**Notă:** GSC și GA4 cer acces la proprietățile clientului. Se solicită la kickoff, ca
să nu fie nevoie de re-crawl doar pentru datele de trafic.

## 8. Crawl Analysis — obligatoriu la final

- Meniu: **Crawl Analysis > Configure**, apoi **Crawl Analysis > Start**, după
  terminarea crawl-ului.
- Fără Crawl Analysis rămân goale: filtrele **Sitemaps** (URLs not in Sitemap, Orphan
  URLs, Non-Indexable URLs in Sitemap), filtrele **Content** (Near Duplicates, Exact
  Duplicates) și raportul **Orphan Pages**.
- Pentru rulările headless: confirmat pe SF 24.3 (12.07.2026) că NU există flag CLI
  dedicat pentru Crawl Analysis. Empiric, la crawl-ul de validare pe serverul Simplead
  analiza a rulat automat la finalul crawl-ului headless — exporturile Sitemaps și
  raportul Orphan Pages au ieșit populate. Dacă pe un alt site ies goale, se bifează
  „Auto Analyse at End of Crawl" în Crawl Analysis > Configure și se salvează în
  fișierul de configurare folosit cu `--config`.

## 9. Salvarea configurației

După setarea punctelor 1–8, configurația se salvează într-un fișier refolosibil (calea
exactă de meniu pentru salvare și extensia implicită a fișierului: [de verificat în
SF]) — de exemplu:

```
/var/www/audit/screaming-frog/simplead-default.seospiderconfig
/var/www/audit/screaming-frog/simplead-js-rendering.seospiderconfig
```

Primul e configul standard (Text Only), al doilea diferă doar prin
Rendering = JavaScript, pentru dublu-crawl-ul verificării 6.3.

**Important:** ambele fișiere se versionează împreună cu documentația. Orice
modificare de configurație = commit + notă în acest document.

---

## Rezumat — checklist de configurare

- [ ] Storage Mode = **Database Storage** (File > Settings > Storage Mode)
- [ ] Viteză = max **1 URL/sec** (Configuration > Speed)
- [ ] User-agent = default **Screaming Frog SEO Spider**
- [ ] Rendering = **Text Only** (sau **JavaScript** pe site-uri JS-heavy; dublu-crawl pentru 6.3)
- [ ] Structured Data extraction + validation active (Configuration > Spider > Extraction)
- [ ] **Crawl Linked XML Sitemaps** bifat (Configuration > Spider > Crawl)
- [ ] API-uri conectate: GSC, GA4, PSI (Configuration > API Access)
- [ ] Crawl Analysis rulat la final (Crawl Analysis > Configure > Start) sau „Auto Analyse at End of Crawl" în config
- [ ] Configurația salvată în fișier și versionată
