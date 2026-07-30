# Pilot simplead-backup — unde am rămas (continuăm mâine)

_Sesiune închisă: 2026-07-27, la cererea owner-ului (pauză). Reluăm de la Pasul 2a._

## Ce e gata (motorul)
Motorul V2 e complet construit, testat și pushed: branch **`feature/simplead-backup-production-ready`**
(último commit înainte de pilot: precondiții de producție cablate). **151 teste verzi**, audit de securitate
APROBAT, producția neatinsă, V2 dezactivat implicit. Vezi `PROJECT-STATUS.md`.

## Pilotul pe feaagalati.ro — progres

**Site:** `feaagalati.ro` (Site id **32** în manager), conector v2.19.0, credențiale valide.
**Storage ales pentru pilot:** Hetzner S3 (destinație #2), **prefix separat `pilot/`** (nu se amestecă cu producția).
**Container manager producție:** `manager-app-y12jcr1kywwdseq7i1cevtjv-153546785043`.

### ✅ Pas 1 — Sondaj capabilities (FĂCUT, succes)
- Pluginul `simplead-backup v0.4.0` a fost **instalat + activat manual** de owner pe feaagalati.ro.
- Sondaj (`dist/pilot-capabilities.php`, rulat de owner) = **HTTP 200**. Hosting perfect potrivit:
  **MariaDB 11.4, toate InnoDB, `consistent_snapshot_supported=true`**; PHP 8.2.31, memory_limit 512M,
  max_execution 300s; **~7,6 GB temp liber**; zip/gzip/mysqli/openssl/curl prezente; multipart + streaming gzip;
  shell_exec dezactivat (nu-l folosim). Niciun semnal de îngrijorare.

### ⬜ Pas 2a — Inventar preview (URMĂTORUL — script gata)
- Scriptul e pregătit: **`dist/pilot-inventory.php`** (POST `/files/inventory` cu `preview=true` — non-distructiv,
  doar numără fișiere + estimează dimensiunea, nu citește/urcă conținut).
- **De rulat de owner** (acțiunile care ating site-ul real sunt blocate pentru asistent — le rulează owner-ul cu `!`):
  ```
  ! docker exec -i manager-app-y12jcr1kywwdseq7i1cevtjv-153546785043 php < /opt/apps/simplead-manager/dist/pilot-inventory.php
  ```
- Ne dă: nr. fișiere, dimensiune totală, câte excluse → confirmăm că temp-ul ajunge + estimăm durata.

### ⬜ Pas 2b — Backup real (DUPĂ 2a)
- Profil **LOW_IMPACT**, scrie în Hetzner `pilot/`, cu monitorizarea că site-ul rămâne online (0× 5xx).
- **DE REZOLVAT mâine:** unde rulează `BackupRunner` (are nevoie de codul V2 + creds site + creds Hetzner + acces la
  ambele). Opțiuni: (a) din `lab-php` (are codul V2) cu creds injectate, declanșat de owner; (b) un script standalone
  care replică fluxul, rulat de owner din producție. De ales calea cea mai simplă/sigură.

### ⬜ Pas 3 — Verificare + proven-restore în SANDBOX (nu pe site-ul live)

## Reguli de siguranță (reconfirmate în practică azi)
- Fiecare acțiune care atinge feaagalati.ro sau credențialele e **blocată automat pentru asistent** → **owner-ul
  rulează scripturile** (`dist/pilot-*.php`) cu `!`. E mai sigur așa (owner controlează atingerea site-ului real).
- Rezervă rămasă: re-review al căii de credențiale înainte de a scala la mai multe site-uri.

## Cum repornim mâine laboratorul (dacă e nevoie pentru Pas 2b)
```
cd /opt/apps/simplead-manager/spike && docker compose -p sam_spike -f docker-compose.spike.yml start
cd /opt/apps/simplead-manager/lab   && docker compose -p sam_lab   -f docker-compose.lab.yml start
docker network connect sam_spike_sam_spike_net sam_lab-lab-php-1   # lab-php să vadă MinIO (dacă e nevoie)
```
Laboratorul a fost **oprit** la închiderea sesiunii (volumele păstrate).
