Vreau să construiești complet noul sistem de backup Simplead, nu doar să mai creezi un plan, un audit sau o fundație incompletă.

Obiectivul final este un modul de backup și restore complet funcțional, pregătit pentru producție, administrat central din manager.simplead.ro și instalat pe site-urile WordPress printr-un plugin separat numit:

simplead-backup

Trebuie să lucrezi autonom, să folosești agenți/subagenți specializați, să implementezi, să testezi, să identifici defectele, să corectezi și să repeți ciclul până când toate criteriile de acceptanță sunt demonstrate prin teste reproductibile.

Nu mă opri pentru a-mi cere să aleg între opțiuni tehnice pe care le poți compara prin prototipuri și măsurători.

Nu te opri după documentație.
Nu te opri după state machines.
Nu te opri după un prototip.
Nu declara proiectul finalizat până când avem un motor complet de backup și restore funcțional în laborator și staging.

CONTEXTUL PROIECTULUI

Aplicația centrală:

- domeniu: manager.simplead.ro
- repository: andreialexandrupanait/simplead-manager
- Laravel 12
- PHP 8.3
- Livewire 4
- Blade
- Tailwind CSS
- Vite
- PostgreSQL 16
- PgBouncer
- Redis 7
- Laravel Horizon
- Laravel Scheduler
- Nginx
- Gotenberg
- Docker
- Coolify
- Ubuntu 24.04

Managerul administrează:

- 24 de clienți;
- 40 de site-uri WordPress;
- programări de backup;
- backupuri existente;
- Hetzner Object Storage S3;
- Dropbox;
- notificări;
- restaurări;
- retenție;
- monitorizare.

Pluginul WordPress actual este:

Simplead Manager Connector

Acesta trebuie să rămână responsabil pentru:

- conexiunea generală cu managerul;
- autentificare;
- monitorizare;
- mentenanță;
- actualizări;
- inventar;
- securitate;
- celelalte operațiuni remote.

Noul motor de backup trebuie implementat într-un plugin separat:

simplead-backup

Pluginul trebuie să aibă:

- namespace REST propriu;
- versiune proprie;
- update propriu;
- rollback propriu;
- schema și opțiunile proprii;
- sesiuni proprii;
- loguri proprii;
- diagnostice proprii;
- state machine proprie;
- storage temporar propriu;
- independență față de connectorul principal.

Connectorul actual poate conține temporar shims de compatibilitate, dar motorul final nu trebuie să depindă de endpointurile vechi de backup.

CERINȚA PRINCIPALĂ

Site-urile clienților nu trebuie să devină indisponibile din cauza backupului.

Sistemul actual generează arhive pe serverul clientului, consumă CPU, RAM, I/O și spațiu pe disk, iar unele site-uri ajung să răspundă lent, să dea timeout sau să cadă.

Noul motor trebuie proiectat astfel încât:

- să nu genereze o arhivă completă monolitică pe serverul clientului;
- să nu țină un singur proces PHP activ pe toată durata backupului;
- să nu țină un request HTTP deschis timp îndelungat;
- să nu umple diskul clientului;
- să nu epuizeze memoria PHP;
- să nu suprasolicite CPU și disk I/O;
- să nu producă erori 500, 502 sau 503;
- să nu afecteze checkout-ul WooCommerce;
- să nu afecteze wp-admin;
- să nu afecteze frontendul;
- să poată fi întrerupt și reluat de oricâte ori este necesar;
- să se oprească sau să se suspende automat atunci când resursele site-ului sunt insuficiente.

Nu promite teoretic „zero impact”.

Demonstrează prin teste că backupul nu produce downtime și că degradarea de performanță rămâne în limitele acceptate.

Dacă limitele nu pot fi respectate pe un anumit hosting:

- backupul trebuie să se suspende;
- site-ul trebuie să rămână online;
- managerul trebuie să afișeze motivul;
- procesul trebuie să poată fi reluat ulterior;
- nu trebuie forțată continuarea până când site-ul cade.

REFERINȚĂ FUNCȚIONALĂ

Referința de funcționalitate este WPMU DEV Snapshot 4.0:

https://wpmudev.com/docs/wpmu-dev-plugins/snapshot-4-0/

Trebuie să existe paritate funcțională reală pentru funcțiile relevante:

