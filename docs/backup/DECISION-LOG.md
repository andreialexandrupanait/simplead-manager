# simplead-backup — DECISION LOG

> Fiecare decizie tehnică semnificativă, cu justificare și (unde e cazul) măsurători din laborator.
> Alegerile între opțiuni tehnice se fac prin prototip + benchmark, nu prin preferință.

## D-001 — Bază de branch unică (snapshot-parity docs + spike code)
**Decizie:** `feature/simplead-backup-production-ready` = `feature/snapshot-parity-backup-engine`
(design docs) + merge `spike/simplead-backup-architecture-validation` (cod validat + docs/backup/spike).
**De ce:** roadmap-ul și dovezile spike stăteau pe două branch-uri diferite; construcția are nevoie
de ambele la un loc. main e ancestor al snapshot-parity (merge curat, fără atingerea codului de producție).
**Măsurători:** n/a.

## D-002 — Izolare V2 prin config dedicat + flag-uri default false
**Decizie:** `config/backup_v2.php` separat de `config/backups.php`; toate flag-urile de activare = false.
**De ce:** blast radius zero pe motorul V1 live; V2 complet inert în producție cu default-urile.
**Măsurători:** n/a.

## D-003 — Laravel: țintim versiunea reală din repo (^12.0)
**Decizie:** `composer.json` are `laravel/framework: ^12.0` → construim pe Laravel 12 (nu 11 cum
zicea vechiul CLAUDE.md). Fără upgrade de framework în această muncă.
**De ce:** evităm să combinăm un upgrade de framework cu construcția motorului.

## Decizii deschise (se închid prin benchmark în fazele următoare)
- **D-004 (P2) — Format de segment/chunk:** object-per-file vs pack/TAR-stream vs segmente
  compresate limitate vs multipart-per-segment. Criterii: impact minim, restore eficient, număr
  rezonabil de obiecte S3, reluare per segment, checksum, streaming, temp redus, fișiere foarte mari.
- **D-005 (P2) — Dimensiune parte multipart + TTL presigned:** din benchmark de throughput/rată de eșec.
- **D-006 (P2) — Metodă dump DB consistent portabilă:** confirmă metoda din spike (single-connection
  `START TRANSACTION WITH CONSISTENT SNAPSHOT`) pe MySQL 8 + MariaDB 10/11 + Woo + Multisite, întărită
  cu ORDER BY stabil și hex pentru binar. Fără binlog/WAL în v1.
- **D-007 (P1/P2) — Profile de resurse:** valorile finale LOW/NORMAL/FAST din capability discovery +
  benchmark de latență, nu hardcodate.
