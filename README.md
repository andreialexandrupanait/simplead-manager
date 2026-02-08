# SimpleAd Manager

WordPress site management platform built with Laravel 11, Livewire 3, Alpine.js, and Tailwind CSS.

## Tech Stack

- **Backend:** PHP 8.3, Laravel 11
- **Frontend:** Livewire 3, Alpine.js, Tailwind CSS, Vite
- **Database:** PostgreSQL 16
- **Queue/Cache:** Redis, Laravel Horizon
- **Infrastructure:** Docker, Nginx

## Features

- Multi-site WordPress management (plugins, themes, core updates)
- Automated backups with multiple storage destinations (local, Dropbox)
- Uptime monitoring with configurable intervals
- SSL certificate and domain expiry tracking
- Performance monitoring via PageSpeed Insights API
- Broken link scanning
- Security scanning and vulnerability checks
- Google Analytics and Search Console integration
- Cloudflare integration
- WooCommerce stats sync
- Multi-channel notifications (email, Slack, Discord, Telegram, webhooks)
- Report generation and scheduling
- Status pages with custom domains
- Application self-backup and restore
- Two-factor authentication (TOTP)
- Activity logging and audit trail
- Localization (English, Romanian)

## Local Development Setup

### Prerequisites

- Docker and Docker Compose
- Node.js 20+ and npm

### Getting Started

```bash
# Clone the repository
git clone <repo-url> && cd simplead-manager

# Copy environment file
cp .env.example .env

# Start Docker containers
docker compose up -d

# Install PHP dependencies
docker exec simplead-app composer install

# Generate app key
docker exec simplead-app php artisan key:generate

# Run migrations and seeders
docker exec simplead-app php artisan migrate --seed

# Install and build frontend assets
npm install
npm run dev

# Start Horizon (queue worker)
docker exec simplead-app php artisan horizon
```

The application will be available at `http://localhost`.

### Running Tests

```bash
docker exec simplead-app php artisan test
```

### Useful Commands

```bash
# Clear all caches
docker exec simplead-app php artisan optimize:clear

# Run scheduler manually
docker exec simplead-app php artisan schedule:run

# View scheduled tasks
docker exec simplead-app php artisan schedule:list
```

## Environment Variables

See `.env.production.example` for a full list of production environment variables. Key variables:

| Variable | Description |
|----------|-------------|
| `APP_URL` | Application URL |
| `DB_*` | PostgreSQL connection settings |
| `REDIS_*` | Redis connection settings |
| `MAIL_*` | SMTP mail configuration |
| `PAGESPEED_API_KEY` | Google PageSpeed Insights API key |
| `GOOGLE_CLIENT_ID/SECRET` | Google OAuth2 for Analytics/Search Console |
| `DROPBOX_APP_KEY/SECRET` | Dropbox OAuth for backup storage |

## Project Structure

```
app/
  Http/Controllers/     # HTTP controllers (auth, backups, health)
  Http/Middleware/       # Custom middleware (security headers, locale)
  Jobs/                 # Queue jobs (backups, monitoring, notifications)
  Livewire/             # Livewire components (pages)
  Models/               # Eloquent models
  Services/             # Business logic services
resources/
  views/
    components/         # Blade components (layouts, UI)
    livewire/           # Livewire component views
    auth/               # Authentication views
    errors/             # Custom error pages (404, 500, 503)
routes/
  web.php               # Web routes
  auth.php              # Authentication routes
  console.php           # Scheduled tasks
lang/                   # Translation files (en.json, ro.json)
```

## Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for production deployment instructions.
