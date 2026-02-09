# Docker Setup Guide

SimpleAD Manager supports two Docker environments: **Development** and **Production**.

## Quick Reference

| Environment | File | Use Case | Code Sync |
|-------------|------|----------|-----------|
| **Development** | `docker-compose.yml` | Local development, fast iteration | ✅ Live sync |
| **Production** | `docker-compose.prod.yml` | Production deployment, immutable infrastructure | ❌ Baked into image |

## Development Setup

### How It Works

Development mode uses **bind mounts** to sync your host codebase directly into containers:

```yaml
volumes:
  - .:/var/www/html              # Mount host code
  - /var/www/html/vendor         # Exclude vendor (Docker-managed)
  - /var/www/html/node_modules   # Exclude node_modules (Docker-managed)
  - app-storage:/var/www/html/storage  # Persistent storage
```

**What This Means:**
- ✅ Edit files on host → Changes appear **immediately** in containers
- ✅ No rebuild required for code changes
- ✅ Fast iteration cycle (seconds, not minutes)
- ✅ Standard Docker development workflow
- ❌ Slightly slower file I/O than production (negligible on Linux)

### Starting Development

```bash
# Option 1: Using helper script (recommended)
./scripts/dev-up.sh

# Option 2: Manual docker compose
docker compose up -d

# View logs
docker compose logs -f app
```

### Making Changes

1. **Edit any file** in your editor (VS Code, PhpStorm, etc.)
2. **Refresh browser** - changes appear immediately
3. **Clear caches** if needed:
   ```bash
   docker compose exec app php artisan view:clear
   docker compose exec app php artisan config:clear
   ```

### When to Rebuild

Only rebuild when **Dockerfile or dependencies change**:

```bash
# Dockerfile changed
./scripts/dev-rebuild.sh

# Or manually
docker compose build --no-cache
docker compose up -d
```

You do **NOT** need to rebuild for:
- ✅ PHP file changes (`.php`)
- ✅ Blade template changes (`.blade.php`)
- ✅ JavaScript/CSS changes (`.js`, `.css`)
- ✅ Configuration changes (`.env`, config files)
- ✅ View/route/cache changes

You **DO** need to rebuild for:
- ❌ New PHP extensions (`docker/php/Dockerfile.prod`)
- ❌ System dependencies (`apk add ...`)
- ❌ Composer packages (or run `docker compose exec app composer install`)
- ❌ NPM packages (or run `docker compose exec app npm install`)

### Stopping Development

```bash
# Option 1: Using helper script
./scripts/dev-down.sh

# Option 2: Manual docker compose
docker compose down
```

**Note**: This stops containers but **preserves data volumes** (database, storage).

## Production Setup

### How It Works

Production mode uses **immutable images** where code is baked in at build time:

```yaml
# No bind mount - code is copied into image during build
volumes:
  - app-storage:/var/www/html/storage  # Only storage is mounted
```

