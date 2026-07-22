# Raport Faza A — starea reală a problemelor cunoscute (22 iulie 2026)

Verificare punct cu punct în cod, la HEAD `0351c29`, conform cerinței Fazei A din promptul-program.
Fiecare punct: verdict + dovadă file:line. Baseline quality: vezi finalul raportului.

## 1. Laravel 11.48 (EOL securitate) — CONFIRMAT
`composer.json:13` cere `laravel/framework: ^11.31`; `composer.lock` are instalat `v11.48.0`.
Composer audit în CI (advisory-only) e roșu: 24 advisories / 12 pachete. → C1 (upgrade L12).

## 2. MFA șters complet — CONFIRMAT
Migrarea `database/migrations/2026_07_11_000001_drop_two_factor_columns_from_users.php` (liniile
19–21) șterge `two_factor_enabled/secret/recovery_codes`; pachetele compose au fost scoase (PR #34).
Nu există niciun cod TOTP pentru utilizatorii Manager-ului. Singurele referințe „2FA" rămase sunt
feature-ul separat de 2FA email pentru site-urile clienților (`SecuritySettingsService.php:38,59`,
`SecurityLogin.php`) — NU e autentificarea aplicației. → C1 (MFA TOTP obligatoriu Admin, și pe SSO).

## 3. `.env.example` lipsă — CONFIRMAT
Nu există în rădăcina repo-ului (doar `.env` local). Instalările noi nu se pot reconstrui din repo. → C1.

## 4. Tabele orfane SEO + schemă stale — CONFIRMAT, cu corecție de cifră
`database/schema/pgsql-schema.sql` are 106 tabele; **~9–12 orfane** (nu „~14"), fără model Eloquent
și fără consum în `app/` (excepție: `RetentionPolicyService.php:51` curăță `keyword_positions`):
`crawled_pages`, `site_crawls`, `seo_contents`, `seo_content_revisions`, `seo_alert_rules`,
`backlinks`, `backlink_snapshots`, `tracked_keywords`, `keyword_positions`, `keyword_page_mappings`,
`keyword_research_results`, `competitor_keyword_positions`. (Keyword tracking-ul real trăiește în
`seo_keyword_rankings.is_tracked`, nu în `tracked_keywords`.)
Schema e **stale**: ultimul commit pe `pgsql-schema.sql` din 14 mai (`bebf236`), migrări până la
`2026_07_12_000011` — ~2 luni în urmă. → C1 (drop + regenerare); atenție în D1 să nu refolosim
numele tabelelor orfane înainte de drop.

## 5. `bootstrap/app.php:22-29` citește `env()` direct — CONFIRMAT, dar e REVERT DELIBERAT
`$middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'), …)` — cu comentariu explicit:
swap-ul pe `config()` (P3-34) a dat fatal și a picat producția (hotfix `d7e26a3`). **Fix-ul din C1
NU poate fi re-aplicarea `config()` în forma veche** — se rezolvă corect odată cu upgrade-ul
Laravel 12 (unde configurarea trustProxies se poate muta în config nativ) + test de regresie care
simulează config:cache. Punctul rămâne valid, dar cu abordare revizuită.

## 6. `edoburu/pgbouncer:latest` nepinuit — CONFIRMAT
`docker-compose.prod.yml:285`. → C1 (pin pe digest).

## 7. `/restore-download/{token}` fără expirare — CONFIRMAT
`routes/web.php:39-50` — validare doar regex `^[a-f0-9]{64}$` + existența fișierului
`storage/app/temp/restore-{token}`; nu e signed URL, nu are timestamp. Fișierul se șterge după
fetch-ul conectorului (`RestoreBackup::sendRestoreData`), dar un restore eșuat lasă tokenul valid
nelimitat. Throttle 10/min există. → C1 (expirare).

## 8. Transport restore = POST-uri sincrone 1800s — CONFIRMAT
`app/Jobs/RestoreBackup.php:999-1004` — `POST /backup/restore` cu timeout 1800s; job timeout 3600s
(linia 35). → C2 (transport asincron cu handshake job-token + poll + reconciliere).

## 9. `file_mode=staged` fără verificarea capabilității — CONFIRMAT
`RestoreBackup.php:997`: `$fileMode = empty($this->selectedFiles) ? 'staged' : 'merge';` — trimis
orb; comentariul (993–996) recunoaște că conectoarele < 2.15.0 ignoră flag-ul și fac merge in-place
(exact fallback-ul tăcut interzis de program). Nu există negociere de capabilități nicăieri —
conectorul raportează doar `plugin_version` prin `/info` (`class-info-endpoint.php`), stocat în
`sites.connector_version` (care poate fi și el stale — istoric confirmat pe flotă). → C2.

## 10. Furtuni de alerte nededuplicate — CONFIRMAT
`app/Jobs/CheckUptime.php:442-445` — `SiteWentDown::dispatch()` per site individual;
`app/Jobs/NotifyIncident.php:37,55-63` — mesaj per site/incident. Zero agregare cross-site sau
deduplicare (N site-uri down în T minute = N×canale mesaje). → C2.

## 11. Uptime 2 workeri + `check_locations` nefolosit — CONFIRMAT (ambele)
`config/horizon.php` (supervisor-uptime): `maxProcesses => (int) env('HORIZON_UPTIME_WORKERS', 2)`.
`check_locations` (jsonb) există în schemă (`pgsql-schema.sql:4297`) și în model
(`UptimeMonitor.php:60,130,160` — property/fillable/cast) dar nu e citit nicăieri în `app/`. → F.

## 12. God-objects — CONFIRMAT, cu o corecție
- `app/Jobs/RestoreBackup.php` — **1.132 linii**
- `app/Jobs/CreateBackup.php` — **1.118 linii**
- `app/Jobs/CrawlSitePages.php` — **797 linii**
- `SiteSeoAudit` — **787 linii**, dar e componentă **Livewire** (`app/Livewire/Sites/Detail/SiteSeoAudit.php`), nu Job.
`CrawlSitePages`/`SiteSeoAudit` probabil mor cu modulul vechi (confirmă la F); Restore/CreateBackup → F sub e2e-urile din C2.

## 13. `phpstan-baseline.neon` ~50KB — CONFIRMAT
50.561 bytes, 1.411 linii. → F (reducere + level 6).

---

## Baseline quality (rulat local prin docker, fără PHP pe host)

| Gate | Rezultat |
|---|---|
| `pint --test` | **VERDE** — 783 fișiere PASS |
| `phpstan analyse --memory-limit=1G` | **VERDE** — No errors (cu baseline-ul de 50KB) |
| `phpunit` (sam-test-pgsql/redis) | **VERDE** — 744 teste, 2.079 assertions, OK (1 deprecare PHPUnit, 18m45s) |

## Concluzie Faza A

Toate cele 13 puncte cunoscute sunt reale (2 corecții de detaliu: numărul tabelelor orfane e ~9–12,
nu 14; `SiteSeoAudit` e Livewire, nu Job; 1 nuanță majoră: trustProxies e revert deliberat post-P3-34,
fix-ul se re-proiectează în C1 împreună cu L12). Baseline-ul de calitate e verde → programul poate
porni de pe fundație stabilă. Pasul următor: Faza B (research R1–R4 + propuneri).
