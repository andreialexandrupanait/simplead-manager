# SimpleAd Manager

## Architecture
- Laravel 11 / PHP 8.3 app managing multiple WordPress sites via connector plugin
- Docker production: app, horizon, scheduler, nginx, pgsql, pgbouncer, redis
- WordPress connector plugin: `wordpress-plugin/simplead-manager-connector/`
- Deploy: `./deploy.sh` (git pull → npm build → docker build → recreate → migrations)
- Frontend: Livewire 4 + Blade + Tailwind CSS + Vite

## Project Structure
- `app/Livewire/` — Livewire components (Sites, Backups, Dashboard, Security, Uptime, etc.)
- `app/Services/` — Business logic services
- `app/Jobs/` — Queued jobs (backup, restore, plugin push, etc.)
- `app/Models/` — Eloquent models
- `app/DTOs/` — Data transfer objects
- `app/Enums/` — PHP enums
- `app/Dispatchers/` — Job dispatchers
- `resources/views/` — Blade templates
- `wordpress-plugin/` — WP connector plugin source

## Conventions
- PHP 8.3 strict types, PSR-12 coding standard
- Use Laravel Pint for code formatting (`./vendor/bin/pint`)
- Livewire components for interactive UI
- Services pattern: business logic in `app/Services/`
- Queue heavy operations (API calls, backups, restores)
- Database: PostgreSQL via PgBouncer (transaction pooling)
- Use `jsonb` column type (not `json`) for PostgreSQL
- Never call `env()` outside config files — use `config()` instead

## Key Patterns
- Site model uses `url` column (not `domain`)
- WP connector plugin: `shell_exec` is disabled on target WP hosts — never use it
- Plugin version: keep header `Version:` and `SAM_VERSION` constant in sync
- Container is read-only in production — use `docker exec -i` to pipe scripts
- Cloudflare proxy: loopback requests from WP server get 403 — this is expected
- Plugin push to WP: must use signed URL route (`download.connector-plugin.signed`)

## Linting
- Lint check: `./vendor/bin/pint --test`
- Lint fix: `./vendor/bin/pint`
- Static analysis: `./vendor/bin/phpstan analyse`

## Quick Commands (user shortcuts)
When the user says any of these, execute immediately without asking:

- **"deploy"** or **"deploy prod"** — Run steps manually: DOCKER_BUILDKIT=1 docker compose -f docker-compose.prod.yml build app nginx (frontend assets built inside Docker multi-stage), then horizon:terminate, artisan down, `up -d --force-recreate --remove-orphans app horizon scheduler`, wait for healthy, migrate --force, queue:restart, artisan up, `up -d --force-recreate nginx`
- **"logs"** — Show last 50 error-level entries from today's production Laravel log
- **"status"** — Show `docker compose -f docker-compose.prod.yml ps`

### Deployment workflow
1. Modify code locally
2. Commit + push when satisfied
3. `deploy` → production via https://manager.simplead.ro

## Other Commands
- Full deploy script: `./deploy.sh` (includes git pull — use for deploy after push)
- Live logs: `docker compose logs -f app`
- Queue: managed by Horizon (`docker compose logs -f horizon`)
- Build assets: `npm run build`
- Dev server: `npm run dev`
