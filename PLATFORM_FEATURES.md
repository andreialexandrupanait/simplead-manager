# SimpleAd Manager — Module & Functionalitati

> Stadiul actual al platformei — Aprilie 2026

---

## 1. Dashboard

- Overview global cu carduri per site (favicon, nume, health score, status)
- Sistem de health scoring cu nivele colorate
- Cautare, filtrare (dupa status sanatate, client) si sortare site-uri
- Selectie bulk si actiuni in masa (backup, sync, verificare uptime, generare raport)
- Redenumire si stergere site-uri
- Reordonare manuala a site-urilor
- Widget-uri configurabile

---

## 2. Managementul Site-urilor

### Creare & Configurare
- Wizard multi-step pentru adaugarea de site-uri noi
- Setari per site (conexiune, metadata, module active)
- Asignare client la site

### Overview per Site
- Dashboard cu carduri: uptime, performanta, backup-uri, baza de date, securitate, SEO, analytics, Search Console, rapoarte, server resources
- Health bar cu scor calculat
- Status conexiune WordPress si circuit breaker
- Login direct in WP Admin

### Plugin-uri & Teme
- Listare, cautare, filtrare plugin-uri si teme
- Update individual sau bulk
- Activare / dezactivare plugin-uri
- Detectie plugin-uri abandonate (> 2 ani fara update)
- Detectie conflicte intre plugin-uri
- Detectie vulnerabilitati cunoscute

### Cron Jobs WordPress
- Listare cron jobs din WordPress
- Rulare manuala, activare/dezactivare

### Baza de Date
- Optimizare tabele
- Conversie engine (MyISAM ↔ InnoDB)
- Stergere tabele
- Cleanup automat (revizii, comentarii spam, metadata orfana)
- Configurare cleanup programat
- Monitorizare sanatate baza de date

### Bulk Settings
- Copiere setari de la un site la altul
- Aplicare configuratii bulk pe mai multe site-uri

---

## 3. Backup & Recovery

### Backup-uri Site
- Backup complet (baza de date + fisiere)
- Backup incremental
- Programare automata (cron configurabil)
- Tracking status: pending, in progress, completed, failed
- Criptare AES-256-GCM
- Browsing continut backup

### Destinatii de Stocare
- Local filesystem
- Amazon S3
- Dropbox
- Google Drive

### Restaurare
- Restaurare one-click din backup
- Verificare integritate post-restaurare
- Rollback points

### Politici de Retentie
- Configurare retentie per destinatie
- Cleanup automat backup-uri expirate
- Monitorizare spatiu de stocare

### Backup Aplicatie
- Backup la nivel de aplicatie (baza de date PostgreSQL)
- Programare automata
- Download si restaurare

---

## 4. Monitorizare Uptime

- Monitorizare continua disponibilitate site-uri
- Configurare interval de verificare
- Tracking incidente downtime (durata, cauza)
- Grafice response time
- Dashboard global uptime pe toate site-urile
- Notificari la downtime
- Bara vizuala uptime percentage

---

## 5. Monitorizare Performanta

- Integrare Google PageSpeed Insights API
- Metrici Core Web Vitals: FCP, SI, LCP, TTI, TBT, CLS
- Scoruri mobile si desktop
- Trenduri performanta in timp
- Performance budgets (praguri configurabile)
- Comparare cu competitori
- Alerte la degradare performanta
- Dashboard global performanta

---

## 6. Securitate

### Dashboard Global Securitate
- Status securitate agregat pe toate site-urile
- Amenintari si alerte active
- Scor securitate global

### Per Site — 8 Sub-Module:

#### 6.1 Overview Securitate
- Scor securitate per site
- Rezultate audit recente
- Activare/dezactivare module securitate

#### 6.2 Hardening
- Masuri recomandate de securizare WordPress
- Reguli .htaccess
- Configurari securitate

#### 6.3 Protectie Login
- Protectie brute-force
- Politici parole
- Autentificare doi factori (2FA)
- Custom login slug

#### 6.4 CAPTCHA
- Integrare reCAPTCHA
- Protectie anti-bot
- Configurare pe formulare

#### 6.5 Scanare
- Scanare malware
- Detectie vulnerabilitati
- Verificare integritate fisiere core WordPress

#### 6.6 Activitate
- Log activitate complet (audit trail)
- Tentative de login
- Modificari fisiere
- Evenimente securitate

#### 6.7 Managementul Utilizatorilor WP
- Listare utilizatori WordPress
- Roluri si capabilitati
- Detectie utilizatori suspecti
- Detectie si stergere useri spam

#### 6.8 Managementul IP-urilor
- Whitelist / Blacklist IP-uri
- Geo-blocking
- IP-uri banate cu expirare automata

### Preset-uri Securitate
- Creare si gestionare preset-uri
- Aplicare bulk pe mai multe site-uri (admin only)

