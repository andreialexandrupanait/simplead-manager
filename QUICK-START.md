# Quick Start: Development Environment

Get up and running with the SimpleAD Manager development environment in 5 minutes.

## Prerequisites

- Docker Engine 20.10+
- Docker Compose v2.0+

## 1. Start Development Environment

```bash
./scripts/dev-up.sh
```

**First time:** Builds images (2-5 minutes)
**Subsequent starts:** Instant (< 10 seconds)

## 2. Access Application

Open browser: http://localhost

## 3. Make Code Changes

Edit any file in your editor:

```bash
vim app/Livewire/Sites/SitesList.php
```

**Refresh browser** → Changes appear immediately!

## 4. Common Commands

```bash
# View logs
./scripts/dev-logs.sh app

# Run artisan
docker compose exec app php artisan migrate

# Access shell
./scripts/dev-shell.sh app

# Stop environment
./scripts/dev-down.sh
```

## 5. Clear Caches (if needed)

```bash
docker compose exec app php artisan view:clear
docker compose exec app php artisan config:clear
```

## That's It!

You're ready to develop. Edit files, refresh browser, repeat.

## Need Help?

- **Full guide:** [DEVELOPMENT.md](DEVELOPMENT.md)
- **Migration:** [MIGRATION-TO-DEV.md](MIGRATION-TO-DEV.md)
- **Architecture:** [DOCKER-SETUP.md](DOCKER-SETUP.md)
- **Scripts:** [scripts/README.md](scripts/README.md)

## Troubleshooting

**Changes not appearing?**
```bash
docker compose exec app php artisan view:clear
```

**Port 80 in use?**
```bash
sudo systemctl stop nginx
docker compose up -d
```

**Permission errors?**
```bash
docker compose exec -u root app chown -R appuser:appuser storage bootstrap/cache
```

---

Happy coding! 🚀
