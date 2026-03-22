# SimpleAd Manager

Platforma completa de management, monitorizare si securitate pentru site-uri WordPress. Construita cu Laravel 11, Livewire 3, Alpine.js si Tailwind CSS.

Gestioneaza zeci de site-uri WordPress dintr-un singur dashboard — backup-uri automate, monitorizare uptime, scanari de securitate, rapoarte pentru clienti, integrari cu Google Analytics, Cloudflare si multe altele.

---

## Functionalitati

### Management WordPress
- **Pluginuri** — vizualizare, actualizare, activare/dezactivare, stergere, detectie pluginuri abandonate si conflicte
- **Teme** — management teme parinte/copil, actualizari, activare
- **Core WordPress** — monitorizare versiune, actualizari sigure cu rollback automat
- **Utilizatori** — creare, stergere, management roluri, tracking activitate
- **Cron Jobs** — vizualizare si configurare task-uri programate WordPress
- **Actualizari in masa** — bulk update pluginuri/teme pe mai multe site-uri simultan

### Backup & Restaurare
- **Backup-uri automate** — programare zilnica/saptamanala/lunara cu suport timezone
- **Tipuri backup** — complet, incremental, doar baza de date
- **Destinatii multiple** — stocare locala, Dropbox (OAuth), Amazon S3
- **Restaurare** — one-click restore cu tracking progres
- **Retentie** — curatare automata backup-uri vechi
- **Backup aplicatie** — self-backup si restore al platformei SimpleAd Manager

### Monitorizare
- **Uptime** — verificari HTTP/HTTPS la intervale configurabile, calcul procent uptime, detectie degradare
- **Incidente** — tracking automat cand site-ul pica/revine, timeline vizual, istoric
- **Certificate SSL** — verificare validitate, tracking expirare, alerte reinnoire
- **Domenii** — monitorizare expirare domeniu
- **Circuit Breaker** — protectie contra esecurilor in cascada, dezactivare automata monitorizare la erori repetate

### Performanta
- **Google PageSpeed** — integrare API PageSpeed Insights, testare desktop si mobil
- **Metrici** — CLS, FID, LCP, TTFB, INP, FCP pe fiecare pagina
- **Bugete de performanta** — setare praguri si alerte la depasire
- **Tendinte** — istoric 30 zile, medii mobile, comparatii
- **Alerte automate** — notificare la degradare performanta

### Securitate
- **Dashboard securitate** — scor global, site-uri la risc, actiuni recomandate
- **Scanare** — detectie malware, vulnerabilitati, scanari programate
- **Hardening** — dezactivare editor teme, ascundere versiune WP, blocare XML-RPC, headere securitate, reguli .htaccess
- **Protectie login** — CAPTCHA, rate limiting, blocare IP automata la brute force
- **Management IP** — whitelist/blacklist, ban temporar cu expirare automata
- **Integritate fisiere core** — verificare fisiere WordPress originale, detectie modificari
- **Sanatate baza de date** — monitorizare dimensiune, curatare revizii/transienti/spam, optimizare
- **Preset-uri securitate** — template-uri de configurare, aplicare in masa pe mai multe site-uri
- **Log activitate** — audit trail complet: logari, actiuni admin, tracking IP
- **Verificare email** — testare deliverabilitate SMTP

### Analytics & SEO
- **Google Analytics** — autentificare OAuth 2.0, suport multi-proprietati, date real-time si istorice
- **Google Search Console** — performanta cautari, impresii, click-uri, CTR, pozitii
- **Tracking cuvinte cheie** — monitorizare pozitii, istoric, click-uri si impresii per keyword

### Cloudflare
- **Conexiuni multiple** — management conturi Cloudflare cu API token
- **DNS** — vizualizare, creare, stergere inregistrari DNS
- **Firewall** — reguli firewall, rate limiting, blocare IP
- **Cache** — purge URL-uri, analytics cache
- **Analytics** — trafic, securitate, performanta

### Rapoarte
- **Generare automata** — rapoarte PDF programate (zilnic, saptamanal, lunar, la cerere)
- **Template-uri** — creare si personalizare template-uri raport
- **Continut** — overview site, performanta, securitate, uptime, analytics, recomandari
- **Livrare** — trimitere automata pe email catre clienti cu URL-uri semnate
- **Branding** — logo si personalizare aspect raport

### Notificari
- **Canale multiple** — Email, Slack, Discord, Telegram, Webhooks HTTP
- **Tipuri alerte** — uptime down/recovery, SSL expirare, degradare performanta, esec backup, vulnerabilitati, actualizari disponibile
- **Configurare** — ore de liniste (do-not-disturb), toggle per tip alerta, digest zilnic
- **Istoric** — log complet livrari, status trimitere, retry