---

## 7. SEO

### 7.1 Overview SEO
- Scor SEO per site
- Rezultate audit recente
- Metrici rapide

### 7.2 Audit SEO
- Audit complet on-page
- 10 tipuri de verificari:
  - Plugin SEO instalat
  - Meta tags (title, description)
  - robots.txt
  - Sitemap XML
  - Structured data (Schema.org)
  - Link-uri stricate
  - Analiza continut
  - Index coverage
  - Scor on-page
  - Redirect chains

### 7.3 Tracking Cuvinte Cheie
- Monitorizare pozitii keywords tinta
- Istoric pozitii
- Volum cautare

### 7.4 SEO Tehnic
- Validare structured data
- Validare sitemap si robots.txt
- Mobile-friendliness
- Viteza pagina

### 7.5 Crawl Site
- Crawl complet al site-ului
- Configurare adancime si rate limiting
- Respectare robots.txt

### 7.6 Rezultate Crawl
- Pagini crawlabile
- Link-uri stricate
- Erori de crawl

---

## 8. Integrari Analytics

### Google Analytics
- Conectare OAuth
- Date GA4: trafic, utilizatori, sesiuni, engagement
- Cache date pentru performanta

### Google Search Console
- Conectare OAuth
- Keywords, impressions, clicks, CTR, pozitie medie
- Coverage data
- Cache date

---

## 9. Integrare Cloudflare

- Mapare zone Cloudflare pe site-uri
- Purge cache
- Vizualizare analytics Cloudflare (request-uri, bandwidth, amenintari)
- Sincronizare date zona

---

## 10. Rapoarte

### Generare Rapoarte
- Generare on-demand sau programata
- Export PDF via Gotenberg
- Template-uri customizabile

### 16+ Sectiuni Raport
- Executive snapshot
- Overview & infrastructura
- Performanta & response time
- Uptime & incidente
- Securitate & verificari securitate
- Backup-uri & capacitate restaurare
- Baza de date & sanatate BD
- Stabilitate tehnica & update-uri
- Inventar plugin-uri & plugin-uri outdated
- Utilizatori WordPress
- Analytics (2 sectiuni)
- Search Console (2 sectiuni)
- Cloudflare
- SEO & SEO tehnic
- Recomandari

### Recomandari Automate
- Engine de recomandari bazat pe datele de monitorizare
- Template-uri recomandari
- Prioritizare actiuni

### Distributie
- Programare automata distribuire
- Download via link semnat
- Link permanent public cu token
- Bulk download rapoarte

### Portalul Clientului
- Acces securizat cu token
- Vizualizare rapoarte
- Download rapoarte

---

## 11. Managementul Clientilor

- Lista clienti cu cautare si filtrare
- Creare / editare profil client (informatii contact, detalii facturare)
- Detalii client cu site-uri asociate
- Asignare site-uri la clienti
- Portal client cu acces pe baza de token
- Filtrare dashboard dupa client

---

## 12. Planuri de Mentenanta

- Creare si gestionare template-uri de configuratie
- Control module per plan (securitate, analytics, SEO, Cloudflare, etc.)
- Setari securitate si tweaks incluse in plan
- Aplicare bulk pe mai multe site-uri
- Sortare si reordonare planuri

---

## 13. Tweaks WordPress

### 13.1 Performance Tweaks
- Caching, lazy loading, optimizare imagini
- Configurari de performanta

### 13.2 Site Control
- Feature toggles
- Managementul functionalitatilor

### 13.3 Admin UX
- Customizare dashboard WordPress
- Imbunatatiri interfata admin

### 13.4 Content & Media
- Procesare imagini
- Optimizare continut

---

## 14. Notificari

### Canale Suportate
- Email
- Slack
- Discord
- Telegram
- Webhook (custom integrations)

### Functionalitati
- Configurare canale per tip eveniment
- Template-uri notificari customizabile
- Reguli de escalare
- Quiet hours
- Deduplicare notificari
- Procesare batch
- Digest zilnic (trimis la 7:00 AM)

---

## 15. Incident Response (AI-Powered)

### Motor AI
- Integrare Claude API pentru analiza si recomandari
- Guardrails de siguranta configurabile

### 5 Playbook-uri Automate
1. **Site Down** — raspuns la downtime
2. **Security Critical** — incidente critice de securitate
3. **Database Critical** — probleme baza de date
4. **Vulnerable Plugin** — detectie plugin vulnerabil
5. **Performance Drop** — degradare performanta

### Functionalitati
- Detectie proactiva (verificare la fiecare 5 minute)
- Colectare context automat
- Executie actiuni remediere
- Cooldown si rate limiting
- Log actiuni si raspunsuri

---

## 16. Status Pages

