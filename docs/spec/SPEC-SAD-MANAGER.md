# SAD Manager — specificație de produs

> Document de referință. Nimic nu intră în el fără să iasă altceva.
> Ultima actualizare: iulie 2026

---

## 1. Scop și granițe

**SAD Manager este o aplicație de mentenanță WordPress pentru flota SimpleAD.**
Administrează sub 60 de site-uri WordPress, le monitorizează, execută operațiuni asupra lor și livrează rapoarte lunare către clienți.

### Regula fundamentală

> **Manager operează și măsoară. Nu analizează niciodată.**

### Ce NU face

| Domeniu | Unde trăiește |
|---|---|
| Audit SEO și CRO | aplicație separată |
| Bani, contracte, facturare, profitabilitate | SAD Hub |
| Prospecți și pipeline de vânzări | SAD Hub |
| Firewall, WAF, scanare de malware | plugin pe site-ul clientului |
| Provisioning de servere, staging, migrare | acces direct Hetzner |

### Utilizator

Un singur utilizator: Andrei. Fără roluri, fără echipe, fără invitații, fără portal de client.

---

## 2. Principii de design

1. **Exception-based** — ecranul implicit arată doar ce e stricat, nu toate site-urile verzi.
2. **Bulk-first** — orice acțiune posibilă pe un site trebuie să fie posibilă pe 30 dintr-un click.
3. **Zero întreținere manuală a datelor** — inventarul se populează singur din conector.
4. **Automatizarea e în execuție, nu în decizie** — aprobarea se dă pe lot, o dată, nu per site.
5. **Fiecare funcționalitate produce o cifră pentru raport sau o acțiune.** Dacă nu face niciuna, nu intră.

---

## 3. Structura de navigație

Aplicația are **două contexte de navigație care se înlocuiesc reciproc**.

### 3.1 Context flotă

```
Caută site, client, plugin…

VEDERE
  Panou
  Alerte                    [contor]

OPERAȚIUNI
  Site-uri                  [58]
  Actualizări               [contor]

EVIDENȚĂ
  Rapoarte
  Activitate
───────────────
  Setări
```

### 3.2 Context site

Se intră prin clic pe un site. Sidebar-ul global dispare complet.

```
← Toate site-urile
[comutator de site ▾]

  Prezentare

MENTENANȚĂ
  Pluginuri și teme ▾       Pluginuri · Teme · Licențe premium
  Actualizări ▾  [contor]   Disponibile · Reguli automate · Ignorate · Istoric
  Backupuri ▾               Listă · Restore verificat · Programare

SUPRAVEGHERE
  Uptime ▾                  Panou · Incidente · SSL și domeniu
  Securitate ▾  [contor]    Scanare · Vulnerabilități · Integritate · Utilizatori · Hardening
  Performanță ▾             PageSpeed · Core Web Vitals
  Verificări ▾              Formulare · WooCommerce · Linkuri rupte · Erori PHP · Cron · Bază de date

DATE
  Trafic ▾                  Analytics · Search Console · Cloudflare
  Sarcini                   din app.simplead.ro
  Rapoarte ▾                Generate · Programare
───────────────
  Setări site
```

### 3.3 Reguli de navigație

- Meniul flotei răspunde la **„pe ce site-uri e problema"**.
- Sidebar-ul site-ului răspunde la **„ce are site-ul ăsta"**.
- Setările nu se repetă sub fiecare modul. Excepție: „Reguli automate" sub Actualizări.
- Butoanele rapide — wp-admin, backup, verifică — rămân vizibile în toate secțiunile site-ului.
- Numele site-ului e un comutator, nu un titlu: treci pe alt site fără să ieși din secțiune.
- Sidebar-ul are buton de colapsare.

---

## 4. Ecranul principal — lista de site-uri

Model: WPMU DEV Hub. Două vederi comutabile: **listă** și **grid**.

### 4.1 Structura rândului

```
[avatar] [nume + client] [nr. update-uri] │ [grup 1] │ [grup 2] │ [grup 3] │ [uptime] [⋮]
```