**What This Means:**
- ✅ Immutable infrastructure (code can't be modified at runtime)
- ✅ Better security (containers run read-only code)
- ✅ Faster file I/O (no bind mount overhead)
- ✅ Standard Docker production best practice
- ❌ Must rebuild and restart to see code changes

### Building Production Images

```bash
docker compose -f docker-compose.prod.yml build
```

This:
1. Copies code into image (`COPY . /var/www/html`)
2. Installs dependencies (`composer install --no-dev`)
3. Sets permissions
4. Creates immutable image

### Starting Production

```bash
docker compose -f docker-compose.prod.yml up -d
```

### Making Changes in Production

```bash
# 1. Make code changes on host
vim app/Livewire/Sites/SitesList.php

# 2. Rebuild images (code gets baked in)
docker compose -f docker-compose.prod.yml build

# 3. Restart containers with new images
docker compose -f docker-compose.prod.yml up -d

# 4. Verify changes
docker compose -f docker-compose.prod.yml logs -f app
```

**This is slow!** That's why we have development mode.

## Key Differences

### Volume Mounts

**Development:**
```yaml
app:
  volumes:
    - .:/var/www/html                    # ← Entire codebase
    - /var/www/html/vendor               # ← Exclude Docker-managed
    - /var/www/html/node_modules         # ← Exclude Docker-managed
    - app-storage:/var/www/html/storage
```

**Production:**
```yaml
app:
  volumes:
    - app-storage:/var/www/html/storage  # ← Only storage
    # No code mount - baked into image
```

### Container Names

**Development:**
- `simplead-app-dev`
- `simplead-horizon-dev`
- `simplead-scheduler-dev`
- `simplead-nginx-dev`
- `simplead-pgsql-dev`
- `simplead-redis-dev`
- `simplead-certbot-dev`

**Production:**
- `simplead-app`
- `simplead-horizon`
- `simplead-scheduler`
- `simplead-nginx`
- `simplead-pgsql`
- `simplead-redis`
- `simplead-certbot`

### Resource Limits

**Development:**
```yaml
# No resource limits - use as much as needed
```

**Production:**
```yaml
deploy:
  resources:
    limits:
      memory: 512M
      cpus: "1.0"
```

### Image Names

**Development:**
```yaml
image: simplead-app:dev
```

**Production:**
```yaml
image: simplead-app:latest
```

## Running Both Simultaneously

You can run both environments side-by-side (useful for testing):

### Option 1: Different Ports

Edit `docker-compose.yml` (development):
```yaml
nginx:
  ports:
    - "8080:80"   # Dev on 8080
    - "8443:443"  # Dev on 8443
```

Keep `docker-compose.prod.yml` (production) on default ports:
```yaml
nginx:
  ports:
    - "80:80"     # Prod on 80
    - "443:443"   # Prod on 443
```

Then:
```bash
# Start production on 80/443
docker compose -f docker-compose.prod.yml up -d

# Start development on 8080/8443
docker compose up -d
```

### Option 2: Different Servers

Run each environment on a separate server (recommended for production).

## File Ownership

Both environments use the same user:

```dockerfile
RUN addgroup -g 1000 -S appuser \
    && adduser -u 1000 -S appuser -G appuser
```

**UID/GID: 1000**

Ensure your host user matches (check with `id -u`):
```bash
# Check your UID
id -u
# Should output: 1000
```

If different, you may encounter permission issues. Fix:
```bash
# Fix storage permissions
docker compose exec -u root app chown -R appuser:appuser storage bootstrap/cache
```

## Data Persistence

Both environments use the **same external volumes** (shared data):

```yaml
volumes:
  simplead-pgsql:
    external: true
    name: simplead-manager_pgsql_data
  simplead-redis:
    external: true
    name: simplead-manager_redis_data
  simplead-certbot:
    external: true
    name: simplead-manager_certbot_data
```

**What This Means:**
- ✅ Switching between dev/prod preserves database
- ✅ No data loss when switching environments
- ⚠️ Be careful! Dev migrations can affect prod database if using same volumes
- 💡 Tip: Use different database volumes for dev/prod if on same server

## Common Workflows

### Daily Development

```bash
# Start
./scripts/dev-up.sh

# Code, code, code...
# (edit files, refresh browser, repeat)

# Stop
./scripts/dev-down.sh
```

### Deploy to Production

```bash
# Build new images
docker compose -f docker-compose.prod.yml build

# Stop old containers
docker compose -f docker-compose.prod.yml down

# Start new containers
docker compose -f docker-compose.prod.yml up -d

# Run migrations (if needed)
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# Check logs
docker compose -f docker-compose.prod.yml logs -f app
```

### Switch from Prod to Dev

```bash
# Stop production
docker compose -f docker-compose.prod.yml down

# Start development
docker compose up -d
```

### Switch from Dev to Prod

```bash
# Stop development
docker compose down

# Start production
docker compose -f docker-compose.prod.yml up -d
```

## Troubleshooting

### "Changes Not Appearing" (Development)

This is usually a **cache issue**, not a volume mount issue.

**Solution:**
```bash
docker compose exec app php artisan view:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear
```

**Verify volume mount is working:**
```bash
# Edit a file on host
echo "// Test" >> app/Http/Controllers/Controller.php

# Check it appears in container
docker compose exec app cat app/Http/Controllers/Controller.php | tail -1
# Should show: // Test

# Clean up
git checkout app/Http/Controllers/Controller.php
```

### "Vendor Directory Conflicts"

**Problem:** Host's `vendor` directory different from container's.

**Solution:** The anonymous volume pattern handles this:
```yaml
volumes:
  - .:/var/www/html        # Host code
  - /var/www/html/vendor   # Container's vendor takes precedence
```

If issues persist:
```bash
# Delete host vendor
rm -rf vendor

# Container's vendor is preserved
docker compose exec app ls -la vendor
```

### "Permission Denied" Errors

**Problem:** File ownership mismatch.

**Solution:**
```bash
# Fix permissions (run as root)
docker compose exec -u root app chown -R appuser:appuser storage bootstrap/cache
docker compose exec -u root app chmod -R 775 storage bootstrap/cache
```

### "Port Already in Use"

**Problem:** Another service using port 80/443.

**Solution:**
```bash
# Option 1: Stop conflicting service
sudo systemctl stop nginx

# Option 2: Change ports in docker-compose.yml
# Edit nginx service:
ports:
  - "8080:80"
  - "8443:443"
```

## Best Practices

### Development

1. ✅ Use development mode for active coding
2. ✅ Keep codebase on fast storage (SSD, not NFS)
3. ✅ Clear Laravel caches after changing config
4. ✅ Use helper scripts for common tasks
5. ❌ Don't commit `vendor` or `node_modules` to git
6. ❌ Don't run development in production

### Production

1. ✅ Use production mode for deployments
2. ✅ Enable resource limits
3. ✅ Use immutable images (no bind mounts)
4. ✅ Test builds before deploying
5. ✅ Run migrations carefully (`--force` flag)
6. ❌ Don't edit files in running containers
7. ❌ Don't use `docker exec` to modify code

### Both

1. ✅ Use external volumes for data persistence
2. ✅ Backup database regularly
3. ✅ Keep `.env` secure and out of git
4. ✅ Review logs regularly
5. ✅ Monitor resource usage

## Summary

**Use Development Mode (`docker-compose.yml`) when:**
- 👨‍💻 Actively developing features
- 🐛 Debugging issues
- ⚡ Need fast iteration
- 🔧 Testing changes locally

**Use Production Mode (`docker-compose.prod.yml`) when:**
- 🚀 Deploying to servers
- 🔒 Security is critical
- ⚡ Performance is critical
- 📦 Want immutable infrastructure

Both modes are valid and serve different purposes. Choose the right tool for the job!

## Resources

- [DEVELOPMENT.md](DEVELOPMENT.md) - Full development guide
- [scripts/README.md](scripts/README.md) - Helper scripts documentation
- [Docker Documentation](https://docs.docker.com/)
- [Laravel Deployment](https://laravel.com/docs/deployment)
