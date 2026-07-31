# Audit: motorul de backup V1 vs V2

_31 iulie 2026. Date din producție (`manager.simplead.ro`) + cod pe `main` (`617fdd1`)._

> **Metodă.** Fiecare afirmație e ori măsurată în producție (interogări pe baza live), ori citită în
> cod cu `fișier:linie`. Unde repo-ul are deja o măsurătoare proprie, e citată ca atare. Nu s-a
> estimat nimic „din ochi". Anexa de la final spune de unde vine fiecare cifră.

## Verdictul, pe scurt

Cele două motoare sunt bune la lucruri **complementare**, iar niciunul nu e bun singur.

- **V1 are produsul, nu are corectitudinea.** Interfața, retenția, replicarea, alertarea, restore-ul
  dovedit — toate există și sunt folosite zilnic pe 40 de site-uri. Dar dump-ul lui de bază de date
  e **greșit prin construcție**, iar asta nu se peticește.
- **V2 are corectitudinea, nu are produsul.** Dump consistent tranzacțional, checksum per obiect,
  reluare după întrerupere, restore cu rollback. Dar e invizibil în interfață, nu alertează la eșec,
  nu are retenție funcțională, nu are replicare și merge doar pe S3.
- **Niciunul nu criptează backup-urile clienților.**

Recomandarea, în două cuvinte: **fața lui V1, motorul lui V2**.

---

## Partea 1 — V1 (motorul curent)

### Cifre din producție, ultimele 30 de zile

| | |
|---|---|
| Backup-uri rulate | 601 |
| Reușite | **544 (90,5%)** |
| Durată medie | 296 s |
| Site-uri cu backup activat | 24 din 40 |
| Site-uri fără backup reușit de >3 zile | **5** |
| Formate scrise | `v3-zip` 534 · `direct-s3` 9 · (`v2-zip` = valoarea implicită pe rândurile eșuate) |
| Backup-uri incrementale rulate | **0** |

### ✅ Ce are V1 și chiar funcționează

**Un produs complet, integrat.** Liste per site și pe flotă, progres live, badge-uri de stare, note
editabile, blocare împotriva retenției, istoric — toate în paginile pe care le folosești deja.

**Alertare pe eșec care chiar pleacă.** `app/Observers/BackupObserver.php:46` se agață de tranziția
coloanei `backups.status`; `NotifyBackupFailed` (`:120`) trimite Slack + email. Verificat în producție:
`notification_logs` are `backup_failed` trimise pe 25, 29, 30 și 31 iulie. Același observer
sincronizează `Site.backup_ok`, `Site.last_backup_at` și `BackupConfig.last_backup_*`.

**Retenție cu noțiune de lanț.** `RetentionService` tratează un full + incrementalii lui ca unitate,
politici `count` sau `days`, nu rupe niciodată un lanț la mijloc. Are deja și cod pentru backup-uri
din mai multe obiecte (`deleteMultipartPrefix`, `:332`) — manifest-driven, cu fallback pe listare.

**Replicare 3-2-1.** `ReplicateBackup` copiază pe o destinație secundară, per fișier, **cu verificare
sha256** față de manifest, urcând manifestul ultimul ca o replică parțială să fie detectabilă.

**Restore dovedit în producție**, inclusiv fallback pe replică dacă primarul lipsește
(`RestoreBackup.php:276-320`) — singurul motor care a restaurat vreodată ceva real aici.

**Cinci destinații de stocare**: local, Dropbox, S3, B2, Hetzner.

**Gardă de spațiu pe managerul propriu** (`DiskSpaceGuard`) + auto-retry pentru backup-uri blocate.

### ❌ Ce nu merge la V1

#### 1. Dump-ul bazei de date e inconsistent — prin construcție, nu din întâmplare

Fluxul chunked face **o cerere HTTP separată per chunk**
(`app/Services/WordPressBackupDownloader.php:144`), iar `startAsyncExec()` (`:456`) le și
pipeline-uiește, deci chunk-uri diferite rulează concurent. Pe partea WordPress, fiecare cerere e alt
proces PHP-FPM cu **altă conexiune MySQL** (`class-backup-endpoint.php:1346`). Nu există nicăieri
`START TRANSACTION`, `FLUSH TABLES WITH READ LOCK` sau `LOCK TABLES`.

Un snapshot consistent ține **doar în interiorul unei conexiuni**. Deci chunk-urile sunt din momente
diferite.