### 4.2 Cele trei grupuri de iconițe

| Grup | Iconițe | Întrebarea la care răspunde |
|---|---|---|
| **Disponibilitate** | uptime, SSL, backup | „De ce nu merge site-ul?" |
| **Sănătate** | securitate, performanță, erori PHP | „De ce e lent?" |
| **Funcționare** | formulare, WooCommerce | „De ce nu primesc comenzi?" |

Al patrulea grup, rezervat pentru mai târziu: linkuri rupte, licențe.

### 4.3 Codul de culoare

| Culoare | Sens |
|---|---|
| Verde | în regulă |
| Galben | atenție |
| Roșu | critic |
| Gri | modulul nu se aplică pe acest site |

Bara de la final: gri dacă luna a fost curată, roșie dacă a existat downtime.

### 4.4 Elemente de ecran

- Taburi: Toate · Actualizări [contor] · Alerte [contor] · Planuri
- Controale: căutare, grupare (client / etichetă / plan), filtru, comutator vedere
- Filtre: client, etichetă, plan, stare, prezență WooCommerce, versiune WP, versiune PHP
- **Selecție multiplă** cu bară de acțiuni: actualizează · backup · aplică plan · aplică presetări · golește cache · verifică acum
- Jos: „Conectează site existent" și „Conectare în masă"
- Grid: miniatură generată automată a homepage-ului

### 4.5 Panou

Trei benzi, în ordinea asta:

1. Cine necesită atenție — grupat pe client, ordonat după gravitate, desfășurabil pe site
2. Ce așteaptă aprobarea
3. Restul, strâns într-o singură linie

Fără grafice. Fără widget-uri decorative.

---

## 5. Verificări automate

### 5.1 Disponibilitate

| Verificare | Detaliu |
|---|---|
| Uptime | 1 min pe site-uri critice, 5 min pe rest |
| Timp de răspuns | serie temporală |
| SSL | valabilitate, expirare, emitent |
| Expirare domeniu | alertă din timp |
| Modificări DNS | detectare schimbări neașteptate |

**Regula anti-fals-pozitiv:** două eșecuri consecutive înainte de a deschide un incident, ideal din două locații. Altfel o sincopă de rețea pe VPS declanșează downtime fals pe toată flota.

### 5.2 Securitate

- Vulnerabilități în pluginuri, teme și core (sursă: Wordfence Intelligence)
- Integritate fișiere core
- Integritate fișiere temă
- Utilizatori administratori noi
- Utilizatori spam
- Încercări de login și atacuri blocate

### 5.3 Stare tehnică

- Versiuni: WordPress, PHP, pluginuri, teme
- EOL WordPress și PHP
- Pluginuri abandonate
- Evaluare risc plugin
- Erori PHP agregate, cu atribuire la plugin sau temă
- Cron funcțional
- Sănătate și mărime bază de date
- Spațiu pe disc
- Scor de sănătate
- Licențe premium — expirări

### 5.4 Funcționare reală

| Verificare | Ce face |
|---|---|
| **Formulare de contact** | trimitere reală săptămânală, cu marcaj, plus verificare de livrare a emailului |
| **WooCommerce** (condiționat) | checkout returnează 200, gateway de plată răspunde, comenzi blocate în pending, cron de reduceri |
| **Linkuri rupte** | vezi secțiunea 5.7 |
| **Backup** | s-a executat, și restore-ul funcționează |

**Precauție la testul de formulare:** conectorul trebuie să seteze un flag pe durata testului care suprimă integrările (CRM, Zapier, autoresponder, Mailchimp). Ștergerea intrării după test nu e suficientă.

### 5.5 Performanță

PageSpeed și Core Web Vitals, **lunar, nu continuu**. Desktop și mobil.

### 5.6 Erori PHP — cerințe pentru conector

Handler propriu (`set_error_handler` + `register_shutdown_function`), nu citire din `debug.log`.

Cerințe non-negociabile:

1. Deduplicare locală după semnătură: fișier + linie + șablonul mesajului, cu valorile variabile eliminate
2. Trimitere în batch, nu per eroare
3. `try/catch` peste tot, cu eșec silențios
4. Buton de oprire de la distanță, din Manager

Atribuirea se face din calea fișierului: `/wp-content/plugins/{slug}/` sau `/themes/{slug}/`, potrivit cu inventarul.

### 5.7 Link checker

**Ce verifică**

- Linkuri interne din conținut: articole, pagini, produse, meniuri, widget-uri
- Imagini cu sursă ruptă
- Lanțuri de redirect interne (A → B → C)
- Linkuri externe — **opțional, dezactivat implicit**

**Cum funcționează**

Conectorul scanează conținutul direct din baza de date și extrage URL-urile unice. Manager le verifică apoi prin HEAD request.

> Un site cu 500 de pagini are de obicei sub 200 de URL-uri interne distincte. Verifici 200 de adrese, nu crawlezi 500 de pagini.

**Ce NU face**

| Exclus | Motiv |
|---|---|
| Backlinks | e SEO — a plecat în aplicația separată |
| Crawl cu randare JavaScript | cost disproporționat |
| Linkuri externe, implicit | zgomot: site-uri terțe temporar căzute, protecții anti-bot care dau 403 la HEAD, redirecturi de tracking |

**Cadență:** lunară, înainte de generarea raportului. Linkurile nu se rup săptămânal.

**Raportare pe diferență, nu pe stare:**

> *„3 linkuri rupte noi, 2 rezolvate față de luna trecută."*

O listă de 40 de linkuri rupte, identică lună de lună, nu se citește niciodată.

**Prag de alertă:** notificare doar dacă se rupe un link pe o pagină din top-ul Search Console. Restul apar doar în raport.

---

## 6. Operațiuni

### 6.1 Motorul comun

Toate operațiunile trec printr-un singur motor cu interfața:

```
prepare() → execute() → verify() → rollback()
```

Primesc gratuit: selecția site-urilor, coada, limita de concurență, progresul, rezultatul per site, retry, timeout, logul, poarta de aprobare, notificarea la final.

**Cozi separate pe clasă de durată** — un purge de cache nu așteaptă 20 de minute în spatele unui backup.
**Rollback-ul nu e obligatoriu** — interfața permite „nu se poate reveni".

Există deja în cod: `CircuitBreakerService`, `JobTracker`, `app/Dispatchers`.

### 6.2 Tipuri de operațiuni

- Update sigur cu rollback (core, pluginuri, teme)
- Backup
- Restore
- Restore verificat automat
- Instalare / activare / ștergere pluginuri
- Gestionare teme
- Aplicare plan de mentenanță
- Aplicare presetări WordPress
- Copiere setări între site-uri
- Golire cache Cloudflare
- Login instant în wp-admin

---

## 7. Actualizări

### 7.1 Vederea globală

O singură axă: **pluginul**, nu site-ul.

> „Elementor 3.22 disponibil pe 18 site-uri" → aprobare de lot → execuție

### 7.2 Reguli inteligente

Regulile sunt aprobarea exprimată o dată, nu automatizare fără control.

**Regula 1 — update minor automat**

```
DACĂ  tipul versiunii ∈ {minor, patch}
  ȘI  pluginul nu e în lista de risc a site-ului
  ȘI  există backup mai nou de 24h
  ȘI  site-ul nu are incident deschis
ATUNCI
  aplică pe 2 site-uri canar, așteaptă 30 min
  rulează smoke check pe URL-urile cheie
  dacă trece → aplică pe restul selecției
  dacă pică → rollback + task în app.simplead.ro
```

**Regula 2 — așteaptă aprobarea**

```
DACĂ  versiune majoră SAU plugin din lista de risc
ATUNCI creează cerere de aprobare, nu executa
```

**Regula 3 — vulnerabilitate critică**

```
DACĂ  severitate ≥ mare ȘI patch disponibil
ATUNCI aplică imediat pe toate site-urile afectate, indiferent de tipul versiunii
       notifică pe Telegram
```

