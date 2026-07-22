# Runbook — instalare & disaster recovery (SimpleAd Manager)

Cum se reconstruiește platforma de la zero (mașină nouă) și cum se recuperează după un
dezastru (host pierdut / DB coruptă). Producția: `manager.simplead.ro` pe **dasher**
(46.225.98.92), stack Docker Compose (`docker-compose.prod.yml`).

> **`.env.example`** — șablonul complet de variabile e la rădăcina repo-ului
> (`/.env.example`). Copiază-l în `.env` și completează valorile marcate REQUIRED.
> Toate variabilele nelistate acolo au default-uri sigure în `config/*.php`.

---

## 1. Instalare de la zero (host nou)

**Precondiții host:** Docker + Docker Compose v2, git, acces la registry-ul de imagini (sau build local).

```bash
# 1. Cod
git clone <repo-url> /var/www/simplead-manager
cd /var/www/simplead-manager

# 2. Config
cp .env.example .env
#    → editează .env: APP_KEY, DB_*, REDIS_*, MAIL_*, BACKUP_ENCRYPTION_KEY,
#      și cheile serviciilor externe folosite (Google, Anthropic, S3/Dropbox…).
#    APP_KEY:                 docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate
#    BACKUP_ENCRYPTION_KEY:   php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"

# 3. Build + pornire
DOCKER_BUILDKIT=1 docker compose -f docker-compose.prod.yml build app nginx
docker compose -f docker-compose.prod.yml up -d --force-recreate --remove-orphans \
  pgsql pgbouncer redis app horizon scheduler gotenberg

# 4. Așteaptă healthy, apoi migrează (pe conexiunea directă — vezi §4)
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# 5. nginx (TLS via certbot montat din /etc/letsencrypt)
docker compose -f docker-compose.prod.yml up -d --force-recreate nginx
```

**Verificare:** `https://manager.simplead.ro` întoarce 200; `docker compose -f docker-compose.prod.yml ps`
arată toate containerele `healthy`; `php artisan horizon:status` = running.

**Cheia obligatorie irecuperabilă:** `BACKUP_ENCRYPTION_KEY`. Fără ea, **backup-urile criptate
existente nu se mai pot restaura**. Păstreaz-o în afara host-ului (password manager al agenției).
Nu o roti fără a re-cripta backup-urile.

## 2. Deploy (host existent)

Vezi `CLAUDE.md` → „deploy" și `deploy.sh` (git pull → build → migrate --force pe pgsql_direct →
queue:restart → up nginx). Gate-ul CI din `deploy.sh` refuză deploy-ul dacă commit-ul livrat nu are
Pint/PHPStan/PHPUnit verzi (override de urgență: `DEPLOY_SKIP_CI_CHECK=1`).

## 3. Disaster recovery

### 3a. Aplicația (host pierdut, DB intactă pe volum/backup)
1. Refă pașii §1.1–§1.3 pe host nou, cu **același `.env`** (mai ales `APP_KEY` și `BACKUP_ENCRYPTION_KEY`).
2. Restaurează volumul Postgres SAU importă cel mai recent dump (§3b) ÎNAINTE de `migrate`.
3. `migrate --force` (aplică doar migrările lipsă), apoi pornește nginx.

### 3b. Baza de date (restore din dump)
```bash
# Dump (rutină / înainte de operații riscante)
docker compose -f docker-compose.prod.yml exec pgsql \
  pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > backup-$(date +%F).dump

# Restore — ÎNTOTDEAUNA pe conexiunea DIRECTĂ (port 5432 pgsql), NU prin PgBouncer
docker compose -f docker-compose.prod.yml exec -T pgsql \
  pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --clean --if-exists < backup-YYYY-MM-DD.dump
```

### 3c. Re-conectarea flotei
- Conectorul WP se re-sincronizează singur la următorul `SyncWordPressSite`.
- Dacă versiunea conectorului trebuie împinsă: `php artisan connector:update --all`.
- Cheile HMAC per-site trăiesc în DB (`sites.api_key`/`api_secret`, criptate) — revin cu restore-ul DB.

## 4. PgBouncer & migrări (capcană critică)

PgBouncer rulează în **transaction pooling**, ceea ce rupe protocolul de prepared-statements al
Laravel pe DDL multi-statement. De aceea **migrările rulează pe conexiunea `pgsql_direct`**
(`DB_DIRECT_HOST`/`DB_DIRECT_PORT` → direct la Postgres, port 5432), configurată în
`config/database.php` și injectată de `docker-compose.prod.yml`.

**Regulă de deploy:** după orice migrare cu DDL (`ALTER/CREATE/DROP TABLE`), **repornește PgBouncer**
ca să nu servească din pool conexiuni cu schema veche:
```bash
docker compose -f docker-compose.prod.yml restart pgbouncer
```

## 5. Containere & roluri

| Container | Rol |
|---|---|
| `app` | PHP-FPM (read-only rootfs, storage pe volum) |
| `horizon` | cozile (backup/restore/sync/notificări/uptime/audit) |
| `scheduler` | `schedule:work` (cron Laravel) |
| `nginx` | TLS + reverse proxy către app |
| `pgsql` | PostgreSQL (sursa de adevăr) |
| `pgbouncer` | pooler (app → aici; migrări → direct la pgsql) |
| `redis` | cache / cozi / sesiuni / lock-uri |
| `gotenberg` | randare PDF rapoarte |
| `certbot` | reînnoire certificate Let's Encrypt |

## 6. Verificări post-recovery

- [ ] `https://manager.simplead.ro` → 200, login funcțional
- [ ] `docker compose -f docker-compose.prod.yml ps` → toate `healthy`
- [ ] `php artisan horizon:status` → running; cozile se golesc
- [ ] Un site din flotă se sincronizează (Sync) fără eroare de semnătură HMAC
- [ ] Un raport PDF se generează (validează Gotenberg)
- [ ] Un backup de test se creează ȘI se restaurează (validează `BACKUP_ENCRYPTION_KEY`)
