# Architecture Overview

## System Design

SimpleAd Manager is a Laravel 11 application that remotely manages multiple WordPress sites through a connector plugin installed on each site.

```
┌─────────────────────────────────────────────┐
│           SimpleAd Manager (Laravel)         │
│  ┌──────┐ ┌─────────┐ ┌──────────┐         │
│  │ Nginx│→│ App/PHP │→│ Horizon  │         │
│  └──────┘ └────┬────┘ └────┬─────┘         │
│                │            │                │
│           ┌────┴────┐ ┌────┴─────┐          │
│           │PgBouncer│ │  Redis   │          │
│           └────┬────┘ └──────────┘          │
│           ┌────┴────┐                        │
│           │PostgreSQL│                       │
│           └─────────┘                        │
└────────────────┬────────────────────────────┘
                 │ REST API (signed URLs, API keys)
     ┌───────────┼───────────┐
     ▼           ▼           ▼
┌─────────┐ ┌─────────┐ ┌─────────┐
│  WP #1  │ │  WP #2  │ │  WP #N  │
│Connector│ │Connector│ │Connector│
└─────────┘ └─────────┘ └─────────┘
```

## Docker Services (Production)

| Service    | Purpose                        |
|------------|--------------------------------|
| app        | Laravel PHP-FPM application    |
| horizon    | Queue worker (Laravel Horizon) |
| scheduler  | Cron / task scheduling         |
| nginx      | Web server / reverse proxy     |
| pgsql      | PostgreSQL database            |
| pgbouncer  | Connection pooling for PG      |
| redis      | Cache, queues, sessions        |

## Key Directories

| Directory              | Purpose                              |
|------------------------|--------------------------------------|
| `app/Livewire/`        | Livewire UI components               |
| `app/Services/`        | Business logic services              |
| `app/Jobs/`            | Async queue jobs                     |
| `app/Models/`          | Eloquent models                      |
| `app/Enums/`           | PHP enums                            |
| `app/DTOs/`            | Data transfer objects                |
| `app/Dispatchers/`     | Job dispatchers                      |
| `wordpress-plugin/`    | WP connector plugin source           |
| `docker/`              | Dockerfiles and config               |
| `scripts/`             | Deployment and utility scripts       |

## Communication Flow

1. **Manager → WP Site**: REST API calls with API key auth (`X-SAM-API-Key` header)
2. **WP Site → Manager**: Signed URL downloads for plugin updates
3. **Async Operations**: Jobs dispatched to Redis queues, processed by Horizon
4. **Cloudflare**: All traffic proxied — direct loopback requests get 403

## Data Model (Core)

- **Site**: WordPress site (`url`, connection config, status)
- **Backup**: Backup records (status, stage, progress, storage path)
- **Client**: Client/owner grouping for sites
- **MaintenancePlan**: Scheduled maintenance configurations