Singura regulă care are voie să sară peste aprobare. Întârzierea e mai riscantă decât update-ul.

### 7.3 Lista de risc

Per site. **Populată automat** la conectare, din categoria pluginului (page buildere, comerț, plăți, formulare, cache) și din istoricul de incidente. Suprascrisă manual acolo unde e nevoie.

### 7.4 Smoke check după update

**Etapa 1 — fără imagini, implementată.** Pe 3-5 URL-uri cheie:
- status 200
- absența unui `Fatal error` în corpul răspunsului
- prezența selectorului-canar (de obicei footerul)
- numărul de noduri DOM față de măsurătoarea anterioară

Prinde ~80% din stricăciuni, cost aproape zero, zero false pozitive.

**Etapa 2 — capturi fără diff automat.** Screenshot înainte și după, stocate pentru verificare manuală și pentru raport. Retenție: ultimele 3 seturi per site.

**Etapa 3 — diff de pixeli cu zone mascate.** *Nu se implementează.* Falsele pozitive din carusele, bannere rotative, produse aleatorii și conținut lazy-loaded transformă gestionarea zonelor mascate într-o slujbă.

### 7.5 URL-uri cheie

Derivate automat, suprascriabile:
1. Homepage
2. Primele 3 pagini după clicuri din Search Console
3. Pagina de contact, detectată după formular
4. O pagină de produs sau categorie, dacă e WooCommerce

Fără Search Console: sitemap și cele mai linkuite pagini interne. Recalculare trimestrială, cu blocare pe cele suprascrise manual.

---

## 8. Backupuri

- Backup programat, multi-destinație
- Politici de retenție
- Restore complet, fișiere și bază de date
- **Restore verificat automat** — lunar, într-un container efemer
- Descărcare prin link semnat
- Backup al aplicației însăși (ops, nu produs)

> Restore-ul verificat e diferențiatorul. Niciun competitor nu testează automat că backupul funcționează. „Backupul tău a fost testat pe 12 aprilie" e o propoziție pe care n-o poate scrie nimeni altcineva.

---

## 9. Planuri și profiluri

Despărțire în două, ca să nu configurezi 60 de site-uri individual.

| | Ce definește | Câte există |
|---|---|---|
| **Plan** | șablon refolosibil: nivel uptime, ritm backup, checkuri active, cadență raport | 3 (Bază, Standard, Premium) |
| **Profil** | ce nu se poate împărți: URL-uri cheie, selector-canar, listă de risc, adresă de test formulare | unul per site |

Planul se leagă de **site**, nu de client. Site-uri ale aceluiași client pot avea planuri diferite.

---

## 10. Conectarea unui site nou

Un singur ecran, nu un wizard.

1. Lipești URL-ul
2. Se instalează conectorul
3. Detectare automată: versiune WP, PHP, pluginuri, WooCommerce, plugin de formulare, disponibilitate Search Console
4. Aplicația propune un profil complet — nivel de monitorizare, URL-uri cheie, listă de risc, program de backup
5. Confirmi sau ajustezi
6. La salvare: primul backup + scanarea de referință

---

## 11. Integrări

| Sursă | Ce aduce | Domeniu |
|---|---|---|
| **Wordfence Intelligence** | baza de vulnerabilități WordPress, JSON + webhook | global, gratuit, fără autentificare |
| **Google Search Console** | clicuri, impresii, poziție medie, pagini indexate, erori de acoperire | un singur cont |
| **Google Analytics 4** | sesiuni, utilizatori, conversii, surse, pagini de top | un singur cont |
| **Cloudflare** | cereri, procent din cache, trafic economisit, amenințări blocate | un singur token |
| **Telegram** | canal unic de alerte critice | bot existent din SAD Hub |
| **app.simplead.ro** | sarcini per site, doar citire | API unidirecțional |
| **Destinații de stocare** | NAS, cloud | pentru backupuri |

### Note