- Creare si gestionare pagini de status publice
- Componente cu status per site
- Raportare incidente cu update-uri
- Template-uri incidente
- Badge SVG embeddable
- Autentificare optionala
- API endpoint pentru integrari

---

## 17. Setari Aplicatie

| Sectiune | Descriere |
|----------|-----------|
| General | Nume aplicatie, branding |
| Profil | Profil utilizator, schimbare parola, preferinte |
| Utilizatori | Management conturi, roluri, invitatii |
| Notificari | Configurare canale si preferinte |
| Email | SMTP, adrese, template-uri email |
| Integrari | API keys, OAuth (Google, Dropbox) |
| WordPress | Configurare plugin connector |
| Retentie Date | Politici retentie backup-uri, rapoarte, loguri |
| Template-uri Rapoarte | Creare si customizare template-uri |
| AI Incident Response | Configurare raspuns automat incident |
| Backup Aplicatie | Backup/restaurare nivel aplicatie |

---

## 18. WordPress Connector Plugin (v2.10.0)

### Autentificare & Securitate
- Autentificare HMAC
- Rate limiting (exceptie endpoint-uri backup)
- IP whitelisting
- Logging request-uri

### 20+ Endpoint-uri REST API

| Endpoint | Functionalitati |
|----------|----------------|
| Info | Informatii site, versiune, endpoint-uri active |
| Plugins | Listare, update, activare, dezactivare, stergere, flush OPcache |
| Themes | Listare, update, activare, stergere |
| Users | Listare, creare, update, stergere, bulk-delete, sync WooCommerce |
| Core | Update-uri WordPress core |
| Health | Status sanatate site |
| Security | Verificari securitate, fix-uri, integritate core |
| Security Settings | Setari hardening, management stare |
| Backup | DB/files backup, restore, prepare (combined/async/execute/finalize), upload chunked/direct, manifest, incremental |
| Rollback | Rollback plugin/theme/core |
| Database | Health, cleanup stats, cleanup run, optimize tabele, conversie engine, stergere tabele |
| Cron | Listare, rulare, activare/dezactivare |
| Monitoring | Resurse server (CPU, memorie, disk) |
| Audit | Retrievere loguri audit |
| Login | Management URL login custom |
| Self-Update | Auto-update plugin cu management OPcache |
| Cache | Golire cache |
| Diagnostic | Diagnosticare, fix Elementor, activare/dezactivare plugin-uri |
| Site Tweaks | Setari si stare tweaks |
| SEO | Analiza SEO |

### Module Always-Active pe WordPress
- Audit logging
- Login handler & security login
- Security hardening & CAPTCHA
- IP manager & whitelist
- Performance tweaks
- Site control & Admin UX tweaks
- Content & media tweaks
- Rate limiting

---

## 19. Infrastructura & Automatizari

### Taskuri Programate (Cron)
- Monitoring dispatcher — verificari uptime, securitate (1-5 min)
- Data sync dispatcher — Analytics, Search Console, Cloudflare, WordPress (1-5 min)
- Backup dispatcher — backup-uri site si aplicatie
- Report dispatcher — generare rapoarte programate (5 min)
- Incident response dispatcher — detectie proactiva (5 min)
- Procesare batch notificari (1 min)
- Escalare notificari (5 min)
- Digest zilnic (7:00 AM)
- Validare conexiuni externe (zilnic 6:00 AM)
- PostgreSQL dump (zilnic 2:30 AM)
- VACUUM ANALYZE (duminica 3:00 AM)
- Cleanup retentie date (zilnic 3:00 AM)
- Cleanup comenzi securitate stale (15 min)
- Pruning activity logs (zilnic 3:30 AM)
- Cleanup IP-uri banate expirate (orar)
- Recalculare scoruri hardening (zilnic 6:00 AM)
- Agregare snapshot-uri lunare (1-a lunii, 2:00 AM)

### Stack Tehnic
- Laravel 11 / PHP 8.3
- Livewire 4 + Blade + Tailwind CSS
- PostgreSQL via PgBouncer (transaction pooling)
- Redis (cache + queues)
- Horizon (queue management)
- Gotenberg (PDF generation)
- Docker (app, horizon, scheduler, nginx, pgsql, pgbouncer, redis)

### Autentificare
- Login standard email/parola
- Google SSO
- Verificare email
- Sistem de invitatii

---

## Statistici Platforma

| Categorie | Numar |
|-----------|-------|
| Componente Livewire | ~100 |
| Servicii backend | ~130 |
| Job-uri asincrone | ~43 |
| Modele Eloquent | ~75 |
| Enum-uri PHP | 17 |
| Migrari baza de date | ~138 |
| Sectiuni raport | 16+ |
| Verificari SEO | 10 |
| Playbook-uri incident | 5 |
| Canale notificare | 5 |
| Endpoint-uri WP plugin | 20+ |
| Template-uri Blade | ~110 |
