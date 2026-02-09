# Development Scripts

Helper scripts for SimpleAD Manager development workflow.

## Available Scripts

### `dev-up.sh`

Start the development environment.

```bash
./scripts/dev-up.sh
```

- Builds images if they don't exist
- Starts all services in the background
- Shows helpful information about running services

### `dev-down.sh`

Stop the development environment.

```bash
./scripts/dev-down.sh
```

- Stops all running containers
- Preserves data volumes (database, storage)

### `dev-rebuild.sh`

Rebuild Docker images from scratch.

```bash
./scripts/dev-rebuild.sh
```

Use when:
- Dockerfile changes
- PHP extensions added/removed
- System dependencies modified
- Build process changes

### `dev-fresh.sh`

⚠️ **Warning: Destructive!**

Fresh start with database reset.

```bash
./scripts/dev-fresh.sh
```

- Rebuilds images
- Resets database (runs `migrate:fresh --seed`)
- Clears all caches
- Asks for confirmation before proceeding

### `dev-logs.sh`

View logs for a specific service.

```bash
./scripts/dev-logs.sh [service]
```

Examples:
```bash
./scripts/dev-logs.sh app       # App logs only
./scripts/dev-logs.sh horizon   # Horizon logs only
./scripts/dev-logs.sh scheduler # Scheduler logs only
./scripts/dev-logs.sh all       # All logs
```

Default (no argument): Shows app logs

### `dev-shell.sh`

Access shell in a container.

```bash
./scripts/dev-shell.sh [service]
```

Examples:
```bash
./scripts/dev-shell.sh app      # App container shell
./scripts/dev-shell.sh pgsql    # Database shell
./scripts/dev-shell.sh redis    # Redis shell
```

Default (no argument): Opens app container shell

## Common Workflows

### Starting Work

```bash
./scripts/dev-up.sh
./scripts/dev-logs.sh app
```

### Making Changes

1. Edit files in your editor
2. Refresh browser (changes appear immediately)
3. If needed: `docker-compose exec app php artisan view:clear`

### Database Changes

```bash
# Create migration
docker-compose exec app php artisan make:migration create_something_table

# Run migration
docker-compose exec app php artisan migrate

# Reset database (development only!)
./scripts/dev-fresh.sh
```

### Debugging Issues

```bash
# View logs
./scripts/dev-logs.sh app

# Access shell
./scripts/dev-shell.sh app

# Check running processes
docker-compose ps

# Restart services
docker-compose restart app horizon scheduler
```

### Ending Work

```bash
./scripts/dev-down.sh
```

## Manual Commands

If you prefer not to use scripts, equivalent manual commands:

```bash
# Start
docker-compose up -d

# Stop
docker-compose down

# Rebuild
docker-compose build --no-cache && docker-compose up -d

# Logs
docker-compose logs -f app

# Shell
docker-compose exec app sh
```

## Troubleshooting

### Scripts Not Executable

```bash
chmod +x scripts/*.sh
```

### Permission Denied

Run from project root:
```bash
cd /var/www/simplead-manager
./scripts/dev-up.sh
```

### Docker Not Running

```bash
# Check Docker status
docker ps

# Start Docker (systemd)
sudo systemctl start docker
```

## See Also

- [DEVELOPMENT.md](../DEVELOPMENT.md) - Full development guide
- [docker-compose.yml](../docker-compose.yml) - Development compose file
- [docker-compose.prod.yml](../docker-compose.prod.yml) - Production compose file
