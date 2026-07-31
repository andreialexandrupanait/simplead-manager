# C-08 — Proven restore (plan de arhitectură)

**Scop:** dovedim automat, săptămânal, că backup-urile sunt CU ADEVĂRAT restaurabile — nu doar
integre (asta o face deja `BackupVerifier` offline), ci restaurate într-un WordPress viu izolat și
verificate (homepage 200, login, coerență DB cu manifestul). Badge „ultimul restore dovedit" per
site + alertă la eșec. Rulează pe **site-urile de test**: notificarialimente.ro, universulsacru.ro.

## Constrângere-cheie & arhitectura aleasă
Containerul Manager e **read-only** și **nu poate rula `docker exec`**. Deci sandbox-ul NU e driven
prin docker din Manager. În schimb:

- **Sandbox = un WordPress containerizat pe dasher, cu conectorul SAM instalat**, înregistrat ca un
  „site" intern în Manager (`sites.is_sandbox = true`, cu `api_key`/`api_secret` proprii).
- Proven restore = **restaurăm backup-ul unui site de test ÎN sandbox** folosind exact transportul
  de restore existent (staged `/backup/restore` către conectorul sandbox-ului), apoi îl verificăm
  prin HTTP + conector. Un restore staged complet suprascrie tot → sandbox-ul se resetează natural
  la fiecare rulare.
- Izolare: rețea Docker separată `sandbox`, fără expunere publică; DB proprie (MariaDB) throwaway.

## Componente (val 1 — engine)
1. **compose** (`docker-compose.prod.yml`): `sandbox-wp` (wordpress) + `sandbox-db` (mariadb) pe
   rețeaua `sandbox`, cu conectorul montat. **Andrei provizionează pe dasher** + înregistrează
   site-ul sandbox (activare + cheie API) — pas de deploy documentat.
2. **migrare**: `sites.is_sandbox` + `sites.proven_restore_enabled` (bool, default false); tabel
   `proven_restores` (site_id, backup_id nullable, status `passed`/`failed`, checks jsonb, error,
   ran_at).
3. **model** `ProvenRestore` + relații pe `Site` (`provenRestores`, `latestProvenRestore`).
4. **serviciu** `SandboxRestoreService`:
   - `restoreInto(Site $sandbox, Backup $backup)`: materializează arhiva (download din storage cu
     fallback pe replică, ca `BackupVerifier`), o trimite la conectorul sandbox-ului prin staged
     `/backup/restore`.
   - `runHealthChecks(Site $sandbox, Backup $backup): array` — homepage 200, `/wp-login.php` 200,
     `runDiagnostic()` loopback pe conectorul sandbox, coerență DB vs manifest. Întoarce
     `['passed'=>bool, 'checks'=>[...]]`.
5. **job** `RunProvenRestore` (coadă `backups`): alege site-ul `proven_restore_enabled` cu cel mai
   vechi „ultimul restore dovedit" (rotație) → ultimul backup completat → sandbox → restoreInto +
   runHealthChecks → înregistrează `ProvenRestore` → alertă la eșec (`NotificationService`).
6. **schedule**: săptămânal în `routes/console.php`.
7. **UI**: badge „ultimul restore dovedit" pe `SiteBackups` (per site) — verde/roșu + „acum X".
8. **teste**: orchestrarea jobului + evaluarea health-checks (serviciul mock-uit); logica de
   health-check din serviciu cu conector fake + `Http::fake` + storage fake.

## Ce NU e în val 1 (follow-up)
Validarea reală pe dasher (aducerea containerelor sus + înregistrarea site-ului sandbox) = pas de
deploy al lui Andrei. Global dashboard badge + istoricul proven-restore în UI = val 2.

## Acceptanță C-08
Restore real dovedit automat vizibil în UI (badge per site) + alertă la eșec + rotație pe site-urile
de test. (Rularea live cap-coadă pe dasher se confirmă după provizionarea sandbox-ului.)