Repo-ul are deja măsurătoarea (`docs/backup/spike/DATABASE-CONSISTENCY.md`, MySQL 8, 20k comenzi,
writer concurent):

| Metodă | Rânduri orfane |
|---|---|
| Conectorul V1 (paginare, fără tranzacție) | **342** |
| `mysqldump --single-transaction` | 0 |
| O singură `START TRANSACTION WITH CONSISTENT SNAPSHOT` | 0 |

Al doilea defect, independent: paginarea e `SELECT * FROM t LIMIT offset, n` **fără `ORDER BY`**
(`:2196`), iar tabelele mari se sparg în sub-chunk-uri dimensionate din `INFORMATION_SCHEMA.TABLE_ROWS`
— o **estimare**. Se pot duplica sau pierde rânduri chiar și fără scrieri concurente.

**Ce înseamnă practic:** pe un WooCommerce sub trafic, un restore V1 poate produce comenzi cu poziții
lipsă. Nu e ipotetic — e măsurat.

#### 2. Un eșec la mijloc pierde tot backup-ul

`florinpasat.com` nu are backup reușit **de 8 zile**: pluginul rămâne fără memorie la chunk-ul 3 din
70 (`Allowed memory size of 536870912 bytes exhausted`, `class-backup-endpoint.php:2211`). Cauza e
igienă de memorie în plugin — `$wpdb->get_results()` cu batch fix de 500 de rânduri și niciun
`$wpdb->flush()`. Nu există reluare de la chunk-ul căzut: se reia totul de la zero, în fiecare noapte.

#### 3. Retenția nu șterge nimic

`backups.retention_dry_run = true` în producție. Rezultatul măsurat: backup-uri din **12 martie** încă
în bază, 1448 de rânduri, **1,18 TB pe Hetzner** — deși politicile sunt configurate (17 site-uri la 30
de zile, 7 site-uri la 7–10 bucăți). Politica există, nu se aplică.

#### 4. Download-ul e stricat pentru backup-urile multi-obiect

`WithBackupActions.php:284` presemnează `file_path` fără branch pe format; pentru `multipart-v3` asta
e un prefix, deci link mort. Butonul se afișează pentru orice backup `completed`. **Bug activ azi**,
nu unul viitor.

#### 5. Incrementalele nu se folosesc

0 rulări în 30 de zile, deși codul există.

#### 6. Cinci site-uri fără protecție reală

florinpasat.com (8 zile), ehvac.ro (7 zile), feaa.ugal.ro (niciodată), plus simplead.ro (51 de zile)
și selectsoft.ro (niciodată) — acestea două deconectate.

---

## Partea 2 — V2 (motorul nou)

### Cifre din producție

O singură rulare reală, pe feaagalati.ro (31 iulie, 17:06):

| | |
|---|---|
| Rezultat | `completed` în **1m 47s** |
| Obiecte | 13 · **451,5 MB** |
| Structură | 5 × `files/chunk_N.zip` + 4 × `database/chunk_N.sql.gz` + manifest/checksums/metadata/`_COMPLETE` |
| Snapshot consistent | **true** (MariaDB 11.4, 38 tabele, 30.736 rânduri) |
| Verificare la creare | `passed` — 9/9 obiecte, zero lipsă, zero nepotriviri |
| Impact pe site | 200 / 70 ms la 36 s după finalizare; zero 5xx |

Comparativ, V1 pe același site: 447 MB în 76–83 s. Dimensiune practic identică, V2 cu ~30% mai lent —
dar profilul e „low impact", deci nu e o regresie.

### ✅ Ce are V2 și V1 nu are

**Dump consistent tranzacțional.** `class-consistent-dumper.php:110` —
`SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT
SNAPSHOT`, într-o singură conexiune. Și e **onest**: dacă depășește bugetul de timp, raportează
`consistent=false` în loc să pretindă. Ăsta e singurul motiv serios pentru care V2 există.

**Integritate verificabilă.** sha256 per obiect, `manifest.json` + `checksums.json`, iar `_COMPLETE`
se scrie **ultimul**, după ce prezența celorlalte e confirmată — deci un backup incomplet nu poate
arăta ca unul întreg.

**Reluare după întrerupere.** Upload multipart cu reluare, testat cu injectare de erori. Un chunk
căzut nu aruncă tot backup-ul.

**Restore mai bogat** — moduri MIRROR și SAFE_MERGE, restore selectiv, rollback la eșec, backup de
siguranță înainte de restore. Complet scris.

**Backup doar de fișiere** — V1 n-are.