### Pagini de Status
- **Pagini publice** — status page pentru fiecare client/proiect
- **Domeniu custom** — suport domenii personalizate
- **Incidente** — creare, tracking status (investigare/identificat/monitorizare/rezolvat), timeline
- **Componente** — grupare site-uri, afisare procent uptime, istoric incidente
- **Protectie** — optiune parola pentru acces restrictionat

### Clienti & Planuri de Mentenanta
- **Clienti** — profil client, informatii contact, note, asociere site-uri
- **Planuri mentenanta** — template-uri cu module configurabile (securitate, performanta, backup)
- **Aplicare in masa** — aplicare plan pe mai multe site-uri simultan

### Plugin Connector WordPress
Plugin WordPress custom instalat pe fiecare site gestionat. Comunica cu platforma prin 19+ endpoint-uri REST API:

- Info site, pluginuri, teme, utilizatori, core WordPress
- Backup si restaurare remote
- Scanare securitate si push configurari
- Management baza de date si cron jobs
- Monitorizare si diagnostice
- Auto-update plugin (push din platforma)
- Hardening securitate, protectie login, management IP
- Log audit si request logging
- Rate limiting si autentificare API

---

## Stack Tehnic

| Componenta | Tehnologie |
|------------|-----------|
| **Backend** | PHP 8.3, Laravel 11 |
| **Frontend** | Livewire 3, Alpine.js, Tailwind CSS, Vite |
| **Baza de date** | PostgreSQL 16 |
| **Cache & Cozi** | Redis, Laravel Horizon |
| **Infrastructura** | Docker, Nginx, PgBouncer |
| **Monitorizare cozi** | Laravel Horizon |

---

## Setup Local

### Cerinte
- Docker si Docker Compose
- Node.js 20+ si npm

### Pornire

```bash
# Cloneaza repository-ul
git clone <repo-url> && cd simplead-manager

# Copiaza fisierul de environment
cp .env.example .env

# Porneste containerele Docker
docker compose up -d

# Instaleaza dependintele PHP
docker exec simplead-app composer install

# Genereaza cheia aplicatiei
docker exec simplead-app php artisan key:generate

# Ruleaza migrarile si seeder-ele
docker exec simplead-app php artisan migrate --seed

# Instaleaza si compileaza asset-urile frontend
npm install
npm run dev

# Porneste Horizon (worker cozi)
docker exec simplead-app php artisan horizon
```

Aplicatia va fi disponibila la `http://localhost`.

### Teste

```bash
docker exec simplead-app php artisan test
```

### Comenzi Utile

```bash
# Curata toate cache-urile
docker exec simplead-app php artisan optimize:clear

# Ruleaza scheduler-ul manual
docker exec simplead-app php artisan schedule:run

# Vizualizeaza task-urile programate
docker exec simplead-app php artisan schedule:list
```

---

## Variabile de Mediu

Vezi `.env.production.example` pentru lista completa. Variabile principale:

| Variabila | Descriere |
|-----------|-----------|
| `APP_URL` | URL-ul aplicatiei |
| `DB_*` | Conexiune PostgreSQL |
| `REDIS_*` | Conexiune Redis |
| `MAIL_*` | Configurare SMTP |
| `PAGESPEED_API_KEY` | Cheie API Google PageSpeed Insights |
| `GOOGLE_CLIENT_ID/SECRET` | OAuth2 Google pentru Analytics/Search Console |
| `DROPBOX_APP_KEY/SECRET` | OAuth Dropbox pentru backup |

---

## Structura Proiect

```
app/
  Http/Controllers/      # Controllere HTTP
  Http/Middleware/        # Middleware custom (headere securitate, limba)
  Jobs/                   # Job-uri background (backup, monitorizare, notificari)
  Livewire/               # Componente Livewire (72 pagini/feature-uri)
  Models/                 # Modele Eloquent (75+)
  Services/               # Logica business (60+)
  Notifications/          # Notificari multi-canal
resources/
  views/
    components/           # Componente Blade (layout, UI)
    livewire/             # View-uri componente Livewire
    auth/                 # Pagini autentificare
    errors/               # Pagini eroare custom (404, 500, 503)
routes/
  web.php                 # Rute web
  auth.php                # Rute autentificare
  console.php             # Task-uri programate (41 job-uri)
lang/                     # Fisiere traducere (en.json, ro.json)
wordpress-plugin/         # Plugin connector WordPress (19+ endpoint-uri)
docker/                   # Configurari Docker (nginx, php, opcache)
```

---

## Deployment

Vezi [DEPLOYMENT.md](DEPLOYMENT.md) pentru instructiuni de deployment in productie.
