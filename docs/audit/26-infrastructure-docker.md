# 26 — Infrastructura Docker și a host-ului

**Data:** 2026-07-02 · **Auditor:** Claude (audit infrastructură) · **Scope:** `docker-compose.prod.yml`, `docker/`, `deploy.sh`, configurații runtime host (verificate read-only), backup-ul bazei de date a managerului, TLS/certbot, log rotation.

**Metodă:** citirea fișierelor din working tree + verificări read-only pe host-ul de producție (`docker ps`, `ss -tln`, `docker inspect`, `docker exec … cat/ls/grep`, interogare TLS live). Fișierele `.env` / `.env.example` **nu au putut fi citite** (acces blocat de permisiunile sesiunii de audit) — valorile din ele sunt marcate „neverificat"; unde a fost posibil, consecința a fost verificată la runtime (ex. Redis răspunde `NOAUTH`).

---

## Rezumat executiv

Remedierea incidentului istoric (PostgreSQL expus public printr-un port mapping care ocolea ufw) este **completă și verificată la runtime**: singurul serviciu cu `ports:` este nginx (80/443), iar pe host ascultă doar 22/80/443. Postura de bază e peste medie: rootfs read-only pe containerele PHP, `no-new-privileges` aproape peste tot, limite de memorie/CPU aplicate efectiv, scram-sha-256 la Postgres și PgBouncer, Redis cu parolă (verificat `NOAUTH` la ping neautentificat), dump DB zilnic criptat cu retenție funcțională.

Problemele serioase sunt operaționale, nu de expunere:

1. **Deploy-ul ucide joburi in-flight** — `deploy.sh` face `docker rm -f` pe Horizon la ~5s după `horizon:terminate`, iar `stop_grace_period: 300s` e oricum sub timeout-ul joburilor de backup/restore (3600s). Un deploy în timpul unui restore poate lăsa un site de client pe jumătate restaurat.
2. **Backupul aplicației manager este stricat în cod** — `AppBackupCreator::create()` citește `$config->retention_type` dintr-o variabilă nedefinită; orice app-backup ajuns la finalizare aruncă excepție și e marcat `failed`. În consecință, dump-urile zilnice ale DB-ului managerului trăiesc **doar pe același host** cu baza de date — un incident de disc/VPS pierde tot.
3. **`db:dump` raportează succes chiar dacă `pg_dump` eșuează** (exit code-ul pipeline-ului este al lui `gzip`), iar retenția `--keep 7` ar șterge dump-urile bune în 7 zile de eșec silențios. Dump-urile nu sunt niciodată testate la restaurare.
4. **PgBouncer nu este repornit după migrații DDL** — incident real documentat (2026-03-28, erori 500 „cached plan must not change result type"); nici `deploy.sh`, nici pașii din CLAUDE.md nu conțin restart-ul.
5. **Bugetele de memorie Horizon depășesc limita containerului** — 3 workeri de backup × prag 1024 MB într-un container limitat la 1024 MB → OOM-kill (SIGKILL) în mijlocul joburilor.

Nicio constatare P0 activă: expunerea publică istorică e închisă, iar restul defectelor cer o coincidență operațională (deploy/eșec/host-failure) ca să producă pagubă.

---

## Port mappings (docker-compose.prod.yml)

**Declarat în compose:** singurul serviciu cu `ports:` este nginx:

```yaml
  nginx:
    ...
    ports:
      - "80:80"
      - "443:443"
```
(`docker-compose.prod.yml:132-134`) — fără IP explicit, deci `0.0.0.0` + `[::]`, ceea ce este corect pentru nginx.

**Verificat că NU au `ports:`:** `pgsql` (`docker-compose.prod.yml:187-235`), `pgbouncer` (`:239-275`), `redis` (`:277-304`), `gotenberg` (`:306-329`), `certbot` (`:170-185`), `app`/`horizon`/`scheduler` (doar `expose` implicit 9000 din imagine).

**Verificat la runtime (2026-07-02):**
- `docker ps` — doar `simplead-nginx` are `0.0.0.0:80->80/tcp, [::]:80->80/tcp, 0.0.0.0:443->443/tcp, [::]:443->443/tcp`; pgsql/pgbouncer/redis/gotenberg apar cu porturi interne neexpuse (`5432/tcp`, `6379/tcp`, `3000/tcp` fără mapare).
- `ss -tln` pe host — ascultă public doar `:22`, `:80`, `:443` (plus servicii loopback: resolver systemd pe 127.0.0.53/54 și câteva porturi 127.0.0.1 efemere).
- Nu există `docker-compose.override.yml` sau alt fișier compose în repo (`ls` rădăcină: doar `docker-compose.prod.yml`); `.dockerignore` exclude `docker-compose*.yml` din imagine.

**Concluzie: remedierea expunerii PostgreSQL este completă.** `ufw status` nu a putut fi rulat (necesită sudo — neverificat), dar este irelevant pentru suprafața Docker: cu zero `ports:` pe serviciile de date, nu există reguli DNAT care să ocolească firewall-ul. Recomandare defensivă (schiță, neaplicată): dacă vreodată e nevoie de acces local la DB, folosiți `127.0.0.1:5432:5432`, niciodată maparea goală.

---

## Segmentare rețea

O singură rețea bridge:

```yaml
networks:
  simplead:
    driver: bridge
```
(`docker-compose.prod.yml:331-333`) — **toate cele 9 containere** sunt în ea, inclusiv certbot (`:179-180`), care nu are nevoie de nicio comunicare inter-container (folosește volumul webroot partajat).

Consecințe concrete:

- **nginx poate vorbi direct cu pgsql:5432, pgbouncer:5432 și redis:6379.** Contează: nginx este containerul cu expunere publică directă; un RCE în nginx (sau un container certbot/gotenberg compromis) are drum liber L3/L4 către stratul de date. Parola PG/Redis rămâne o barieră, dar apărarea în adâncime lipsește.
- **PHP-FPM ascultă pe toate interfețele containerului:** `listen = 0.0.0.0:9000` (`docker/php/php-fpm-pool.conf:5`). Orice container din rețea poate vorbi FastCGI cu `app:9000` și executa orice fișier PHP existent în container (FastCGI nu are autentificare). Nginx restricționează doar `/fpm-ping` la `172.16.0.0/12` (`docker/nginx/conf.d.ssl/app.conf:88-96`) — dar asta e la nivel HTTP, nu protejează socketul FastCGI.
- **gotenberg:3000 este apelabil fără autentificare de orice container** (vezi secțiunea Gotenberg).

Schiță de remediere (neaplicată): trei rețele — `frontend` (nginx+app), `backend` (app+horizon+scheduler+pgbouncer+pgsql+redis), `pdf` (app+horizon+gotenberg); certbot fără `networks:` (are nevoie doar de egress, pe care îl păstrează prin rețeaua implicită a serviciului dacă i se dă una dedicată fără alte containere). pgsql poate fi restrâns și mai mult: doar pgbouncer + scheduler (pentru `db:dump`) au nevoie de el.

Severitate: P2 — exploatabil doar post-compromis al unui container, dar host-ul rulează operații distructive pe site-uri live, deci pivotarea contează.

---

## Redis

**Configurare (fail-open):**

```yaml
    command: >
      sh -c '
      ARGS="--maxmemory 256mb --maxmemory-policy volatile-lru --appendonly yes --appendfsync everysec";
      if [ -n "$$REDIS_PASSWORD" ]; then ARGS="--requirepass $$REDIS_PASSWORD $$ARGS"; fi;
      exec redis-server $$ARGS
      '
```
(`docker-compose.prod.yml:281-286`). Dacă `REDIS_PASSWORD` este gol sau lipsește din `.env`, Redis pornește **fără autentificare** și totul funcționează normal — healthcheck-ul (`:290`) trece în ambele cazuri, deci nimeni nu observă. `.env.example` — neverificat (acces blocat); `config/database.php:161,170` nu are default pentru `REDIS_PASSWORD` (null → clientul nu trimite AUTH), deci aplicația s-ar conecta fericită la un Redis neprotejat.

**Verificat la runtime:** `docker exec simplead-redis redis-cli ping` → `NOAUTH Authentication required.` — **parola este setată în producția curentă**. Riscul este de regresie (un `.env` regenerat / o instanță nouă), nu unul activ.

**Cine poate ajunge la Redis:** doar containerele din rețeaua `simplead` (nginx, certbot, gotenberg, app, horizon, scheduler, pgsql, pgbouncer) — niciun acces din exterior (fără `ports:`). De reținut că Redis este aici **magistrala de execuție**: cozi Horizon + cache + sesiuni (`config/queue.php:16`, `config/cache.php:18`, `config/session.php:21`). Cine scrie în Redis poate injecta joburi serializate pe care Horizon le execută → echivalent RCE în containerul horizon, care are credențialele HMAC către toate site-urile WP ale clienților. De aceea fail-open-ul + rețeaua plată sunt o combinație de tratat serios.

Alte observații: `--maxmemory-policy volatile-lru` e alegerea corectă pentru cozi (nu evacuează chei fără TTL); `appendonly yes` pe volumul `simplead-redis` dă durabilitate cozilor. Healthcheck-ul pune parola în argv (`redis-cli -a $REDIS_PASSWORD`, `:290`) — vizibilă în `ps` din container (P3).

Schiță de remediere: fail-closed — `command: ["redis-server", "--requirepass", "${REDIS_PASSWORD:?REDIS_PASSWORD is required}", ...]` (interpolarea compose cu `:?` refuză pornirea fără parolă); parola prin `REDISCLI_AUTH` env în healthcheck.

---

## PostgreSQL & PgBouncer

**Autentificare — verificată complet:**
- `POSTGRES_INITDB_ARGS: "--auth-host=scram-sha-256 --auth-local=scram-sha-256"` (`docker-compose.prod.yml:195`) și `password_encryption=scram-sha-256` (`:218-219`). Cum volumul de date e extern și pre-existent (`:336-338`), argumentul initdb ar fi putut să nu se aplice — am verificat runtime: `pg_hba.conf` efectiv conține exclusiv `scram-sha-256` pe toate liniile, inclusiv `host all all all scram-sha-256`. Fără `trust`, fără `md5`. ✔
- PgBouncer: `AUTH_TYPE: scram-sha-256` (`:258`), confirmat în `/etc/pgbouncer/pgbouncer.ini` runtime (`auth_type = scram-sha-256`).

**Transaction pooling (`POOL_MODE: transaction`, `:248`) — implicații:**
- **Advisory locks / LISTEN-NOTIFY:** incompatibile cu transaction pooling (lock-ul de sesiune poate ateriza pe altă conexiune server). Am căutat în tot `app/`: **zero utilizări** de `pg_advisory_*`, `LISTEN`, `pg_notify`. Singurul lock DB este `lockForUpdate()` (`app/Jobs/ReplicateBackup.php:141`) — row-lock în interiorul unei tranzacții, sigur în pooling tranzacțional. `withoutOverlapping()`/`onOneServer()` din scheduler folosesc mutex pe cache-ul Redis, nu pe Postgres. ✔
- **Prepared statements:** `config/database.php:84-88` recunoaște problema în comentariu, dar conexiunea `pgsql` (`:89-103`) nu setează `options` (`PDO::ATTR_EMULATE_PREPARES`), deci pdo_pgsql folosește named prepared statements reale. Funcționează astăzi doar pentru că imaginea `edoburu/pgbouncer:latest` a adus **PgBouncer 1.25.1** (verificat runtime), care suportă prepared statements în transaction mode; `pgbouncer.ini` generat **nu** setează explicit `max_prepared_statements` (verificat — absent din fișier), deci se bazează pe default-ul versiunii. Tag-ul `latest` nepinat înseamnă că un `docker compose pull` poate schimba silențios acest comportament (P2, INF-12).
- **DDL prin PgBouncer:** migrațiile rulează prin `app` cu `DB_HOST=pgbouncer` (comentariul din compose `:237-238`; valoarea exactă din `.env` — neverificat, dar confirmat indirect de incidentul documentat). Memoria proiectului (`feedback_pgbouncer_deploy.md`) consemnează incidentul din 2026-03-28: după `ALTER TABLE uptime_monitors ADD COLUMN`, prepared statements cache-uite pe conexiunile server au produs 500-uri („cached plan must not change result type") până la restart PgBouncer. **Nici `deploy.sh` (nicio apariție a `pgbouncer` în script), nici pașii „deploy" din CLAUDE.md nu includ restartul** → incidentul se poate repeta la fiecare migrație pe tabele existente. P1 (INF-04).

Alte note: `max_connections=50` la PG + `DEFAULT_POOL_SIZE: 20` + `RESERVE_POOL_SIZE: 5` + conexiuni directe ocazionale (`db:dump` din scheduler merge tot prin `DB_HOST` configurat) — bugete coerente. `sslmode=prefer` pe rețea internă — acceptabil.

Schiță de remediere INF-04: în `deploy.sh`, după `migrate --force`, detectează dacă au rulat migrații (`php artisan migrate:status` diff sau simplu necondiționat) și rulează `docker compose -f docker-compose.prod.yml restart pgbouncer`; alternativ rulează migrațiile cu `DB_HOST=pgsql` (cum recomandă chiar comentariul din `config/database.php:87-88`) și tot repornește pgbouncer pentru cache-ul de prepared statements al aplicației.

---

## Read-only rootfs & privilegii

Verificat și în compose, și la runtime (`docker inspect`):

| Container | read_only | no-new-privileges | mem limit (aplicată) | cpus | cap_drop | pids-limit |
|---|---|---|---|---|---|---|
| app | ✔ (`:32`) | ✔ (`:30-31`) | 512M ✔ | 1.0 | — | — |
| horizon | ✔ (`:77`) | ✔ | 1024M ✔ | 1.0 | — | — |
| scheduler | ✔ (`:113`) | ✔ | 256M ✔ | 0.5 | — | — |
| nginx | ✘ | ✔ (`:162-163`) | 128M ✔ | 0.25 | — | — |
| certbot | ✘ | **✘** (lipsește, `:170-185`) | 64M ✔ | 0.1 | — | — |
| pgsql | ✘ | ✔ (`:229-230`) | 2048M ✔ | 1.0 | — | — |
| pgbouncer | ✘ | ✔ (`:269-270`) | 64M ✔ | 0.25 | — | — |
| redis | ✘ | ✔ (`:298-299`) | 320M ✔ | 0.5 | — | — |
| gotenberg | ✘ | ✔ (`:323-324`) | 512M ✔ | 1.0 | — | — |

Puncte bune, verificate:
- tmpfs corect dimensionate pe containerele read-only (`/tmp`, `/var/run`, `/var/www/html/bootstrap/cache` cu uid/gid 1000 — `:36-39`, `:78-81`, `:114-117`); horizon folosește `TMPDIR=/var/www/html/storage/app/temp` pentru fișiere >1GB (`:51-53`), evitând umplerea tmpfs.
- `deploy.resources.limits` chiar se aplică (confirmat `docker inspect`: ex. horizon `mem=1073741824`) — nu e cazul clasic „deploy e ignorat fără swarm".
- Imaginea rulează ca `appuser` uid 1000, non-root (`docker/php/Dockerfile.prod:75-76,149`); `.dockerignore` exclude `.env`/`.git` din imagine.

Probleme:
- **INF-05 (P1): bugetele Horizon nu încap în container.** `config/horizon.php`: `supervisor-backups` are `memory => 1024` per worker și `timeout => 3600` (`config/horizon.php:232-243`), cu `HORIZON_BACKUP_WORKERS` default 3 în producție (`:296-297`), plus supervisor-general 3×512, sync 3×256, incident-response 2×512 etc. Limita cgroup a containerului este exact 1024M (`docker-compose.prod.yml:82-86`). Pragul `memory` al Horizon e verificat de worker *după* terminarea jobului; până acolo, kernel-ul OOM-kill-uiește cu SIGKILL primul proces gras — un backup/restore în derulare moare fără retry curat și fără cleanup. Scenariu: 2 backup-uri mari concurente (fiecare legitim sub pragul de 1024M al Horizon) depășesc împreună 1024M → SIGKILL. Remediere: fie limita containerului ≥ suma realistă a workerilor (ex. 3-4 GB), fie `memory` per worker redus (ex. 256M) și `HORIZON_BACKUP_WORKERS=1-2`.
- **INF-01 (P1), parțial aici:** comentariul din compose contrazice valoarea: „Must exceed longest job timeout (backups: 2700s = 45min)" dar `stop_grace_period: 300s` (`docker-compose.prod.yml:63-65`) — iar timeout-ul real al cozii backups e 3600s (`config/horizon.php:242`). Vezi secțiunea Deploy.
- P3: lipsesc `cap_drop: [ALL]` + capabilități minime și `pids` limits pe toate serviciile; nginx ar putea rula read-only cu tmpfs pe `/var/cache/nginx`, `/var/run`; certbot fără `no-new-privileges` și membru inutil al rețelei `simplead`.

---

## Backupul bazei de date A MANAGERULUI

Două mecanisme: dump-ul zilnic PG + AppBackup (arhivă aplicație).

**1. `db:dump --keep 7` la 02:30** (`routes/console.php:101-104`, comanda `app/Console/Commands/DatabaseDumpCommand.php`):

- **Unde ajung dump-urile:** `storage_path('app/db-dumps')` (`DatabaseDumpCommand.php:26`) = volumul Docker `app-storage`, **pe același disc cu volumul de date PostgreSQL**. Verificat runtime: 7 fișiere `db_dump_*.sql.gz.enc` a ~298 MB, zilnice, retenția funcționează.
- **Criptate:** da în producția curentă (extensia `.enc` confirmă `BACKUP_ENCRYPTION_KEY` setat; `config/app.php:136`). Dar criptarea e condiționată (`DatabaseDumpCommand.php:72-73` — fără cheie, dump necriptat, fără avertisment), cheia trece prin argv (`openssl enc ... -pass pass:KEY`, `:76-80` — vizibilă în `/proc/*/cmdline` pe durata criptării) și AES-256-CBC fără MAC nu oferă integritate (P3, INF-20).
- **Offsite:** **NU direct.** Singurul drum offsite este componenta `storage` a AppBackup (`AppBackupCreator::backupStorage()` arhivează `storage/app` fără a exclude `db-dumps` — `app/Services/AppBackup/AppBackupCreator.php:379-384`), dar AppBackup e stricat (mai jos). Verificat runtime: `storage/app/backups/application` (fallback-ul local, `:143-148`) este gol. Snapshot-uri Hetzner la nivel de VPS — neverificat. **Ipoteza de lucru conservatoare: pierderea discului/VPS-ului = pierderea managerului + a tuturor dump-urilor.**
- **INF-03 (P1) — eșec mascat:**
  ```php
  $command = sprintf(
      'PGPASSFILE=%s pg_dump -h %s -p %s -U %s %s | gzip > %s', ...);
  ...
  exec($command.' 2>&1', $output, $exitCode);
  ...
  if ($exitCode !== 0) {
  ```
  (`DatabaseDumpCommand.php:47-63`). `exec()` rulează prin `sh -c`; exit code-ul unui pipeline este al **ultimei** comenzi (`gzip`), nu al lui `pg_dump`. Scenariu: parola DB rotită / pgbouncer căzut la 02:30 → `pg_dump` eșuează, `gzip` scrie un `.gz` valid dar gol, exit 0, log `Dump created: ... (0.0 MB)` ca succes; după 7 nopți, `cleanup()` (`:111-129`) a șters toate dump-urile bune. Nu există verificare de dimensiune minimă și nicio alertă. (Contrast: `AppBackupCreator::backupDatabase()` face corect dump-în-fișier + check de dimensiune, `AppBackupCreator.php:295-297`.)
- **Testate:** nu. `backup:verify-restore` săptămânal (`routes/console.php:137-141`) verifică backup-urile **site-urilor WP**, nu dump-urile managerului. Niciun restore-test pentru `db-dumps` sau AppBackup nu există în cod.

**2. AppBackup (arhivă DB + .env criptat + storage, offsite via StorageDestination):**

- **INF-02 (P1) — variabilă nedefinită rupe finalizarea:**
  ```php
  $expiresAt = null;
  if ($config->retention_type === 'days') {
      $expiresAt = now()->addDays($config->retention_value);
  }
  ```
  (`app/Services/AppBackup/AppBackupCreator.php:151-154`). În `create()`, `$config` **nu este definită nicăieri înainte** (prima atribuire este în blocul `catch`, `:205`; cea din `resolveStorageDestination()`, `:254`, e alt scope). PHP 8.3: `Attempt to read property "retention_type" on null` → `Error` prins de `catch (\Throwable)` → backup-ul e marcat `failed` (`:195-201`) **după** ce arhiva a fost deja urcată în storage (`:130-141`), deci `storage_path`/`file_name`/`checksum` nu se salvează niciodată — chiar dacă fișierul există offsite, e de negăsit din aplicație. Fișierul nu a mai fost modificat din 2026-05-10 (commit `c20bc40`, care probabil a șters linia `$config = AppBackupConfig::instance();` odată cu „unused backup encryption module"). Logurile din ultimele 14 zile nu conțin „Application backup failed" — cel mai probabil app-backup nici nu e programat/activat (starea `AppBackupConfig` din DB — neverificat, tinker indisponibil în imaginea prod), ceea ce înseamnă că **managerul nu are în prezent niciun backup offsite funcțional**, cu bug garantat la prima activare.
- `backupEnv()` include `.env` complet (toate secretele: chei HMAC ale conectorilor, API keys) criptat cu `encrypt()`/APP_KEY (`:308-323`) — corect, dar înseamnă că restaurarea DR depinde de păstrarea APP_KEY în afara host-ului (proces — neverificat).

**Schiță de remediere (neaplicată):** (a) fix `$config = AppBackupConfig::instance();` la începutul `create()`; (b) `db:dump`: dump în fișier + verificare exit code `pg_dump` + prag minim de dimensiune + gzip separat (ca în AppBackupCreator) + alertă prin NotificationService la eșec; (c) pas de upload offsite (S3) direct în `db:dump` sau activarea AppBackup zilnic cu destinație S3; (d) un restore-test lunar al dump-ului într-un Postgres efemer (`pg_restore --list` minim); (e) depozitarea APP_KEY + BACKUP_ENCRYPTION_KEY într-un seif în afara host-ului.

---

## Log rotation

- **Laravel:** rotație activă și sănătoasă — verificat runtime în `storage/logs/`: fișiere `laravel-YYYY-MM-DD.log` zilnice de ~250 KB (deci `LOG_CHANNEL` efectiv e pe canal daily; `config/logging.php:68-72` — `days => 14`). ✔
- **Docker (INF-09, P2):** `/etc/docker/` este **gol** (verificat) — nu există `daemon.json`, deci driverul implicit `json-file` **fără limită de dimensiune sau rotație**. Toate stream-urile stdout/stderr cresc nemărginit: access-log-ul nginx merge integral în docker logs (imaginea nginx face symlink `/var/log/nginx/access.log → /dev/stdout`; formatul detaliat e definit în `docker/nginx/nginx.conf:19-24`), Postgres loghează query-urile >1s (`log_min_duration_statement=1000`, `docker-compose.prod.yml:216-217`), FPM forwardează stderr-ul workerilor (`catch_workers_output = yes`, `docker/php/php-fpm-pool.conf`). pgsql/redis/gotenberg rulează neîntrerupt de 2 luni. Dimensiunea actuală a fișierelor json-log — neverificat (necesită root pe `/var/lib/docker`), dar discul are 100 GB liberi, deci e o degradare lentă, nu iminentă. Scenariu de eșec: un incident care produce log-spam (ex. buclă de erori pe un site monitorizat, scanner pe nginx) umple discul → Postgres se oprește la disc plin. Remediere: `daemon.json` cu `{"log-driver":"json-file","log-opts":{"max-size":"50m","max-file":"5"}}` + restart planificat al daemonului (sau `logging:` per serviciu în compose, care se aplică la recreate).
- **Acumulare imagini (INF-18, P3):** `docker system df` — 9,65 GB imagini (98% reclamabile) + 5,96 GB build cache; `deploy.sh` nu face niciun `docker image prune`. Creștere constantă cu fiecare deploy.

---

## Certbot & TLS

- **Renewal:** containerul certbot rulează `certbot renew --webroot -w /var/www/certbot` la fiecare 12h (`docker-compose.prod.yml:174-175`), webroot partajat cu nginx prin volumul `simplead-certbot`; nginx servește `/.well-known/acme-challenge/` din el (`docker/nginx/conf.d.ssl/app.conf:7-9`). Funcționează: certificatul live are `notBefore=Jun 21 2026, notAfter=Sep 19 2026` (verificat cu openssl s_client).
- **INF-08 (P2): nginx nu recitește certificatul reînnoit.** Nginx încarcă certificatul o singură dată, la pornire; nu există deploy-hook, cron de `nginx -s reload` sau reload periodic — certbot nici nu ar putea să-l declanșeze (nu are docker socket, și e bine că nu are). Certificatul nou e preluat doar pentru că deploy-urile recreează nginx frecvent (`deploy.sh:100`). Scenariu de eșec: proiectul intră în mentenanță pasivă, fără deploy >30 de zile după un renewal → nginx servește certificatul vechi până la expirare → manager complet inaccesibil (inclusiv callback-urile de backup ale conectorilor). Remediere: cron pe host `docker exec simplead-nginx nginx -s reload` zilnic, sau `docker kill -s HUP simplead-nginx` după fiecare `certbot renew` reușit (script pe host, nu în container).
- **Config TLS** (`docker/nginx/conf.d.ssl/app.conf:26-33`): TLS 1.2/1.3, suite ECDHE-AES-GCM, session tickets off — solid. HSTS 1 an + `includeSubDomains` (`:42`), header-e de securitate prezente (`:37-43`), `server_tokens off` (`nginx.conf:13`). Rate-limit pe `/login` 5r/m (`nginx.conf:52-56`, `app.conf:57-60`).
- **Fallback HTTP (P3, INF-21):** dacă certificatele lipsesc la pornire, entrypoint-ul nginx activează `http.conf` (`docker-compose.prod.yml:145-156`) care servește aplicația pe HTTP simplu, fără rate-limit pe login și fără HSTS (`docker/nginx/conf.d/app.conf:1-49`). Fereastră doar la bootstrap/dezastru, dar cookie-urile au `session.cookie_secure = On` (`docker/php/php.ini`), deci sesiunile nu ar circula în clar — impact limitat.

---

## Gotenberg

- **Expunere:** doar internă — fără `ports:` (`docker-compose.prod.yml:306-329`), apelat de aplicație la `http://gotenberg:3000` (`config/services.php:53-55`, `app/Services/GotenbergService.php:20`).
- **Intrare:** aplicația trimite HTML generat de ea prin `Stream::string(...)` (`GotenbergService.php:66-73`) — nu URL-uri arbitrare; `--chromium-disable-javascript=true` (`docker-compose.prod.yml:312`) taie JS-ul. Bine.
- **INF-07 (P2): SSRF prin subresurse + proxy intern.** Două vectoare rămân: (1) Chromium încarcă în continuare subresursele din HTML (img/link/font) — orice URL controlabil ajuns în template-urile de raport (logo client, favicon-uri/screenshot-uri provenite de la site-uri WP ale clienților, potențial compromise) devine un fetch executat **din interiorul rețelei `simplead`**, cu egress nelimitat, inclusiv către `http://169.254.169.254/` (metadata Hetzner Cloud) sau `http://app:9000` — dacă template-urile includ asemenea URL-uri externe este de verificat în auditul modulului 18 (aici: neverificat); (2) API-ul Gotenberg nu are autentificare și expune și ruta de conversie **după URL**, deci orice alt container compromis îl poate folosi ca proxy SSRF cu randare completă. Nu sunt setate `--chromium-allow-list` / `--chromium-deny-list` (comanda completă: `docker-compose.prod.yml:310-314`).
- Remediere: `--chromium-deny-list` pentru RFC1918 + link-local (sau allow-list strict pe domeniile de asset-uri), rețea dedicată `pdf` fără pgsql/redis în ea, opțional `--api-enable-basic-auth` cu secret din env.
- P3 (INF-22): limita de 512M + `--api-timeout=120s` — rapoartele PDF mari (multe pagini + imagini) pot OOM-ui containerul; simptom: eșecuri intermitente de generare rapoarte.

---

## Deploy (deploy.sh + pașii din CLAUDE.md)

Fluxul `deploy.sh`: git pull → build (site sus, ok) → `horizon:terminate` → **`sleep 5`** → `docker rm -f simplead-{app,horizon,scheduler,nginx}` → `up -d app horizon scheduler` → așteaptă app (max 90s) → `migrate --force` → `queue:restart` → `artisan up` → `up -d nginx`.

- **INF-01 (P1): joburile in-flight sunt ucise cu SIGKILL.** `horizon:terminate` (`deploy.sh:43-44`) cere oprire grațioasă, dar la 5 secunde după (`:45`) scriptul face `docker rm -f "simplead-horizon"` (`:54-57`) — SIGKILL imediat, `stop_grace_period` nu se aplică la `rm -f`. Chiar și pe calea `up --force-recreate` (când numele nu se potrivește — vezi INF-19), grația e 300s (`docker-compose.prod.yml:65`), de 12 ori sub timeout-ul cozii backups (3600s, `config/horizon.php:242`) — propriul comentariu din compose o recunoaște: „Must exceed longest job timeout (backups: 2700s = 45min)" (`:63-64`). Scenariu de eșec concret: deploy lansat în timp ce `RestoreBackup` rescrie un site live de client → procesul moare la jumătatea restore-ului → site-ul clientului rămâne într-o stare inconsistență (DB parțial importat / fișiere amestecate). Remediere: după `horizon:terminate`, poll până când `horizon:status` raportează inactive/paused și coada de workeri e goală (cu timeout generos, ex. 3900s, și confirmare manuală de override), `stop_grace_period` ≥ timeout-ul maxim al joburilor, și eliminarea `docker rm -f` pentru containere care rulează.
- **INF-10 (P2): fereastra de downtime e un hard-outage, iar `artisan down` e teatru.** Bucla de la `:55-57` include **nginx**, care e șters la începutul ferestrei și recreat abia la pasul 11 (`:100`), după healthcheck (până la 90s) + migrații. În tot acest interval, portul 443 e mort (connection refused), nu pagină de mentenanță: cad callback-urile de backup de la site-urile WP (`/api/backup/callback`), webhook-urile inbound, paginile publice de status și link-urile de rapoarte ale clienților. `php artisan down` (`:49-50`) e inutil de vreme ce și app și nginx sunt oricum șterse. Pașii „deploy" din CLAUDE.md au ordinea corectă (nginx recreat doar la final, fără rm anticipat) — cele două proceduri au divergat. Remediere: scoateți nginx din bucla de rm; recreați-l ultimul (cum face deja pasul 11); mutați `artisan down` să acopere doar migrate.
- **INF-04 (P1):** niciun restart PgBouncer după `migrate --force` (`:78-81`) — detaliat în secțiunea PostgreSQL & PgBouncer; incident real pe 2026-03-28.
- **INF-19 (P3):** cleanup-ul de orfani (`:59-61`) filtrează doar `status=created|exited`, deci un container redenumit de compose care **rulează** scapă — verificat runtime: containerul horizon activ se numește `2eef8f7112a8_simplead-horizon` de 23h, dovadă că exact asta s-a întâmplat la ultimul deploy (`docker rm -f simplead-horizon` de la următorul deploy nu-l va nimeri; `up --force-recreate` da, prin label-uri).
- Observație: pasul 8 din script afirmă că e sărit config caching-ul pentru că rootfs-ul e read-only (`deploy.sh:83-85`), dar `entrypoint.sh:6-8` rulează `config:cache`/`route:cache`/`view:cache` la fiecare pornire în tmpfs-ul `bootstrap/cache` — comentariul e perimat, comportamentul e corect.

---

## Constatări

| ID | Sev. | Fișiere:linii | Descriere | Scenariu de eșec | Remediere (schiță) |
|---|---|---|---|---|---|
| INF-01 | **P1** | `deploy.sh:43-57`; `docker-compose.prod.yml:63-65`; `config/horizon.php:232-243` | Deploy-ul ucide joburile in-flight: `docker rm -f` pe horizon la ~5s după `horizon:terminate`; `stop_grace_period` 300s vs timeout backups 3600s (comentariul din compose cere >2700s) | Deploy în timpul unui `RestoreBackup` → SIGKILL la jumătatea restaurării → site live de client lăsat inconsistent; backup-uri lungi pierdute | Drain real (poll `horizon:status` + workeri inactivi), `stop_grace_period` ≥ timeout max, fără `rm -f` pe containere care rulează |
| INF-02 | **P1** | `app/Services/AppBackup/AppBackupCreator.php:151-154` (vs `:205`); `routes/console.php:123-127` | `$config` nedefinit în `create()` → orice app-backup crapă la finalizare (după upload) și e marcat `failed`, fără `storage_path` salvat; managerul nu are backup offsite funcțional | Activarea backup-urilor programate → toate eșuează; la un incident de host, singurele copii ale DB-ului managerului (dump-urile locale) se pierd odată cu discul | `$config = AppBackupConfig::instance();` la începutul metodei + test de fum al pipeline-ului; destinație offsite implicită |
| INF-03 | **P1** | `app/Console/Commands/DatabaseDumpCommand.php:47-63,111-129`; `routes/console.php:101-104,137-141` | `db:dump`: exit code-ul pipeline-ului `pg_dump \| gzip` este al lui gzip → eșecul pg_dump raportat ca succes; fără verificare de dimensiune; retenția `--keep 7` șterge dump-urile bune; dump-urile nu sunt niciodată restore-testate | Parolă DB rotită / pgbouncer indisponibil la 02:30 → 7 nopți de dump-uri goale „reușite" → zero backup DB utilizabil exact când e nevoie | Dump în fișier + check exit code pg_dump + prag minim de mărime + alertă la eșec + restore-test periodic |
| INF-04 | **P1** | `deploy.sh:78-81` (fără restart pgbouncer); `config/database.php:84-103`; runtime `pgbouncer.ini` (fără `max_prepared_statements`) | PgBouncer (transaction pooling, prepared statements cache-uite) nu e repornit după migrații DDL — incident real 2026-03-28 („cached plan must not change result type", 500-uri pe dashboard) | Orice deploy cu `ALTER TABLE` pe tabele existente → erori 500 intermitente în toată aplicația până la restart manual | `restart pgbouncer` după migrate în deploy.sh; DDL rulat direct pe `pgsql` |
| INF-05 | **P1** | `config/horizon.php:232-243,283-303`; `docker-compose.prod.yml:82-86` | Praguri de memorie Horizon (3 workeri backups × 1024 MB + celelalte supervisoare) mult peste limita containerului de 1024 MB | Două backup-uri mari concurente → cgroup OOM-kill (SIGKILL) pe workeri la mijlocul joburilor, fără cleanup; posibil kill al întregului container | Limita containerului dimensionată după suma workerilor sau praguri/workeri reduse |
| INF-06 | P2 | `docker-compose.prod.yml:331-333` (+`networks:` la fiecare serviciu); `docker/php/php-fpm-pool.conf:5` | O singură rețea bridge: nginx/gotenberg/certbot ajung direct la pgsql/pgbouncer/redis și la FastCGI `app:9000` (fără autentificare) | Compromiterea containerului expus (nginx) sau a gotenberg → pivot direct pe stratul de date și execuție PHP prin FastCGI | Segmentare frontend/backend/pdf; certbot fără rețea partajată; FPM accesibil doar din frontend |
| INF-07 | P2 | `docker-compose.prod.yml:310-314`; `app/Services/GotenbergService.php:52-77` | Gotenberg fără allow/deny-list și fără autentificare: subresursele HTML sunt fetch-uite din rețeaua internă cu egress liber (inclusiv 169.254.169.254); orice container îl poate folosi ca proxy SSRF | URL de imagine controlat de un site WP compromis ajunge într-un raport → citire metadata cloud / probe pe rețeaua internă din containerul gotenberg | `--chromium-deny-list` pe RFC1918+link-local, rețea dedicată, opțional basic-auth |
| INF-08 | P2 | `docker-compose.prod.yml:145-156,174-175`; `deploy.sh:100` | Nginx încarcă certificatul TLS doar la pornire; certbot reînnoiește, dar nimic nu face reload — certificatul nou e preluat doar dacă se întâmplă un deploy | Fără deploy >30 zile după un renewal → certificat expirat → manager și toate callback-urile publice inaccesibile | Cron pe host: `docker exec simplead-nginx nginx -s reload` zilnic (sau HUP după renew) |
| INF-09 | P2 | `/etc/docker/` gol (verificat); `docker/nginx/nginx.conf:19-24`; `docker-compose.prod.yml:216-217` | Fără `daemon.json` → loguri `json-file` nelimitate pentru toate containerele (access-log nginx complet, slow-query PG, stderr FPM); containere neîntrerupte de 2 luni | Log-spam la un incident umple discul → Postgres refuză scrieri → cade tot managerul | `daemon.json` cu `max-size`/`max-file` sau `logging:` per serviciu în compose |
| INF-10 | P2 | `deploy.sh:49-57,100` vs pașii din CLAUDE.md | Deploy.sh șterge nginx la începutul ferestrei și îl recreează abia după migrații → hard-outage (connection refused), nu pagină de mentenanță; `artisan down` fără efect; procedura diverge de CLAUDE.md | Fiecare deploy: minute de refuz de conexiune pentru callback-urile de backup WP, webhook-uri, status pages și link-urile publice de rapoarte | Nginx scos din bucla de rm; recreat doar la final; `down` doar în jurul migrate |
| INF-11 | P2 | `docker-compose.prod.yml:281-290`; `config/database.php:161,170` | Redis `requirepass` fail-open: cu `REDIS_PASSWORD` gol pornește fără auth și totul (healthcheck, aplicație) funcționează silențios; parola există azi (verificat `NOAUTH`), `.env.example` neverificat | Regenerarea `.env` / mediu nou fără parolă → Redis deschis pe rețeaua partajată → injecție de joburi serializate = execuție de cod în horizon | `${REDIS_PASSWORD:?required}` în command (fail-closed) |
| INF-12 | P2 | `docker-compose.prod.yml:240` (`edoburu/pgbouncer:latest`), `:171` (`certbot/certbot` fără tag); runtime PgBouncer 1.25.1, ini fără `max_prepared_statements` | Imagini nepinate pe componenta de care depinde suportul prepared-statements în transaction pooling | Un `pull` viitor schimbă versiunea/default-urile PgBouncer → erori „prepared statement does not exist" în toată aplicația | Pin pe versiune (`edoburu/pgbouncer:v1.25.1-p0`) + `MAX_PREPARED_STATEMENTS` explicit |
| INF-13 | P3 | `docker-compose.prod.yml` (toate serviciile) | Lipsesc `cap_drop: [ALL]` și `pids` limits | Post-compromis, capabilități implicite disponibile; fork-bomb într-un container epuizează PID-urile host-ului | `cap_drop: [ALL]` + capabilități minime, `pids: 256` |
| INF-14 | P3 | `docker-compose.prod.yml:170-185` | Certbot fără `no-new-privileges` (verificat și runtime: `secopt=<no value>`) și membru inutil al rețelei `simplead` | Suprafață suplimentară în containerul cel mai puțin întreținut | Adăugat security_opt; scos din rețea |
| INF-15 | P3 | `docker-compose.prod.yml:290` | Healthcheck-ul Redis pune parola în argv (`redis-cli -a`) — vizibilă în `ps` din container | Leak local al parolei Redis către orice proces din container | `REDISCLI_AUTH` env |
| INF-16 | P3 | `docker-compose.prod.yml:96-101` | Healthcheck-ul scheduler-ului e `php -r 'echo 1;'` — trece chiar dacă `schedule:work` a murit ca proces copil sau blochează | Scheduler căzut nedetectat → backup-uri/monitorizări oprite silențios | Verificare reală (ex. timestamp heartbeat scris de scheduler) |
| INF-17 | P3 | `docker-compose.prod.yml:124-168` | Nginx nu e `read_only` (singurul serviciu propriu fără) | Persistență facilitată post-compromis în containerul expus public | `read_only: true` + tmpfs `/var/cache/nginx`, `/var/run` |
| INF-18 | P3 | `deploy.sh` (fără prune); runtime `docker system df`: 9,5 GB imagini reclamabile + 6 GB build cache | Acumulare nelimitată de imagini vechi și build cache la fiecare deploy | Creștere lentă până la presiune pe disc | `docker image prune -f` (păstrând ultimele N) la finalul deploy-ului |
| INF-19 | P3 | `deploy.sh:59-61`; runtime: container activ `2eef8f7112a8_simplead-horizon` | Cleanup-ul de orfani prinde doar containere created/exited; cele redenumite care rulează scapă (dovadă: horizonul curent are nume prefixat) | `docker rm -f simplead-horizon` de la deploy nu nimerește containerul real; confuzie operațională la `logs`/`exec` după nume | Filtrare pe label compose, nu pe nume/status |
| INF-20 | P3 | `app/Console/Commands/DatabaseDumpCommand.php:72-96` | Criptarea dump-ului: cheia pe linia de comandă (`-pass pass:`) și AES-256-CBC fără autentificare (fără HMAC); necriptat silențios dacă cheia lipsește | Cheie vizibilă în `/proc` pe durata criptării; tamper nedetectabil; regresie silențioasă la dump necriptat | `-pass env:…`, `age`/GPG sau AES-GCM; warning când cheia lipsește |
| INF-21 | P3 | `docker-compose.prod.yml:145-156`; `docker/nginx/conf.d/app.conf` | Fallback-ul HTTP (fără certificate) servește aplicația în clar, fără rate-limit pe login și fără HSTS | Fereastră de bootstrap/dezastru cu login pe HTTP (atenuat de `session.cookie_secure=On`) | Fallback-ul să servească doar ACME + 503 |
| INF-22 | P3 | `docker-compose.prod.yml:310-329` | Gotenberg limitat la 512 MB cu Chromium — rapoartele PDF mari pot OOM-ui containerul | Eșecuri intermitente de generare a rapoartelor client | Limită 1 GB sau paginare/split al rapoartelor mari |

**Bilanț: 0×P0, 5×P1, 7×P2, 10×P3.**