**Buget de timp trimis pluginului** (90 s per pas, segmente de 8 MB), cu reluare din cursor. De asta
site-ul a rămas la 70 ms tot timpul.

### ❌ Ce nu are V2

1. **Nu alertează la eșec. Deloc.** Nu există nicio clasă de notificare pentru V2. Mai rău:
   `RunBackupSessionJob` are `tries = 1` și **nicio metodă `failed()`**, iar `BackupRunner::run()`
   prinde doar `CorruptBackupException`. Orice altă excepție lasă sesiunea blocată într-o stare
   intermediară, fără tranziție la `failed`. **Un backup V2 care pică e complet tăcut.**
2. **Retenția e de două ori moartă.** `ChainRetentionService` n-are niciun apelant în `app/`; și dacă
   ar avea, `isExpired()` cere `expires_at`, care nu e setat nicăieri. Backup-urile V2 s-ar acumula la
   infinit.
3. **Nu replică nimic.** Regula 3-2-1 nu se aplică.
4. **Doar S3.** `S3ClientFactory::forDestination()` aruncă pentru orice altceva — site-urile pe
   Dropbox sau local nu pot rula V2 deloc.
5. **Nu se poate descărca un backup.** Nicio metodă, nicio rută.
6. **Invizibil în interfața normală.** `backup_sessions.backup_id` nu e populat niciodată, deci un
   backup V2 nu apare pe pagina care există ca să-ți arate backup-urile site-ului.
7. **Profilul de resurse e decorativ.** Cele șase butoane — `step_seconds`, `file_batch`, `pause_ms`,
   `memory_budget_mb`, `max_concurrency`, `min_free_disk_mb` — au **zero apariții în `app/`**.
   `low_impact` și `fast` produc exact același comportament; diferența e un șir în `metadata.json`.
8. **Nicio gardă de spațiu pe serverul clientului.** Pluginul *raportează* spațiul liber
   (`capabilities.storage.free_bytes`), dar `capabilityCheck()` nu-l citește. `min_free_disk_mb: 512`
   e config mort. Backup-ul ar umple discul clientului și ar pica pe la mijloc.
9. **Nicio verificare de precondiții.** Dacă pluginul lipsește, afli din eroarea HTTP, în timpul
   rulării.
10. **Incrementalele nu sunt cablate pe calea de producție.** Motorul există, dar
    `RunBackupSessionJob` nu-i pasează niciodată baza — deci fiecare „incremental" ar fi un full.
11. **`deep-verify` e doar de laborator — și marchează backup-uri bune ca eșuate.**
    `DeepVerifyCommand.php:47` folosește `S3ClientFactory::lab()` hardcodat. Rulat pe producție, scrie
    un rând `failed` împotriva unui backup perfect valid.
12. **Butonul „verify" minte.** `verifySession()` cheamă `markVerified()`, care doar pune
    `verified_at = now()` fără să verifice nimic.
13. **Nu există scheduler.** `scheduler_enabled` din config n-are niciun consumator.
14. **Pluginul e pe 1 din 40 de site-uri**, fără mecanism de instalare în masă.
15. **Restore-ul n-a rulat niciodată în producție** și e în spatele unui flag oprit.

---

## Partea 3 — Ce lipsește AMÂNDURORA

Astea nu sunt argumente pentru un motor sau altul. Sunt găuri comune.

**1. Backup-urile clienților nu sunt criptate.** Nici V1, nici V2. `BACKUP_ENCRYPTION_KEY` e folosită
într-un singur loc, `DatabaseDumpCommand.php:145` — dump-ul bazei **managerului**, nu al site-urilor.
Backup-urile clienților stau în clar pe Hetzner și Dropbox: baze de date întregi, cu date personale și
comenzi. Pentru V2 e recunoscut explicit (`RestoreRunner::decrypt()` e un no-op documentat); pentru V1
nu e menționat nicăieri. **Ăsta e cel mai serios lucru din audit.**

**2. „Proven restore" nu rulează niciunde.** Zero rânduri în `proven_restores`, la ambele motoare.
Nimeni n-a dovedit vreodată automat că un backup se poate restaura.

**3. Nicio oprire automată dacă site-ul începe să dea erori** în timpul backup-ului. Verificarea
impactului e manuală.

**4. Nicio verificare de precondiții pe serverul clientului** — spațiu, memorie, versiune de PHP —
înainte de a începe.

**5. Nicio vizualizare unică „ce site-uri sunt de fapt protejate?"** care să nu depindă de motor.

---