- **Wordfence, nu WPScan.** WPScan interzice cache-ul datelor și cere cont Enterprise pentru integrarea în servicii proprii. Fără cache: peste 1.100 de apeluri per scanare a flotei.
- **Patchstack — amânat după lansare.** E strat de protecție (virtual patching), nu sursă de date. Ar trăi ca plugin pe site-ul clientului, facturat separat.
  - *Precauție de arhitectură:* modelul de vulnerabilitate primește de la început starea cu trei valori — vulnerabil / actualizat / mitigat. „Mitigat" rămâne nefolosit până atunci, dar eviți o migrare.
- **Alertele critice se retrimit din 15 în 15 minute până la confirmare.** Un singur canal și un singur om înseamnă că un incident la 3 dimineața depinde de faptul că vezi notificarea.

---

## 12. Raportare

### 12.1 Format

Un raport **per site**, nu per client. Automat, white-label, PDF.

### 12.2 Secțiuni, în ordinea importanței pentru client

1. **Rezumat** — starea generală a lunii
2. **Disponibilitate** — uptime, timp de răspuns, SSL, domeniu
3. **Mentenanță efectuată** — update-uri, backupuri, restore verificat, sarcini finalizate din app.simplead.ro
4. **Securitate** — vulnerabilități, integritate, utilizatori, amenințări blocate
5. **Performanță** — PageSpeed, Core Web Vitals, procent servit din cache
6. **Trafic** — sesiuni și conversii din GA4, clicuri și poziții din Search Console

Datele de audit SEO/CRO intră prin API din aplicația separată, când va exista.

### 12.3 Bariere de siguranță — obligatorii

Raportul pleacă automat, **dacă totul arată în regulă**. Nu pleacă dacă:

- există un incident critic nerezolvat pe site
- o secțiune n-are date pentru că o integrare a picat (mai bine omisă decât afișată cu zero)
- o valoare iese dintr-un interval rezonabil — uptime sub 99%, salt de trafic de câteva ori

În loc să plece, intră într-o coadă și primești notificare.

> Primul raport care pleacă singur cu date greșite face mai mult rău decât zece rapoarte trimise târziu.

### 12.4 Cifra care justifică factura

Secțiunea de mentenanță e singura care demonstrează muncă preventivă. Restul spun „nu s-a stricat nimic".

> *„Am identificat 847 de avertismente generate de JetWooBuilder 2.1.3. Am raportat problema dezvoltatorului și am aplicat versiunea 2.1.4 pe 18 iulie. Erori active în prezent: 0."*

---

## 13. Presetări WordPress — 10 setări

Din cele 55 disponibile în conector. Restul rămân în cod, dispar doar din interfață.

### Aplicate automat pe toate site-urile

| Setare | Ce face | Atenție |
|---|---|---|
| **Oprire update-uri automate** | dezactivează actualizările automate WordPress | **Esențială.** Fără ea, site-ul se actualizează singur și Manager pierde controlul |
| **Curățare standard head** | emoji, RSD, manifest WLW, shortlinks, linkuri REST, generator, prefetch DNS, pingback-uri proprii, dashicons în frontend | sigur peste tot |
| **Control Heartbeat** | reglează frecvența în dashboard, editor, frontend | reduce încărcarea serverului |
| **Limitare revizii** | limitează reviziile per articol | previne umflarea bazei de date |
| **Limite imagini la upload** | lățime, înălțime, calitate JPEG maxime | doar la încărcări noi |

### Condiționate — doar unde WooCommerce e detectat

| Setare | Ce face | Atenție |
|---|---|---|
| **Fragmente coș** | oprește scriptul AJAX de actualizare a coșului | cel mai mare câștig de viteză pe Woo; coșul din header nu se mai împrospătează fără reîncărcare |
| **Scripturi Woo pe pagini non-magazin** | nu încarcă bibliotecile unde nu sunt necesare | sigur aproape peste tot |

### Unelte la cerere