- primul backup complet;
- backupuri incrementale ulterioare;
- backup complet manual oricând;
- backup complet periodic;
- database-only;
- files-only;
- full-site;
- backup programat;
- backup la cerere;
- alegerea conținutului;
- excluderea fișierelor;
- excluderea folderelor;
- excluderea tabelelor;
- progres;
- loguri;
- retry;
- resume;
- retention;
- protejarea backupurilor;
- download;
- export;
- restore complet;
- restore selectiv;
- verificare;
- proven restore;
- WordPress Multisite;
- administrare centrală;
- administrare locală;
- alerte;
- recuperare după întrerupere.

Nu este suficient să existe butoane cu aceste denumiri.

Fiecare funcție trebuie:

- implementată real;
- acoperită de teste;
- validată într-un restore;
- observabilă;
- reluabilă unde este aplicabil;
- documentată prin dovezi.

Poți refolosi orice cod existent care este corect, testat și robust.

Dacă o componentă existentă este fragilă sau greșită:

- elimin-o din motorul nou;
- înlocuiește-o;
- rescrie-o corect;
- păstrează un compatibility layer pentru datele vechi dacă este necesar.

Nu utiliza surse proprietare pentru care nu avem drept de utilizare, dar reproduce complet și independent comportamentul și capabilitățile public documentate.

STAREA ACTUALĂ

Sistemul de backup existent funcționează în producție și nu trebuie oprit în timpul dezvoltării.

Funcții existente care pot fi analizate și reutilizate:

- Backup;
- BackupConfig;
- StorageDestination;
- ProvenRestore;
- CreateBackup;
- CreateIncrementalBackup;
- RestoreBackup;
- ReplicateBackup;
- RetentionCleanup;
- queue backups;
- Horizon;
- multipart S3;
- presigned URLs;
- Dropbox chunk upload;
- retention chain-aware;
- sandbox restore;
- SiteOperationLock;
- endpointuri WordPress;
- dump DB;
- ZipArchive;
- prepare sessions;
- loopback;
- WP-Cron;
- restore staged;
- checksums;
- capability discovery.

Probleme deja confirmate:

- DB export inconsistent sub trafic în motorul actual;
- tabele citite pe conexiuni diferite;
- posibilitatea apariției rândurilor orfane;
- backupuri marcate complete fără manifest;
- proven restore fără rezultate reale;
- paginare instabilă;
- probleme cu date binare;
- multipart failures;
- timeout-uri;
- Cloudflare 522;
- connector 500/503;
- callbacks pierdute;
- obiecte incomplete;
- used_bytes inconsistent;
- excluderi incomplete sau nefuncționale;
- arhive locale care pot afecta site-ul.

SPIKE VALIDAT

Există deja un spike cu verdict GO.

A demonstrat:

- procesare fără arhivă monolitică;
- fișier individual de 2 GB procesat cu RAM stabilă;
- spațiu temporar controlat;
- multipart S3 real;
- reluare după întrerupere;
- restore identic la bit;
- DB consistentă pe MySQL 8;
- DB consistentă pe MariaDB 11;
- WooCommerce fără rânduri orfane;
- Multisite consistent;
- excluderi deterministe;
- producția neafectată.

Inspectează:

- branchurile existente;
- documentele din docs/backup;
- branchul de spike;
- branchul de roadmap;
- jiggly-painting-sutton.md, dacă există;
- commiturile relevante.

Integrează concluziile și codul validat din spike, dar nu muta necontrolat cod experimental în producție.

ORGANIZARE CU AGENȚI

Folosește agenți/subagenți disponibili.

Dacă platforma nu permite subagenți reali, simulează aceeași separare prin execuții și review-uri distincte.

Creează cel puțin următoarele roluri:

1. ARCHITECT AGENT

Responsabil pentru:

- arhitectura generală;
- state machines;
- protocol manager-plugin;
- model full + incremental;
- formatul backupului;
- manifest;
- chain-uri;
- compatibilitate legacy;
- decizii tehnice.

2. WORDPRESS ENGINE AGENT

Responsabil pentru:

- pluginul simplead-backup;
- inventar fișiere;
- excluderi;
- DB export;
- procesare batch;
- time budget;
- memory budget;
- disk guard;
- checkpoint;
- upload;
- restore;
- WP-Cron fallback;
- Multisite;
- shared hosting.

3. LARAVEL ORCHESTRATOR AGENT

Responsabil pentru:

- manager.simplead.ro;
- modele;
- migrări;
- joburi;
- Horizon;
- state machines;
- retries;
- heartbeats;
- scheduling;
- progress;
- retention;
- UI;
- alerts;
- quotas;
- legacy reader.

4. STORAGE AGENT

Responsabil pentru:

- Hetzner S3;
- presigned URLs;
- multipart;
- retry per part;
- upload resume;
- completion;
- checksums;
- storage accounting;
- orphan objects;
- lifecycle;
- encryption;
- cleanup.

5. RESTORE AGENT

Responsabil pentru:

- restore full;
- restore incremental chain;
- restore DB-only;
- restore files-only;
- selective restore;
- staging;
- pre-restore backup;
- maintenance;
- rollback;
- proven restore.

6. PERFORMANCE AGENT

Responsabil pentru:

- măsurarea impactului;
- CPU;
- RAM;
- disk I/O;
- free disk;
- TTFB;
- p95;
- p99;
- error rate;
- WooCommerce checkout;
- LOW IMPACT;
- NORMAL;
- FAST;
- adaptive throttling.

7. SECURITY AGENT

Responsabil pentru:

- HMAC;
- nonce;
- timestamp;
- replay protection;
- idempotency;
- presigned URLs;
- tenant isolation;
- path traversal;
- zip-slip;
- SSRF;
- symlinks;
- archive bombs;
- secret redaction;
- RBAC;
- audit;
- encryption.

8. QA / FAILURE INJECTION AGENT

Responsabil pentru:

- testele automate;
- E2E;
- kill/restart tests;
- network failures;
- S3 failures;
- callback loss;
- duplicate callbacks;
- disk full;
- memory low;
- checksum corruption;
- restore comparison;
- regression suite.

9. REVIEW AGENT

Responsabil pentru:

- code review independent;
- verificarea cerințelor;
- detectarea scurtăturilor;
- verificarea că nu există completări false;
- verificarea că site-urile nu sunt afectate;
- blocarea marcării proiectului ca finalizat dacă lipsesc dovezi.

FLUXUL AUTONOM DE LUCRU

Lucrează în cicluri:

1. Analizează situația.
2. Formulează ipoteza tehnică.
3. Implementează o variantă.
4. Rulează testele.
5. Măsoară resursele și impactul.
6. Rulează failure injection.
7. Rulează restore.
8. Compară rezultatul cu sursa.
9. Rulează review independent.
10. Identifică defectele.
11. Corectează.
12. Repetă.

Nu cere aprobarea mea între cicluri.

Continuă până când:

- toate criteriile obligatorii trec;
- backupul este restaurabil;
- procesul este reluabil;
- site-ul rămâne disponibil;
- manifestul este complet;
- storage-ul este coerent;
- review agent nu mai găsește probleme critice sau majore.

Dacă există mai multe arhitecturi posibile:

- construiește spike-uri izolate;
- măsoară;
- compară;
- alege varianta demonstrată ca fiind cea mai sigură;
- documentează decizia.

Nu alege doar pe baza preferinței personale.

Nu mă întreba dacă prefer:

- object-per-file;
- pack segments;
- TAR streaming;
- multipart;
- batch size;
- checkpoint format;
- metoda DB.

Testează opțiunile și alege varianta care trece criteriile.

Oprește-te doar dacă există un blocaj extern real:

- acces lipsă;
- credentială de test indisponibilă;
- serviciu extern indisponibil;
- limitare imposibil de simulat local.

În acel caz, raportează:

- exact ce lipsește;
- de ce este necesar;
- comanda sau acțiunea exactă;
- ce ai finalizat între timp.

ARHITECTURA ȚINTĂ

Fluxul general trebuie să fie:

MANAGER.SIMPLEAD.RO

- creează sesiunea;
- stabilește backup type;
- stabilește scope;
- stabilește exclusions;
- stabilește resource profile;
- generează joburile;
- generează presigned URLs;
- urmărește heartbeat;
- urmărește checkpoints;
- retrimite etapele eșuate;
- validează obiectele;
- validează manifestul;
- validează checksums;
- validează completion marker;
- gestionează chain-ul;
- gestionează retenția;
- orchestrează restore-ul.

SIMPLEAD-BACKUP PE WORDPRESS

- descoperă capabilitățile hostingului;
- inventariază fișierele și baza;
- aplică excluderile înainte de citire;
- procesează în etape scurte;
- folosește time budget;
- folosește memory budget;
- folosește disk guard;
- produce streamuri sau segmente limitate;
- urcă direct către Hetzner S3;
- salvează checkpoint;
- trimite heartbeat;
- poate fi oprit;
- poate fi reluat;
- nu păstrează credentiale S3 permanente;
- nu construiește arhivă completă pe disk.

