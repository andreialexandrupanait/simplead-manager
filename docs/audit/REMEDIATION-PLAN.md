# Plan de remediere — execuție

Secvență concretă de PR-uri mici, ordonate pe risc și dependențe. Îl parcurgem în ordine; fiecare rând = un PR. Referințele `#N` trimit la constatările din [`../../AUDIT.md`](../../AUDIT.md).

**Legendă:** ✅ gata · 🚧 în lucru · ⬜ de făcut · efort: S (≤0.5 zi) / M (1–2 zile) / L (3+ zile)

---

## Etapa 0 — Fundație de verificare (o dată, deblochează încrederea în tot restul)

| # | PR | Efort | De ce acum | Constatare |
|---|----|-------|-----------|------------|
| 0.1 | ✅ **Audit + rapoarte** (PR #5) | — | livrat | — |
| 0.2 | 🚧 **CI minim** (PR #7) — GitHub Actions: `pint --test` + `phpstan` (blocante) + `phpunit` pe pgsql/redis efemer (non-blocant) + `composer audit` (advisory) | S | Nu putem verifica local (fără PHP pe host). Fără asta, fiecare fix pleacă pe încredere. | Testing #1/#2 |
| 0.3 | 🚧 **Izolare suită de test** (PR #7, bundluit) — `phpunit.xml` pinuit `force=true` (DB_HOST/REDIS_* → 127.0.0.1), ca o rulare pe prod să eșueze inofensiv | S | Prerechizit ca CI-ul de la 0.2 să fie sigur și repetabil | Testing P1 |

> Notă: suita actuală poate fi deja roșie. La 0.2 rulăm `phpunit` **non-blocant** (raportează, nu oprește merge-ul); îl facem blocant după ce e verde.

---

## Etapa 1 — Stop-the-bleeding (P0, fiecare PR mic și izolat)

| # | PR | Efort | Constatare |
|---|----|-------|------------|
| 1.1 | ✅ **Autorizare pe restore + anti-IDOR** (PR #6) | S | #1 |
| 1.2 | ⬜ **Închide gaura Viewer sistemică** — `authorizeSiteModification` pe toate acțiunile mutante: `WithBackupActions` (delete/bulk), plugins/teme, security users, DB cleanup, Cloudflare, uptime, SEO, performance. Mecanic, același pattern. | M | #7 |
| 1.3 | ⬜ **Restore recuperabil** — `failed()` + `uniqueFor` pe `RestoreBackup`; detecție stuck-restore în `BackupDispatcher`; `backup:release-lock` acoperă restore | M | #4, #5 |
| 1.4 | ⬜ **Deploy sigur** — `stop_grace_period ≥ 3600s`; restart PgBouncer după DDL în `deploy.sh`; drain real Horizon (poll până la inactiv) | S | #5, INF-01/04 |
| 1.5 | ⬜ **Lock per-site pe backup/restore** — `uniqueId` per-site pe toată clasa; guard în dispatcher pe `restore_status` | M | #4 |
| 1.6 | ⬜ **Update prin SafeUpdate** — rutează fluxul UI de update prin `RunSafeUpdate` pe coadă (backup sincron → update → health check → auto-rollback); scoate execuția sincronă din request | L | #2, #6 |
| 1.7 | ⬜ **Corectitudine runtime** — coloane inexistente în incident-response (`status`→`is_fixed`, `cvss_score`→`software_slug`) + backup manager (`$config`, exit-code `pg_dump`, offsite S3) | M | #8, #9 |

---

## Etapa 2 — Stabilizare (observabilitate + teste pe căile distructive)

| # | PR | Efort | Constatare |
|---|----|-------|------------|
| 2.1 | ⬜ **Heartbeat extern** (healthchecks.io) pe scheduler + canal sincron pentru meta-alerte (Horizon jos) | S | #10 |
| 2.2 | ⬜ **Alertare pe eșecuri silențioase** — backup oprit (disc plin), scan securitate înghețat, rapoarte eșuate, notificări failed care escaladează | M | Notif/Backup/Reports P1 |
| 2.3 | ⬜ **Audit trail distructiv** — user inițiator propagat în constructorii job-urilor + log restore/push/safe-update/delete | M | D-P1-3 |
| 2.4 | ⬜ **Test restore end-to-end** cu `FakeWordPressApiService` (arhivă coruptă → abort; eșec chunk → site scos din maintenance) | L | Testing |
| 2.5 | ⬜ **Test plugin push / safe-update + HMAC connector + token flows publice** | L | Testing |
| 2.6 | ⬜ **Curăță baseline PHPStan categoria A** (bug-uri reale) + completează `WordPressApiServiceInterface` | S | Testing P1 |
| 2.7 | ⬜ **PHPStan OOM** — analiza nu se termină nici la 4GB (larastan bootează app-ul și recursează în container). De diagnosticat și de făcut jobul `static` blocant după fix. | M | descoperit de CI (PR #7) |
| 2.8 | ⬜ **CVE guzzlehttp/psr7 <2.12.1** (CRLF injection, GHSA-vm85-hxw5-5432) — bump; `composer audit` îl semnalează | S | descoperit de CI (PR #7) |
| 2.9 | ⬜ **`.env.example` lipsă din repo** — de adăugat un exemplu sanitizat (blochează onboarding + build-uri) | S | descoperit de CI (PR #7) |

---

## Etapa 3 — Harden (arhitectură, continuu, după ce fundația e verde)

- Dizolvă job-urile god-object de backup în `Services/Backup/Pipelines/` cu contract comun + teste.
- Un singur client HTTP semnat către connector (concern `ManagesSeo`); retrage calea HMAC legacy fără nonce.
- Rotire chei connector + `api_key_hash` determinist pentru lookup agent.
- Retenție + indexare pe tabele fierbinți (uptime_checks, seo_pages, activity_logs).

---

## Regula de aur
Niciun feature nou (din [`../../ROADMAP.md`](../../ROADMAP.md)) pe o cale distructivă înainte ca acea cale să fie securizată (Etapa 1) și testată (Etapa 2).

## Stare curentă
Facem **0.2 (CI)** acum. Următorul: 0.3, apoi 1.2.
