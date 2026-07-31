# Runbook — instalare & disaster recovery (SimpleAd Manager)

Cum se reconstruiește platforma de la zero și cum se recuperează după un dezastru
(host pierdut / DB coruptă). Producția: `manager.simplead.ro`, gestionată de **Coolify**
(app `managersimpleadro`, resource UUID `y12jcr1kywwdseq7i1cevtjv`, `coolify.applicationId=6`),
deployată din `main` prin `docker-compose.coolify.yml`.

> **`.env.example`** — șablonul complet de variabile e la rădăcina repo-ului
> (`/.env.example`). În Coolify variabilele NU stau într-un fișier din repo: se
> configurează în UI (Environment Variables), Coolify le ține în DB-ul propriu și
> scrie un `.env` în directorul de artefacte la fiecare deploy.
> Toate variabilele nelistate în `.env.example` au default-uri sigure în `config/*.php`.

---

## 1. Instalare de la zero (host nou)

**Precondiții host:** Docker + Docker Compose v2, git, o instanță Coolify.

1. **Creează resursele managed în Coolify**, înainte de aplicație:
   - PostgreSQL 16 (devine `DB_HOST` / `DB_DIRECT_HOST`)
   - Redis 7
2. **Creează aplicația** de tip *Docker Compose*, sursă = repo-ul, branch `main`,
   compose file = `docker-compose.coolify.yml`. Domeniul se setează pe serviciul
   `manager-nginx` (Traefik termină TLS — nu e nevoie de certbot).
3. **Completează variabilele de mediu** în UI-ul Coolify după `.env.example`.
   Obligatorii fără default: `APP_KEY`, `DB_*`, `REDIS_*`, `MAIL_*`,
   `BACKUP_ENCRYPTION_KEY`, plus cheile serviciilor externe folosite
   (Google, Anthropic, S3/Dropbox…). Setează `MANAGER_WORKERS_ENABLED=true`
   doar când vrei ca Horizon și scheduler-ul să înceapă efectiv să lucreze.
   ```bash
   # generare chei
   APP_KEY:               php artisan key:generate --show
   BACKUP_ENCRYPTION_KEY: php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
   ```
4. **Declanșează deploy-ul** (Coolify nu auto-deployează pe push):
   ```bash
   TOKEN=$(cat /opt/apps/simplead-manager/.coolify-deploy-token)
   curl -H "Authorization: Bearer $TOKEN" \
     "https://server.simplead.ro/api/v1/deploy?uuid=<APP_UUID>&force=false"
   ```
5. **Rulează migrările MANUAL** — Coolify nu le rulează (vezi §4):
   ```bash
   APP=$(docker ps --filter name=manager-app --format '{{.Names}}')
   docker exec -i "$APP" php artisan migrate --force --database=pgsql_direct
   ```

**Verificare:** `https://manager.simplead.ro` întoarce 200;
`docker ps --filter 'label=coolify.applicationId=6'` arată toate containerele `healthy`;
`docker exec <horizon> php artisan horizon:status` = running.

**Cheia obligatorie irecuperabilă:** `BACKUP_ENCRYPTION_KEY`. Fără ea, **backup-urile criptate
existente nu se mai pot restaura**. Păstreaz-o în afara host-ului (password manager al agenției).
Nu o roti fără a re-cripta backup-urile.

## 2. Deploy (host existent)

Vezi `CLAUDE.md` → **Deployment procedure**. Pe scurt: merge `--ff-only` în `main`, push,
apoi `curl` pe webhook-ul de deploy Coolify, apoi migrările manual. Nu există gate CI în
procedura de deploy — CI-ul (`.github/workflows/ci.yml`) rulează pe PR, înainte de merge.

## 3. Disaster recovery

### 3a. Aplicația (host pierdut, DB intactă pe volum/backup)
1. Refă pașii §1.1–§1.4 pe host nou, cu **aceleași variabile** (mai ales `APP_KEY` și
   `BACKUP_ENCRYPTION_KEY`).
2. Restaurează volumul Postgres SAU importă cel mai recent dump (§3b) ÎNAINTE de `migrate`.
3. `migrate --force --database=pgsql_direct` (aplică doar migrările lipsă).
4. Restaurează volumul de storage al aplicației (`…_app-storage`) — conține fișierele
   generate (rapoarte, screenshot-uri, artefacte de backup).

### 3b. Baza de date (restore din dump)
```bash
PG=$(docker ps --filter name=postgres --filter label=coolify.managed=true --format '{{.Names}}' | head -1)

# Dump (rutină / înainte de operații riscante)
docker exec "$PG" pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > backup-$(date +%F).dump

# Restore — ÎNTOTDEAUNA direct pe Postgres (5432), NU prin PgBouncer
docker exec -i "$PG" pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" \
  --clean --if-exists < backup-YYYY-MM-DD.dump
```

### 3c. Re-conectarea flotei
- Conectorul WP se re-sincronizează singur la următorul `SyncWordPressSite`.
- Dacă versiunea conectorului trebuie împinsă: `php artisan connector:update --all`.
- Cheile HMAC per-site trăiesc în DB (`sites.api_key`/`api_secret`, criptate) — revin cu restore-ul DB.

## 4. PgBouncer & migrări (capcană critică)

PgBouncer rulează în **transaction pooling**, ceea ce rupe protocolul de prepared-statements al
Laravel pe DDL multi-statement. De aceea **migrările rulează pe conexiunea `pgsql_direct`**
(`DB_DIRECT_HOST`/`DB_DIRECT_PORT` → direct la Postgres, port 5432), configurată în
`config/database.php` și injectată prin variabilele Coolify.

**Regulă de deploy:** după orice migrare cu DDL (`ALTER/CREATE/DROP TABLE`), **repornește PgBouncer**
ca să nu servească din pool conexiuni cu schema veche (altfel: 500-uri intermitente cu
`cached plan must not change result type`):
```bash
docker restart $(docker ps --filter name=manager-pgbouncer --format '{{.Names}}')
```

## 5. Containere & roluri

Stack aplicativ — `docker-compose.coolify.yml`:

| Container | Rol |
|---|---|
| `manager-app` | PHP-FPM (rootfs read-only, storage pe volum) |
| `manager-horizon` | cozile (backup/restore/sync/notificări/uptime/audit) |
| `manager-scheduler` | `schedule:work` (cron Laravel) |
| `manager-nginx` | reverse proxy HTTP către app (TLS e la Traefik) |
| `manager-pgbouncer` | pooler (app → aici; migrări → direct la Postgres) |
| `manager-gotenberg` | randare PDF rapoarte |

Resurse managed Coolify, în afara compose-ului: **PostgreSQL 16** (sursa de adevăr) și
**Redis 7** (cache / cozi / sesiuni / lock-uri). TLS + rutarea: **Traefik** (`coolify-proxy`).

Opțional, pornit manual: `docker-compose.sandbox.yml` (`simplead-sandbox-wp` + `-db`) —
ținta de restore pentru „proven restore" (C-08). Nu face parte din producție.

## 6. Verificări post-recovery

- [ ] `https://manager.simplead.ro` → 200, login funcțional
- [ ] `docker ps --filter 'label=coolify.applicationId=6'` → toate `healthy`
- [ ] `php artisan horizon:status` → running; cozile se golesc
- [ ] Un site din flotă se sincronizează (Sync) fără eroare de semnătură HMAC
- [ ] Un raport PDF se generează (validează Gotenberg)
- [ ] Un backup de test se creează ȘI se restaurează (validează `BACKUP_ENCRYPTION_KEY`)