HETZNER S3

- primește obiectele;
- primește segmentele;
- folosește multipart;
- permite resume;
- stochează manifest;
- stochează checksums;
- stochează DB dump;
- stochează tombstones;
- stochează chain metadata;
- stochează completion marker;
- devine sursa finală a backupului.

FULL BACKUP

Trebuie să existe full backup real.

Primul backup al unui site trebuie să fie full.

Un utilizator trebuie să poată declanșa manual un full oricând.

Schedulerul trebuie să poată genera full periodic.

Tipuri obligatorii:

- Full site;
- Incremental;
- Database only;
- Files only.

Full site trebuie să poată include:

- dump complet DB;
- WordPress core;
- uploads;
- plugins;
- themes;
- mu-plugins;
- wp-config.php dacă politica permite;
- directoare custom;
- fișiere custom;
- tabele custom;
- metadata;
- manifest;
- checksums;
- completion marker.

Full backup trebuie să fie restaurabil independent.

Un full nou poate porni un chain incremental nou.

Un full nu poate fi șters dacă are incrementale dependente.

INCREMENTAL

Strategia inițială aprobată:

- dump DB complet la fiecare backup;
- fișiere incrementale;
- fișiere noi;
- fișiere modificate;
- tombstones pentru fișiere șterse;
- full base obligatoriu;
- chain ordonat;
- manifest per incremental;
- checksums;
- completion marker.

Nu denumi dumpul complet „incremental DB”.

Nu implementa binlog sau WAL în prima versiune de producție.

Dacă spike-ul demonstrează o îmbunătățire sigură și portabilă pentru DB, documentează și implementează doar dacă funcționează pe shared hosting, MySQL 8, MariaDB și WooCommerce.

CONSISTENȚA BAZEI DE DATE

Exportul DB trebuie să reprezinte un snapshot consistent.

Trebuie să funcționeze pe:

- MySQL 8;
- MariaDB 10/11;
- WordPress;
- WooCommerce sub trafic;
- Multisite.

Reutilizează metoda validată în spike dacă trece review-ul.

Trebuie demonstrat că după restore există:

- zero comenzi orfane;
- zero order items orfane;
- zero metadata lipsă;
- zero relații rupte;
- zero tabele lipsă;
- date binare corecte;
- serialized data corectă.

Dacă exportul consistent nu poate fi obținut pe un hosting:

- backupul DB nu trebuie marcat completed;
- sistemul trebuie să raporteze limitarea;
- files backup poate continua doar dacă politica permite;
- site-ul nu trebuie pus în pericol.

FĂRĂ ARHIVĂ MONOLITICĂ

Nu crea o singură arhivă ZIP de dimensiunea întregului site.

Compară prin benchmark:

- object-per-file;
- segmente TAR/pack streaming;
- segmente compresate limitate;
- multipart per segment.

Alege varianta care oferă:

- impact minim;
- restore eficient;
- număr rezonabil de obiecte S3;
- reluare per segment;
- checksum;
- streaming;
- temp space redus;
- suport pentru fișiere foarte mari.

Un segment trebuie să poată fi refăcut independent.

Nu trebuie refăcut întregul backup dacă un segment eșuează.

RELUARE DUPĂ ÎNTRERUPERE

Trebuie să existe:

- backup session persistentă;
- restore session persistentă;
- stage;
- cursor;
- checkpoint;
- heartbeat;
- attempt;
- idempotency key;
- confirmed object list;
- confirmed part list;
- retry count;
- next retry;
- cancellation;
- pause;
- resume.

Dacă procesul moare:

- nu reîncepe de la zero;
- reia de la ultimul checkpoint confirmat;
- nu dublează obiectele;
- nu dublează chunkurile;
- nu dublează callbackurile;
- nu avansează manifestul înainte de confirmare;
- abandonează controlat multipartul invalid;
- poate inițializa un multipart nou numai pentru obiectul afectat.

Testează cel puțin:

- PHP kill;
- container restart;
- manager worker restart;
- Redis restart;
- conexiune S3 întreruptă;
- presigned URL expirat;
- callback pierdut;
- callback duplicat;
- același job executat de două ori;
- 10 întreruperi consecutive;
- 100 întreruperi într-un test extins.