| Unealtă | Ce face |
|---|---|
| **Înlocuire fișier media** | schimbă fișierul păstrând URL-ul — previne linkuri rupte |
| **Ascundere notificări pluginuri** | pentru non-admini; clientul nu mai vede îndemnuri de upgrade |
| **CSS și subsol admin** | branding SimpleAD |

### Aplicare

Pachet implicit „Standard SimpleAD", aplicat automat la conectare, cu abateri per site.

### Setări respinse și de ce

| Categorie | Exemple | Motiv |
|---|---|---|
| Riscante sau dependente de temă | Google Fonts, jQuery Migrate, stiluri globale, widget-uri de blocuri, Gutenberg, lazy load | strică lucruri greu de prezis |
| Dăunătoare | redirect 404 către homepage | Google le vede ca soft-404 |
| Decizii editoriale | comentarii, feed-uri, embeds, arhive de autor, sitemap nativ | depind de site și de pluginul SEO |
| Cosmetice | organizator meniu, meniu lat, widget-uri dashboard, bară admin, CSS frontend, prefix titlu | fără impact operațional |
| Riscante sau rar folosite | SVG (securitate), AVIF, duplicare conținut, publicare automată a programărilor ratate | raport valoare/risc slab |

---

## 13bis. Sistem de design

### Decizia de bază: set propriu, nu kit

| Opțiune | Verdict |
|---|---|
| Flowbite / Preline / daisyUI | **nu** — 60 de componente din care folosești 12, plus o dependență de întreținut și un aspect recognoscibil |
| Filament | **nu** — ar însemna rescrierea celor 111 componente Livewire existente |
| **Token-uri proprii + componente Blade** | **da** — la un utilizator și ~15 ecrane, mai ieftin de construit *și* de întreținut |

### Token-uri — 19

| Categorie | Valori |
|---|---|
| Suprafețe | pagină, card, hover |
| Text | primar, secundar, estompat |
| Borduri | 0.5px normală, accentuată |
| Semantice | ok, atenție, critic, inactiv |
| Raze | 8px controale, 12px carduri |
| Spațiere | 4, 8, 12, 16, 24 |

### Componente Blade — 12

1. `x-layout.fleet` — shell context flotă
2. `x-layout.site` — shell context site
3. `x-sidebar-item` — cu suport pentru subsecțiuni și contor
4. `x-status-icon` — iconița colorată din strip
5. `x-site-row` — rândul din lista de site-uri
6. `x-site-card` — cardul din vederea grid
7. `x-metric` — cifră plus etichetă
8. `x-badge` — contor colorat
9. `x-table`
10. `x-empty-state`
11. `x-toolbar` — bara de acțiuni la selecție multiplă
12. `x-confirm-dialog`

### Reguli vizuale

| Regulă | Detaliu |
|---|---|
| **Iconițe** | Tabler outline, auto-găzduite ca sprite SVG. Fără CDN. |
| **Tipografie** | o singură familie sans (Inter sau stack de sistem) |
| **Greutăți** | **doar 400 și 500.** 600 și 700 arată greoi într-o interfață densă |
| **Scală** | 11, 12, 13, 15, 18, 24 |
| **Densitate rând** | **44px, nu 64px** |
| **Umbre și gradienți** | niciunul — ierarhia se face din borduri de 0.5px și spațiere |
| **Dark mode** | nu în v1; token-urile fac trecerea ușoară mai târziu |

> **De ce contează densitatea:** ecranul principal e un tabel cu ~60 de rânduri, deschis de zece ori pe zi. La 44px vezi 12 site-uri fără scroll; la 64px vezi 8.

---

## 14. Decizii tehnice

### 14.1 Retenție

| Date | Perioadă |
|---|---|
| Ping-uri uptime brute | 14 zile |
| Agregate orare uptime | 13 luni |
| Incidente | permanent |
| Erori PHP brute | 30 zile |
| Erori PHP agregate | 13 luni |
| Capturi de ecran | ultimele 3 seturi per site |
| Rapoarte | permanent |

Cele 13 luni permit comparația „iulie anul ăsta față de iulie anul trecut".

