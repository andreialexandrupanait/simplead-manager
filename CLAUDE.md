# SimpleAd Manager

## Architecture
- Laravel 11 / PHP 8.3 app managing multiple WordPress sites via connector plugin
- Docker production: app, horizon, scheduler, nginx, pgsql, pgbouncer, redis
- WordPress connector plugin: `wordpress-plugin/simplead-manager-connector/`
- Deploy: **Coolify** (manual, from the `migration/coolify` branch) — see the numbered **Deployment procedure** below. `deploy.sh` is stale (pre-Coolify).
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

- **"deploy"** or **"deploy prod"** — Follow the numbered **Deployment procedure** below (Coolify). Do NOT run `deploy.sh` or `docker compose -f docker-compose.prod.yml …` — see the warning there.
- **"logs"** — Show last 50 error-level entries from today's production Laravel log
- **"status"** — Show `docker ps --filter 'label=coolify.applicationId=6' --format '{{.Names}} {{.Status}}'`

### Deployment procedure (Coolify — READ THIS)

Production `manager.simplead.ro` is **Coolify-managed** and deploys from the **`migration/coolify`** branch (NOT `main`). Coolify runs locally (UI on `:8000`); app = `managersimpleadro`, resource UUID `y12jcr1kywwdseq7i1cevtjv`, `coolify.applicationId=6`.

1. **Make the change on a branch off `migration/coolify`** (not `main` — `main` is not deployed). e.g. `git checkout migration/coolify && git pull && git checkout -b hotfix/<name>`.
2. **Push the branch and merge it into `migration/coolify`** via a PR whose **base is `migration/coolify`** (compare link: `https://github.com/andreialexandrupanait/simplead-manager/compare/migration/coolify...<branch>?expand=1`). Direct pushes to `main`/prod are protected/blocked; feature-branch pushes are fine.
3. **Trigger the deploy in the Coolify UI** — click **Deploy/Redeploy** on the app. **Coolify does NOT auto-deploy on push/merge.** The multi-stage build (npm + composer) takes ~4 min, then it recreates the containers.
4. **⚠️ Run migrations MANUALLY — Coolify does NOT run them.** After the deploy, inside the running app container:
   ```
   docker exec -i $(docker ps --filter name=manager-app --format '{{.Names}}') php artisan migrate:status   # check
   docker exec -i $(docker ps --filter name=manager-app --format '{{.Names}}') php artisan migrate --force   # run pending
   ```
   `migrate` writes to the DB (not the read-only FS), so it works. Check status with a grep on the exact `Pending$` status column, not the migration name. **New code that references a not-yet-run migration's columns/tables will break at runtime**, so never skip this step.
5. **Verify from the server:** `docker ps --filter 'label=coolify.applicationId=6'` (containers recreated + `healthy`), `docker exec <app> sh -lc 'echo $SOURCE_COMMIT'` (new commit hash), and grep the changed code / check the new schema in the live container.

## Other Commands
- ⚠️ `./deploy.sh` and `docker compose -f docker-compose.prod.yml …` are **STALE (pre-Coolify)** — they build/recreate under a different compose project than the live Coolify one (`docker compose -f docker-compose.prod.yml ps` is empty), so they would spawn conflicting containers and break prod. Deploy via Coolify (procedure above).
- Live logs: `docker logs -f $(docker ps --filter name=manager-app --format '{{.Names}}')` (or `manager-horizon` for the queue)
- Queue: managed by Horizon (container `manager-horizon-…`)
- Build assets: `npm run build`  ·  Dev server: `npm run dev`
