# Plan Faza C — Corectarea & întărirea a tot ce există (fără funcții noi)

Aprobat scope: C-01…C-14 integral (STOP Faza B, 22 iul). Valuri de PR-uri mici, fiecare cu teste +
CHANGELOG; Pint + PHPStan + PHPUnit verzi per PR; migrări aditive pe `pgsql_direct` + restart
PgBouncer după DDL la deploy. La final: subagent AUDITOR (Anexa 1) → `raport-faza-C.md` → STOP.

## Ordinea valurilor (raționament: quick-wins întâi, L12 ÎNAINTE de MFA ca să nu alegem pachete de două ori, C2 pe fundația L12)

### Val C1-a — Quick wins de securitate/igienă (PR-uri mici, independente)
1. **PR: C-06 pin pgbouncer** — `edoburu/pgbouncer:latest` → digest exact (`docker-compose.prod.yml:285`); doar infra, fără test PHP.
2. **PR: C-07 expirare `/restore-download/{token}`** — TTL pe token (timestamp în cache/DB la generare, verificat în rută + cleanup fișier orfan); test negativ token expirat + test pozitiv în fereastră (`routes/web.php:39-50`, `RestoreBackup::sendRestoreData`).
3. **PR: C-03 `.env.example` + runbook** — toate variabilele din config/ cu comentarii, zero valori reale; `docs/runbook-instalare.md` (instalare de la zero + DR: restore DB, re-deploy, re-push conector).
4. **PR: C-14 fix decalaj GSC** — `FetchKeywordRankings.php:52,97`: `recorded_date` = data ferestrei GSC (now()-3d), nu azi; test pe cheia de dată.
5. **PR: C-04 drop tabele orfane + regenerare schemă** — migrare drop pentru cele ~12 tabele orfane (după dublă verificare zero referințe + backup prod înainte de deploy); `php artisan schema:dump` regenerat. Atenție deploy: pgsql_direct + restart PgBouncer.

### Val C1-b — Laravel 11 → 12 (C-01 + C-05, un PR mare sau două)
- Know-how: sad-erp e deja pe 12. Suită completă pe Postgres real în CI; atenție Livewire uploads, casturi `encrypted`, cozi/Horizon.
- **C-05 inclus aici**: trustProxies mutat pe mecanismul nativ L12 (config `trustedProxies` fără `env()` în bootstrap), cu test de regresie care simulează `config:cache` (capcana P3-34 — NU re-aplicăm naiv `config()` pe L11).
- Composer audit: re-verificat după upgrade (azi 24 advisories / 12 pachete).

### Val C1-c — MFA TOTP (C-02)
- TOTP + coduri recovery, **obligatoriu Admin**, aplicat și pe Google SSO (challenge după callback), rate-limit pe verificare, audit log enroll/disable. Pachete alese PE L12. Teste: enroll, login cu/fără cod, recovery, negative de autorizare.

### Val C2 — Încrederea în operațiile critice (după C1)
6. **PR: C-10 negocierea capabilităților** — conectorul își anunță capabilitățile (extindere `/info` sau endpoint dedicat, listă de capability keys per versiune), Manager le stochează per site; operațiile fără capabilitate → REFUZ explicit „actualizează conectorul la ≥X" (primul consumator: `file_mode=staged`, `RestoreBackup.php:997`). Fără fallback tăcut. Bump versiune conector.
7. **PR: C-11 agregarea furtunilor** — N site-uri down în T minute → 1 notificare agregată per canal + 1 la recovery; test cu 20 site-uri simulate → se numără mesajele (`CheckUptime.php:442`, `NotifyIncident`).
8. **PR: C-13 e2e `FakeWordPressApiService`** — backup→verificare→restore staged; safe-update cu rollback la health-check picat; callback-uri. Plasa pentru C-09 și Faza F.
9. **PR-uri: C-09 transport asincron restore** — handshake job-token, conectorul rulează detașat cu fișier de progres, poll semnat din Manager, finalizare idempotentă (restore „failed" de transport dar terminat de conector se reconciliază la poll — niciodată „fișiere noi + DB vechi"), kill-switch. Construit PE capabilitățile din C-10 (conector nou anunță `async_restore`). Bump conector + push flotă.
10. **PR: C-08 proven restore** — job săptămânal, restaurează cel mai recent backup al unui site (rotație) într-un sandbox WP containerizat izolat pe dasher; health-checks (homepage 200, login, coerență DB cu manifestul); badge „ultimul restore dovedit" per site + global + alertă la eșec.
11. **PR: C-12 offsite verificat** — banner per site fără destinație offsite activă + job validare credențiale și ultima replicare.

### Final de fază
- Subagent AUDITOR (context curat, doar criteriile de acceptanță C + acces cod) → `raport-faza-C.md` (P0–P3, file:line, reproducere) → remediere P0/P1 (max 3 runde) → **STOP: OK Andrei**.

## Acceptanță C (din promptul-program)
L12 + MFA live · restore real dovedit automat vizibil în UI · restore întrerupt la transport se
reconciliază (test) · conector vechi = refuz explicit · furtună = 1 alertă · e2e verzi în CI ·
mediu nou reconstruibil din repo + runbook.