### 14.2 Dimensionare — sub 60 de site-uri

| Resursă | Volum | Concluzie |
|---|---|---|
| Ping-uri uptime | ~1 mil. rânduri/lună brute, ~470k vii cu retenția de 14 zile | trivial pentru PostgreSQL cu index simplu |
| PageSpeed API | ~120 apeluri/lună | cotă gratuită: 25.000/zi |
| Capturi de ecran | sub 400 MB | neglijabil |
| Backupuri | singurul cost real | NAS |

> **Infrastructura nu e o constrângere. Un singur VPS Hetzner duce tot.**

### 14.3 Curățenie în codebase

| Acțiune | Impact estimat |
|---|---|
| Șterge `Services/SeoAudit` + modelele SEO orfane | ~12 modele |
| Șterge `Services/Audit` (30 fișiere) + `Prospect` + modelele de audit | ~20 modele, 29 fișiere de test |
| Șterge `ClientCost`, `ClientRevenue` | conflict cu SAD Hub |
| Șterge `StatusPage` + 4 modele, portal de client, roluri, invitații | ~10 modele |
| Șterge `IncidentResponse` cu remediere automată AI, `AuditAutoApprover` | — |

**Ștergi aproximativ o treime din codebase eliminând 10 linii din lista de funcționalități.**

### 14.4 De conectat, nu de construit

`PluginRiskAssessmentService` **există deja** în cod, dar nu e legat de fluxul de update. E jumătate din „Update Copilot" al concurenței, construit și nefolosit. Cea mai ieftină funcționalitate nouă din tot documentul.

### 14.5 Corecturi la presupuneri anterioare

| Presupunere | Realitate |
|---|---|
| Login instant în wp-admin lipsește | **există** — `WithWpAdminLogin` + `SAM_Login_Endpoint`, token de unică folosință |
| Restore verificat e incomplet | **complet** — model, job `RunProvenRestore`, UI în `SiteBackups` |
| Formulare, WooCommerce, linkuri rupte, licențe | **chiar lipsesc** — zero apariții în cod |

---

## 15. În observație 60 de zile

Instrumentate cu log de acces, decizie pe date, nu pe impresii:

- Tweaks — cele patru taburi
- Curățare bază de date
- Conflicte pluginuri
- Redirecturi

---

## 16. Ordinea de execuție

1. **Ștergerile.** `Services/SeoAudit`, apoi `Services/Audit` și `Prospect`, apoi modelele financiare, portalul și status pages. Cea mai mare reducere de confuzie, cel mai mic risc — cele 212 fișiere de test sunt plasa de siguranță.
2. **Un singur site, cap-coadă.** Cel mai simplu, nu cel mai important: conectare → monitorizare → un update cu rollback → un backup restaurat → un raport livrat pe email.
3. **Restructurarea navigației** în cele două contexte.
4. **Motorul comun de operațiuni**, consolidând `CircuitBreakerService`, `JobTracker` și `Dispatchers`.
5. **Cele patru adăugiri**: formulare, WooCommerce, linkuri rupte, licențe.
6. **Regulile inteligente**, conectând `PluginRiskAssessmentService`.
7. **Decizia pe modulele în observație.**

> **Dacă merge cap-coadă pentru un site, merge pentru șaizeci. Acum nu e demonstrat pentru niciunul.**

---

## 17. Avertisment economic

Costul de construcție e plătit. Costul de **întreținere** e permanent: câteva ore pe lună, la nesfârșit — upgrade-uri de framework, patch-uri în dependențe, conectorul care se rupe la o schimbare din WordPress core, API-uri Google care își schimbă versiunea.

Un tool comercial (WP Umbrella, Modular DS, ManageWP) face ~80% din acest document contra abonament.

**Merită** dacă aplicația se duce la capăt în săptămâni și rămâne infrastructură internă care nu se mai extinde.
**Nu merită** dacă rămâne un proiect deschis, îmbunătățit câte puțin la nesfârșit — atunci taxa de întreținere depășește orice economie.