EXCLUDERI

Trebuie implementate complet.

Niveluri:

- global;
- plan de mentenanță;
- client;
- site;
- schedule;
- backup manual.

Tipuri:

- folder exact;
- fișier exact;
- glob;
- extensie;
- prefix;
- dimensiune;
- vârstă;
- tabel exact;
- prefix de tabel;
- include-only;
- exclude.

Exemple:

- wp-content/cache/**
- wp-content/wflogs/**
- wp-content/ai1wm-backups/**
- wp-content/updraft/**
- wp-content/upgrade/**
- **/node_modules/**
- **/.git/**
- *.log
- *.tmp
- *.bak
- error_log
- debug.log

Cerințe:

- preview înainte de backup;
- număr estimat de fișiere;
- dimensiune estimată;
- validarea regulilor;
- path normalization;
- path traversal protection;
- regulile salvate în manifest;
- exclusion_policy_hash;
- fișierele excluse nu sunt citite inutil;
- fișierele excluse nu sunt urcate;
- fișierele excluse nu produc tombstones;
- modificarea majoră a scope-ului forțează full nou;
- restore-ul respectă scope-ul;
- nu permite excluderea accidentală a întregului site fără confirmare.

PROFILE DE RESURSE

Trebuie să existe:

LOW IMPACT

Pentru shared hosting și site-uri sensibile:

- batchuri mici;
- o singură operațiune activă;
- time budget scurt;
- pauze adaptive;
- memory budget strict;
- disk budget strict;
- suspendare la load ridicat;
- prioritate pentru frontend și checkout.

NORMAL

- echilibru între viteză și impact;
- batchuri medii;
- upload controlat;
- concurență limitată.

FAST

- numai pentru VPS/dedicat;
- batchuri mai mari;
- concurență controlată;
- trebuie activat explicit.

Nu hardcoda arbitrar limitele finale.

Stabilește valorile prin benchmark și capability discovery.

Folosește limite relative la:

- max_execution_time;
- memory_limit;
- free disk;
- load average;
- CPU;
- I/O;
- response latency.

Regulă de siguranță:

- dacă pragurile sunt depășite, procesul se suspendă;
- nu continuă agresiv;
- site-ul rămâne prioritar.

CRITERII DE IMPACT

În testele de trafic:

- zero erori 500/502/503 provocate de backup;
- zero OOM;
- zero disk full;
- zero blocaje checkout;
- zero restarturi PHP provocate de backup;
- zero pierderi de date;
- zero backupuri complete false.

Măsoară:

- baseline fără backup;
- p50;
- p95;
- p99;
- throughput;
- error rate;
- CPU;
- RAM;
- I/O;
- free disk;
- PHP workers;
- checkout duration.

LOW IMPACT trebuie să aibă impact minim măsurabil.

Dacă p95, p99 sau error rate depășesc bugetul definit de Performance Agent:

- micșorează batchurile;
- crește pauza;
- reduce concurența;
- suspendă;
- reia ulterior.

MANIFEST ȘI COMPLETION

Un backup nu poate fi completed fără:

- manifest valid;
- completion marker valid;
- toate obiectele;
- toate segmentele;
- toate părțile multipart;
- toate dimensiunile;
- toate checksums;
- DB validation;
- chain validation;
- full base valid;
- scope hash;
- exclusion policy hash;
- format version.

Manifestul trebuie să includă:

- format_version;
- engine_version;
- backup_type;
- scope;
- included paths;
- excluded paths;
- included tables;
- excluded tables;
- full_base_id;
- chain_position;
- objects;
- chunks;
- tombstones;
- checksums;
- sizes;
- WordPress version;
- PHP version;
- plugin version;
- DB engine;
- started_at;
- completed_at;
- completion marker reference.

S3 completion marker trebuie scris ultimul.

RESTORE

Trebuie implementat și demonstrat:

- full restore;
- incremental chain restore;
- DB-only;
- files-only;
- plugins;
- themes;
- uploads;
- core;
- custom directories;
- selective tables;
- selective files/folders;
- restore pe site original;
- restore în sandbox;
- pre-restore safety backup;
- rollback;
- resume după întrerupere.

Moduri:

SAFE MERGE

- restaurează obiectele selectate;
- nu șterge alte fișiere locale;
- risc redus.

EXACT / MIRROR

- reproduce exact starea backupului;
- aplică tombstones;
- șterge obiectele care nu existau;
- necesită confirmare suplimentară;
- necesită pre-restore backup.

Restore-ul nu trebuie să țină site-ul în maintenance pe toată durata downloadului.

Flux recomandat:

- download în staging;
- verificare;
- pregătire;
- maintenance numai pentru etapa finală critică;
- swap atomic unde este posibil;
- validare;
- ieșire din maintenance;
- rollback dacă validarea eșuează.

LEGACY BACKUPS

Strategia este HYBRID LEGACY.

Nu șterge nimic.

Clasificări:

- valid;
- verification_required;
- recoverable;
- incomplete;
- orphaned;
- unknown;
- quarantined;
- invalid.

Backupurile vechi trebuie:

- afișate read-only;
- descărcabile dacă obiectele există;
- restaurabile numai după verification gate;
- marcate legacy;
- păstrate dacă sunt protected;
- păstrate dacă sunt ultimul full valid;
- păstrate dacă susțin un chain.

Nu modifica obiectele legacy.

Nu executa cleanup fără aprobarea mea.

UI MANAGER

Construiește complet interfața în stilul manager.simplead.ro.

Global:

- backup health;
- active sessions;
- failed sessions;
- paused sessions;
- stale sessions;
- destination health;
- storage usage;
- quotas;
- orphan objects;
- incomplete backups;
- proven restore;
- latest restores;
- alerts.

Per site:

- Backup now;
- Full backup;
- Incremental backup;
- Database only;
- Files only;
- schedule;
- resource profile;
- scope;
- exclusions;
- preview;
- estimate;
- history;
- chains;
- progress;
- logs;
- verify;
- download;
- restore;
- retry;
- resume;
- pause;
- cancel;
- protect;
- delete;
- storage destination.

Backup detail:

- state;
- stage;
- progress;
- heartbeat;
- attempts;
- duration;
- resource profile;
- full/incremental;
- full base;
- chain;
- manifest;
- objects;
- checksums;
- storage;
- errors;
- verification;
- restore options.

UI WORDPRESS

Pluginul simplead-backup trebuie să aibă o interfață locală minimalistă:

- connection status;
- manager status;
- plugin version;
- capabilities;
- current backup;
- current restore;
- progress;
- heartbeat;
- temp usage;
- disk space;
- last operations;
- logs;
- WP-Cron status;
- loopback status;
- diagnostics;
- manual backup dacă politica permite;
- pause/cancel dacă politica permite;
- support package fără secrete.

SECURITATE

Implementează și testează:

- HTTPS;
- HMAC-SHA256;
- timestamp;
- nonce;
- replay protection;
- idempotency;
- short-lived presigned URLs;
- fără credentiale S3 permanente în WordPress;
- tenant isolation;
- RBAC;
- 2FA pentru restore;
- audit trail;
- secret redaction;
- path traversal protection;
- zip-slip protection;
- symlink policy;
- SSRF protection;
- archive bomb protection;
- maximum extraction size;
- maximum file count;
- checksum SHA-256;
- S3 server-side encryption suportată;
- application-level encryption opțională dacă trece bugetele de performanță;
- key rotation strategy;
- GDPR retention;
- storage în regiune UE.

TEST LAB

Construiește un laborator reproductibil cu Docker.

Include:

- WordPress single site;
- WooCommerce;
- Multisite;
- MySQL 8;
- MariaDB 11;
- S3 emulator;
- trafic artificial;
- fișiere generate;
- DB fixtures;
- storage fixtures;
- restore targets.

Scenarii minime:

SITE MIC

- 5.000 fișiere;
- DB 100 MB.

SITE MEDIU

- 100.000 fișiere;
- DB 2 GB.

SITE MARE

- minimum 500.000 fișiere;
- DB 10 GB;
- fișiere individuale de 1–5 GB.

Dacă resursele locale nu permit toate dimensiunile simultan:

- folosește teste parametrizate;
- teste de streaming sparse;
- benchmarkuri reproductibile;
- rulează testul maxim posibil;
- nu pretinde că o dimensiune netestată a trecut.

TESTE OBLIGATORII

Unit:

- state machines;
- manifests;
- checksums;
- exclusions;
- tombstones;
- chain resolution;
- retention;
- idempotency;
- retries;
- completion rules;
- errors;
- classification;
- quotas.

Integration:

- full backup;
- incremental backup;
- DB-only;
- files-only;
- S3 multipart;
- interrupted upload;
- resume;
- duplicate callbacks;
- expired URL;
- missing chunk;
- bad checksum;
- bad manifest;
- missing full base;
- legacy reader;
- selective restore;
- full restore;
- mirror restore;
- safe merge.

E2E:

- WordPress;
- WooCommerce;
- Multisite;
- shared-hosting constraints;
- low memory;
- low disk;
- disabled WP-Cron;
- failed loopback;
- WAF simulation;
- security plugin;
- large file;
- many small files;
- active checkout traffic.

Failure injection:

- kill PHP;
- kill WordPress container;
- kill manager worker;
- restart Horizon;
- restart Redis;
- restart MySQL;
- disconnect S3;
- return S3 500;
- expire URL;
- lose callback;
- duplicate callback;
- duplicate job;
- corrupt chunk;
- delete chunk;
- disk full;
- memory pressure;
- high CPU;
- high I/O.

Restore verification:

- file count;
- file hashes;
- DB row counts;
- DB constraints;
- WooCommerce order relations;
- serialized data;
- users;
- media;
- plugins;
- themes;
- Multisite blogs;
- frontend;
- wp-admin;
- checkout;
- proven restore record.

DEFINITION OF DONE

Nu considera proiectul finalizat doar pentru că:

- codul compilează;
- testele unitare trec;
- backupul se încarcă în S3;
- UI-ul există;
- backupul are status completed.

Proiectul este finalizat numai dacă:

1. Pluginul simplead-backup este complet și instalabil.
2. Managerul orchestrează complet operațiunile.
3. Full backup funcționează.
4. Incremental backup funcționează.
5. DB-only funcționează.
6. Files-only funcționează.
7. Excluderile funcționează.
8. Resume funcționează după întreruperi repetate.
9. Nu se creează arhivă monolitică.
10. Storage temporar este plafonat.
11. Hetzner S3 multipart este robust.
12. Manifestul este obligatoriu.
13. Completion marker este obligatoriu.
14. Checksums sunt validate.
15. Restore complet funcționează.
16. Restore selectiv funcționează.
17. Incremental chain restore funcționează.
18. Rollback funcționează.
19. Proven restore produce rezultate reale.
20. WooCommerce DB este consistentă.
21. Multisite este consistent.
22. Site-ul rămâne online în testele de trafic.
23. Nu apar erori 500/502/503 provocate de backup.
24. Nu apare OOM.
25. Nu apare disk full.
26. Review Agent nu raportează probleme critice sau majore nerezolvate.
27. Toate limitările rămase sunt documentate clar.
28. CI este verde.
29. ZIP-ul pluginului este generat și verificat.
30. Avem un plan de rollout și rollback testat.

GIT

Inspectează branchurile existente și creează un branch coerent de implementare, de exemplu:

feature/simplead-backup-production-ready

Acesta trebuie să pornească din baza care conține:

- roadmapul;
- fundația;
- spike-ul validat;
- documentația relevantă.

Nu pierde istoricul existent.

Folosește commituri logice pe faze.

Nu face merge în main.

Nu face deploy în producție.

Nu distribui pluginul către site-urile clienților.

PROTECȚIA PRODUCȚIEI

Pe parcursul construcției:

- motorul existent rămâne activ;
- noul motor este dezactivat implicit;
- feature flags false;
- schedulerul nou inactiv;
- Horizon nu procesează cozi V2 în producție;
- nu se schimbă backupurile existente;
- nu se scrie în bucketul production;
- nu se folosesc credentiale production în teste;
- nu se pornesc restore-uri pe site-uri reale;
- nu se fac requesturi mutante către site-urile clienților;
- nu se modifică retention production;
- nu se șterge nimic.

Feature flags minime:

BACKUP_ENGINE_V2_ENABLED=false
BACKUP_ENGINE_V2_SITE_IDS=
BACKUP_ENGINE_V2_SCHEDULER_ENABLED=false
BACKUP_ENGINE_V2_RESTORE_ENABLED=false
BACKUP_ENGINE_V2_PROVEN_RESTORE_ENABLED=false
BACKUP_LEGACY_RESTORE_ENABLED=false
BACKUP_RECONCILIATION_WRITES_ENABLED=false

ROLLOUT

Pregătește, dar nu executa fără aprobarea mea:

1. laborator;
2. staging intern;
3. site intern sau necritic;
4. un singur site pilot;
5. grup 3 site-uri;
6. grup 10 site-uri;
7. 25%;
8. 50%;
9. 100%.

Fiecare etapă trebuie să aibă:

- health gates;
- performance gates;
- backup verification;
- restore verification;
- rollback;
- comparație cu motorul vechi;
- raport.

Nu porni pilotul real până nu îți răspund exact:

DA PILOT BACKUP V2

LIVRABILE OBLIGATORII

Trebuie să livrezi:

- cod Laravel complet;
- pluginul simplead-backup complet;
- migrări;
- modele;
- servicii;
- joburi;
- comenzi;
- state machines;
- UI Manager;
- UI WordPress;
- integrare Hetzner S3;
- backup full;
- backup incremental;
- restore;
- retention;
- legacy reader;
- reconciliation;
- proven restore;
- notificări;
- quotas;
- test lab;
- fixture-uri;
- teste;
- CI;
- benchmarkuri;
- failure injection reports;
- security review;
- performance review;
- rollout runbook;
- rollback runbook;
- plugin ZIP;
- checksum SHA-256;
- release notes.

Documente:

docs/backup/PROJECT-STATUS.md
docs/backup/FINAL-ARCHITECTURE.md
docs/backup/FORMAT-SPECIFICATION.md
docs/backup/PLUGIN-PROTOCOL.md
docs/backup/RESOURCE-PROFILES.md
docs/backup/EXCLUSIONS.md
docs/backup/FULL-BACKUP.md
docs/backup/INCREMENTAL-BACKUP.md
docs/backup/RESTORE.md
docs/backup/LEGACY-COMPATIBILITY.md
docs/backup/SECURITY-REVIEW.md
docs/backup/PERFORMANCE-RESULTS.md
docs/backup/FAILURE-INJECTION-RESULTS.md
docs/backup/TEST-EVIDENCE.md
docs/backup/ROLLOUT-RUNBOOK.md
docs/backup/ROLLBACK-RUNBOOK.md
docs/backup/KNOWN-LIMITATIONS.md
docs/backup/DECISION-LOG.md

RAPORTAREA PROGRESULUI

Nu îmi trimite rapoarte după fiecare schimbare mică.

Lucrează autonom și actualizează în repository:

docs/backup/PROJECT-STATUS.md

La finalul fiecărei faze interne:

- fă commit;
- rulează toate testele relevante;
- rulează review;
- continuă automat dacă nu există probleme critice.

Oprește-te și raportează doar când:

- modulul complet a trecut toate criteriile;
- este pregătit pentru pilot;
- există un blocaj extern real;
- ai descoperit că o cerință este imposibilă și ai dovezi măsurate.

RAPORT FINAL

Răspunde în limba română, strict cu:

1. Verdict: READY FOR PILOT / BLOCKED
2. Branch
3. Commituri
4. Pull Request / compare URL
5. Arhitectura finală aleasă
6. Agenții folosiți
7. Ciclurile de implementare și corectare
8. Plugin simplead-backup
9. Calea ZIP
10. SHA-256
11. Manager Laravel
12. Full backup
13. Incremental backup
14. Database-only
15. Files-only
16. Excluderi
17. Resume și checkpoint
18. Hetzner S3 multipart
19. Manifest și completion marker
20. Restore complet
21. Restore selectiv
22. Restore incremental chain
23. Rollback
24. Proven restore
25. Legacy compatibility
26. Retention
27. UI Manager
28. UI WordPress
29. Securitate
30. Testele executate
31. Testele trecute
32. Testele eșuate
33. Failure injection
34. Rezultatele MySQL
35. Rezultatele MariaDB
36. Rezultatele WooCommerce
37. Rezultatele Multisite
38. Test site mic
39. Test site mediu
40. Test site mare
41. RAM maxim utilizat
42. Disk temporar maxim utilizat
43. CPU
44. Impact frontend p95/p99
45. Erori 500/502/503 provocate de backup
46. Rezultatele restore și hash comparison
47. Probleme critice rămase
48. Probleme majore rămase
49. Limitări cunoscute
50. Confirmarea că producția nu a fost modificată
51. Confirmarea că site-urile clienților nu au fost folosite
52. Confirmarea că nu s-a scris sau șters nimic în S3/Dropbox production
53. Confirmarea că motorul V2 este dezactivat implicit
54. Instrucțiunea unică necesară pentru următorul pas:
    DA PILOT BACKUP V2