## Partea 4 — Ce ar trebui să avem în plus

În ordinea în care le-aș face, după cât de mult rău previn per unitate de efort:

| # | Ce | De ce acum |
|---|---|---|
| 1 | **Criptare la repaus pentru backup-urile clienților** | Date personale în clar la un terț. Singurul punct din audit cu implicații legale, nu doar operaționale. |
| 2 | **Gardă de precondiții** (spațiu + memorie), înainte de start | Pluginul deja trimite cifrele. Exact asta a doborât florinpasat 8 nopți la rând. |
| 3 | **Alertare pe eșec pentru V2** + `failed()` pe job | Fără ea, orice site trecut pe V2 devine invizibil când pică. Precondiție pentru orice rollout. |
| 4 | **Retenția aprinsă și pe V1** (`retention_dry_run=false`), după un ciclu de observare a logurilor | 1,18 TB unde politica zice ~324 GB. Se plătește lunar. |
| 5 | **Igiena de memorie în conectorul V1** (`$wpdb->flush()`, batch adaptiv) | Repară florinpasat fără să aștepte V2. Câteva linii în plugin. |
| 6 | **Cablarea profilului de resurse** | Ca `low_impact` să însemne ceva. Azi e o etichetă. |
| 7 | **Proven restore care chiar rulează** | Un backup nedovedit e o presupunere. Cere cablajul de sandbox care lipsește. |
| 8 | **Download pentru backup-uri multi-obiect** | Repară și bug-ul V1 existent pe `multipart-v3`. |

---

## Partea 5 — Recomandarea

**Nu alege între ele. Păstrează fața lui V1 peste motorul lui V2.**

Stratul de produs al lui V1 e ~90% agnostic la forma stocării: interfața nu citește niciodată
`file_path` sau numărul de obiecte, alertarea se agață doar de tranziția coloanei `status`, iar
retenția și replicarea **au deja** cod pentru backup-uri din mai multe obiecte.

Puntea a fost proiectată de la început și nu a fost niciodată cablată — `backup_sessions.backup_id`,
cu acest comentariu în migrare:

> „links back to a V1 `backups` row when one exists so the two engines can coexist during migration"

Nu e însă o schimbare de câteva linii. Există trei capcane tăcute care trebuie închise **înainte** ca
primul backup V2 să apară în listele V1:

- **`BackupDispatcher::recoverStuckBackups()` nu filtrează pe format.** Rulează din minut în minut și
  ia orice rând `in_progress` mai vechi de 20 de minute → ar porni motorul V1 peste o sesiune V2 în
  curs, pe site-ul live. Un backup V2 „low impact" depășește banal 20 de minute.
- **Ștergerea ar lăsa obiectele orfane, tăcut.** `DeleteObject` pe un prefix inexistent e succes în
  S3: rândul dispare, `used_bytes` scade, iar cele 13 obiecte rămân în bucket pentru totdeauna.
- **Prefixul obiectelor se mută în clipa în care legi cele două tabele.** Se calculează din
  `backup_id ?? id` (`RunBackupSessionJob.php:90`, `RunRestoreSessionJob.php:99`,
  `PreRestoreSafetyBackup.php:59`). Iar `DeepVerifyCommand.php:118` folosește deja `$session->id`
  inconsecvent — azi inofensiv, din prima zi a punții nu.

---

## Anexă — de unde vine fiecare cifră

| Afirmație | Sursă |
|---|---|
| 544/601 = 90,5%, 296 s medie, 0 incrementale | interogare pe `backups`, 30 zile, 31 iul 2026 |
| 342 rânduri orfane | `docs/backup/spike/DATABASE-CONSISTENCY.md` (măsurătoare proprie a repo-ului) |
| 1,18 TB · 1448 rânduri · cel mai vechi 12 mar | `storage_destinations.used_bytes` + `backups` |
| `retention_dry_run = true` | `config('backups.retention_dry_run')` în containerul de producție |
| florinpasat: 8 zile, OOM la chunk 3/70 | `backups` + `storage/logs/laravel-2026-07-31.log` |
| Backup V2: 13 obiecte, 451,5 MB, 1m47s | sesiunea #2 + listare `ListObjectsV2` pe Hetzner |
| Impact pe site: 200 / 70 ms | `uptime_checks`, monitor 31, 17:09:15 |
| 6 butoane de profil cu 0 apariții | `grep` pe `app/`, per cheie |
| Criptarea acoperă doar managerul | `grep BACKUP_ENCRYPTION_KEY` → un singur consumator |
