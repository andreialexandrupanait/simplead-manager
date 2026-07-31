# SimpleAd Manager

## Architecture
- Laravel 11 / PHP 8.3 app managing multiple WordPress sites via connector plugin
- Docker production: `manager-{app,horizon,scheduler,nginx,pgbouncer,gotenberg}`. Postgres and Redis are **managed Coolify resources**, not compose services; Traefik terminates TLS.
- WordPress connector plugin: `wordpress-plugin/simplead-manager-connector/`
- Deploy: **Coolify** (manual, from `main`) — see the numbered **Deployment procedure** below.
- **`docker-compose.coolify.yml` is the only deploy stack.** The pre-Coolify one (`docker-compose.prod.yml` + `deploy.sh`, which ran Postgres/Redis/certbot on the host) was deleted on 2026-07-31; it had been dead since the 07-29 cutover and would have spawned containers under a different compose project. Anything still referring to it lives in `docs/archive/`.
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

- **"deploy"** or **"deploy prod"** — Follow the numbered **Deployment procedure** below (Coolify).
- **"logs"** — Show last 50 error-level entries from today's production Laravel log
- **"status"** — Show `docker ps --filter 'label=coolify.applicationId=6' --format '{{.Names}} {{.Status}}'`

### Deployment procedure (Coolify — READ THIS)

Production `manager.simplead.ro` is **Coolify-managed** and deploys from **`main`**.
Coolify runs locally (UI on `:8000`); app = `managersimpleadro`, resource UUID
`y12jcr1kywwdseq7i1cevtjv`, `coolify.applicationId=6`.

> Until 2026-07-29 prod deployed from `migration/coolify`. That branch is dead —
> 0 ahead of `main`, 89 behind — and `COOLIFY_BRANCH` is `main`. Anything that
> still says otherwise is out of date.

1. **Branch off `main`**: `git checkout main && git pull && git checkout -b hotfix/<name>`.
2. **Merge into `main`.** `main` has no branch protection, so the flow is
   `git checkout main && git merge --ff-only <branch> && git push origin main`.
3. **Trigger the deploy.** Coolify does NOT auto-deploy on push or merge:
   ```
   TOKEN=$(cat /opt/apps/simplead-manager/.coolify-deploy-token)
   curl -H "Authorization: Bearer $TOKEN" \
     "https://server.simplead.ro/api/v1/deploy?uuid=y12jcr1kywwdseq7i1cevtjv&force=false"
   ```
   The multi-stage build (npm + composer) takes ~4 min, then containers are recreated.
   Use `force=true` when the deploy carries **no new code** but must pick up changed
   env vars — without it the containers are not recreated and the env is not applied.
   The token is deploy-scope only: `GET /api/v1/deployments/<uuid>` returns 403, so
   status comes from docker, not the API.
4. **⚠️ Run migrations MANUALLY — Coolify does NOT run them.**
   ```
   docker exec -i $(docker ps --filter name=manager-app --format '{{.Names}}') php artisan migrate:status
   docker exec -i $(docker ps --filter name=manager-app --format '{{.Names}}') php artisan migrate --force
   ```
   Grep the exact `Pending$` status column, not the migration name. New code that
   references a not-yet-run migration's columns breaks at runtime.
   - **`Schema::create` migrations must use `--database=pgsql_direct`** — they wrap in
     a transaction whose prepared statements break under PgBouncer transaction pooling.
     `DB_DIRECT_HOST` is set in prod for exactly this.
   - **After a migration that ALTERs an existing table, recycle PgBouncer:**
     `docker restart $(docker ps --filter name=manager-pgbouncer --format '{{.Names}}')`.
     Otherwise stale prepared plans give intermittent 500s
     (`cached plan must not change result type`).
5. **Verify from the server:** `docker ps --filter 'label=coolify.applicationId=6'`
   (recreated + `healthy`), `docker exec <app> sh -lc 'echo $SOURCE_COMMIT'` (new hash),
   and grep the changed code in the live container.

## Other Commands
- Live logs: `docker logs -f $(docker ps --filter name=manager-app --format '{{.Names}}')` (or `manager-horizon` for the queue)
- Queue: managed by Horizon (container `manager-horizon-…`)
- Build assets: `npm run build`  ·  Dev server: `npm run dev`
- Run the suite on this host: `bin/test [phpunit args]` — spins up `sam-test-pgsql` / `sam-test-redis` on 127.0.0.1 with their own credentials; never touches prod.
- `docker-compose.sandbox.yml` — the throwaway WordPress that "proven restore" (C-08) restores into. Opt-in, started by hand, not part of the deploy. Currently unused (0 sandbox sites registered).

## Repo hygiene
- **DB dumps and migration artifacts do not belong in the repo.** They live in `/home/andrei/backups/simplead-manager/` (`/opt/backups` is root-owned, no passwordless sudo).
- `public/vendor/` is **not** committed — the Docker build runs `vendor:publish --tag=laravel-assets --force` so Livewire's JS stays in lockstep with `composer.lock`. Committed copies drift and make Livewire log "published assets are out of date".
- `docs/archive/` is history, not instructions — see its README before following anything in there.
