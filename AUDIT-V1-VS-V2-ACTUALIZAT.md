# Audit și decizie finală: sistemul de backup pentru `manager.simplead.ro`

_Actualizat la 31 iulie 2026 pe baza auditului V1 vs V2, a datelor din producție și a cerințelor finale de produs._

> **Scopul documentului**
>
> Acest document înlocuiește recomandarea inițială acolo unde cerințele au fost clarificate. Ținta nu
> este construirea unei noi infrastructuri complete de disaster recovery, ci realizarea celui de-al
> patrulea backup al site-urilor administrate: sigur, cu impact minim asupra clientului, stocat exclusiv
> în Hetzner S3 și restaurabil direct sau printr-un pachet portabil descărcabil.

---

## 1. Cerința finală

Sistemul trebuie să ofere, pentru fiecare site:

1. backup complet al fișierelor și bazei de date;
2. opțional, backup incremental între backupurile complete;
3. alegerea strategiei din interfața Manager;
4. stocare exclusivă în Hetzner S3 Object Storage;
5. zero arhive complete construite pe serverul clientului;
6. consum controlat de CPU, memorie, I/O și spațiu temporar;
7. suspendare automată dacă site-ul clientului se degradează;
8. verificarea integrității fiecărui backup;
9. reluarea unei rulări întrerupte fără reluarea completă de la zero;
10. retenție corectă pentru backupuri full și lanțuri incrementale;
11. restaurare directă controlată, cu backup de siguranță și rollback;
12. generarea unui pachet portabil pentru descărcare și restaurare manuală;
13. alerte reale și vizibilitate clară asupra site-urilor protejate;
14. criptarea datelor stocate în S3.

### Decizie de produs

Utilizatorul trebuie să poată selecta una dintre următoarele strategii:

- **Numai backup full**;
- **Backup full + incremental**.

Valoarea implicită recomandată pentru majoritatea site-urilor este:

- full săptămânal;
- incremental zilnic;
- dump complet și consistent al bazei de date la fiecare punct de restaurare;
- retenție de patru lanțuri complete.

Pentru site-uri mici poate fi ales full zilnic. Pentru site-uri mari, full săptămânal și incremental
zilnic reduc transferul și spațiul ocupat fără a complica inutil restaurarea bazei de date.

---

## 2. Verdictul revizuit asupra V1 și V2

Cele două motoare rămân complementare, dar recomandarea este ajustată cerințelor finale.

### V1

V1 are produsul utilizabil:

- interfață;
- scheduler;
- istoric;
- progres;
- alerte;
- retenție cu noțiune de lanț;
- integrare cu site-urile și configurațiile existente;
- restore folosit în producție.

V1 nu poate rămâne motorul pentru baza de date, deoarece dumpul chunked este inconsistent prin
construcție. Cererile HTTP diferite folosesc conexiuni MySQL diferite și citesc datele în momente
diferite. Testul existent în repository a produs 342 de rânduri orfane pentru metoda V1 și zero
pentru un snapshot tranzacțional consistent.

### V2

V2 are fundația tehnică potrivită:

- snapshot tranzacțional consistent;
- obiecte multiple, mici și verificabile;
- checksum SHA-256;
- manifest;
- marker `_COMPLETE` scris ultimul;
- reluare după întrerupere;
- restore cu rollback;
- impact redus demonstrat într-o rulare reală.

V2 nu este încă un produs complet:

- nu alertează corect la eșec;
- nu marchează sigur toate excepțiile ca `failed`;
- retenția nu este conectată la fluxul de producție;
- incrementalele nu sunt conectate la jobul real;
- nu apare corect în interfața normală;
- downloadul nu există;
- profilurile de resurse nu controlează motorul;
- nu există un circuit breaker automat;
- restore-ul V2 nu este încă dovedit în producție.

### Corecții față de auditul inițial

Următoarele nu mai sunt considerate defecte pentru produsul final:

- faptul că V2 funcționează numai cu S3;
- lipsa Dropbox, B2 și stocării locale;
- lipsa replicării către o destinație secundară în cadrul modulului.

Motivul este că acest sistem reprezintă deja al patrulea backup. Produsul final va folosi exclusiv
**Hetzner S3 Object Storage**. Nu trebuie adăugată o a doua infrastructură de replicare în interiorul
acestui modul.

### Recomandarea finală

> **Interfața și orchestrarea matură din V1 peste motorul corect și reluabil din V2, cu suport real
> pentru full și incremental, exclusiv pe Hetzner S3.**

V1 nu mai trebuie extins ca motor de creare a backupului. V2 trebuie finalizat, simplificat și legat
corect de produsul existent.

---

## 3. Situația măsurată în producție

### V1 — ultimele 30 de zile

| Indicator | Valoare |
|---|---:|
| Backupuri rulate | 601 |
| Backupuri reușite | **544 — 90,5%** |
| Durată medie | 296 secunde |
| Site-uri cu backup activat | 24 din 40 |
| Site-uri fără backup reușit de peste 3 zile | **5** |
| Backupuri incrementale rulate | **0** |
| Spațiu raportat pe Hetzner | **1,18 TB** |
| Cel mai vechi backup păstrat | 12 martie 2026 |

### V2 — rularea reală din 31 iulie 2026

| Indicator | Valoare |
|---|---:|
| Site testat | `feaagalati.ro` |
| Rezultat | `completed` |
| Durată | 1 minut și 47 de secunde |
| Dimensiune | 451,5 MB |
| Obiecte | 13 |
| Snapshot DB consistent | `true` |
| Tabele / rânduri | 38 / 30.736 |
| Integritate la creare | 9/9 obiecte valide |
| Erori HTTP 5xx observate | 0 |
| Timp de răspuns observat după finalizare | 70 ms |

V2 a fost aproximativ 30% mai lent decât V1 pe același site, însă diferența este acceptabilă dacă
motorul păstrează site-ul disponibil și produce date corecte.

---

## 4. Arhitectura finală

### 4.1 Componente

Sistemul final are trei componente clare:

1. **Managerul Laravel**
   - programare și orchestrare;
   - coadă de joburi;
   - configurare;
   - stare și progres;
   - alerte;
   - retenție;
   - verificare;
   - restaurare;
   - generarea pachetului portabil;
   - acces la Hetzner S3.

2. **Pluginul WordPress**
   - preflight local;
   - inventarierea fișierelor;
   - citirea și împachetarea fișierelor în chunkuri mici;
   - dump consistent al bazei de date;
   - curățarea imediată a fișierelor temporare;
   - raportarea resurselor și a stării site-ului;
   - restore local controlat.

3. **Hetzner S3 Object Storage**
   - unica destinație a produsului;
   - obiecte criptate;
   - manifest și checksumuri;
   - pachete portabile temporare;
   - linkuri presemnate cu expirare.

### 4.2 Principiu de siguranță

> **Backupul trebuie să se oprească înainte ca site-ul să fie afectat.**

Sistemul nu poate promite impact matematic zero pe un hosting deja suprasolicitat, dar trebuie să
poată detecta riscul, să reducă viteza, să pună jobul în pauză sau să îl oprească fără a compromite
site-ul.

---

## 5. Formatul backupului

Fiecare punct de restaurare are un prefix propriu în S3:

```text
sites/{site_uuid}/backups/{backup_uuid}/
├── manifest.json
├── checksums.json
├── metadata.json
├── database/
│   ├── chunk_000001.sql.gz.enc
│   ├── chunk_000002.sql.gz.enc
│   └── ...
├── files/
│   ├── chunk_000001.zip.enc
│   ├── chunk_000002.zip.enc
│   └── ...
├── deleted-files.json.enc       # numai la incremental
└── _COMPLETE
```

### Reguli obligatorii

- `backup_uuid` este generat o singură dată și nu se schimbă după pornire;
- prefixul S3 nu depinde de ID-uri care se pot modifica la legarea V1–V2;
- `manifest.json` descrie toate obiectele, dimensiunile și rolurile lor;
- `checksums.json` conține SHA-256 pentru conținutul final stocat;
- `_COMPLETE` este scris ultimul;
- un backup fără `_COMPLETE` nu este afișat ca restaurabil;
- markerul nu este scris dacă lipsește un obiect sau un checksum nu corespunde;
- obiectele temporare/incomplete au termen de expirare și sunt curățate separat.

### Stări permise

```text
queued
preflight
running_database
running_files
uploading
verifying
completed
paused
failed
cancelled
expired
```

Tranzițiile trebuie validate. Nu se permite trecerea directă la `completed` fără verificare și fără
markerul `_COMPLETE` confirmat în S3.

---

## 6. Strategiile selectabile

### 6.1 Numai full

La fiecare rulare se salvează:

- toate fișierele incluse;
- dump complet și consistent al bazei de date;
- inventarul complet al fișierelor;
- manifest, checksumuri și metadate.

Este strategia cea mai simplă și potrivită pentru site-uri mici sau pentru utilizatorii care preferă
independența totală a fiecărui punct de restaurare.

### 6.2 Full + incremental

Un lanț este format din:

```text
FULL
├── INCREMENTAL 1
├── INCREMENTAL 2
├── INCREMENTAL 3
└── ...
```

Backupul incremental conține:

- fișiere noi;
- fișiere modificate;
- lista fișierelor șterse;
- dump complet și consistent al bazei de date pentru acel punct de restaurare;
- legătura către fullul de bază și către punctul anterior;
- manifest și checksumuri proprii.

### De ce baza de date rămâne completă la fiecare incremental

Un incremental generic al bazei de date WordPress nu poate fi garantat pe toate hostingurile:

- nu toate tabelele au coloane de modificare;
- pluginurile folosesc scheme diferite;
- accesul la MySQL binlog nu este garantat;
- ștergerile sunt greu de reconstruit corect;
- restaurarea din multe diferențe mărește riscul operațional.

Prin urmare, termenul „incremental” se aplică fișierelor. La fiecare punct de restaurare se păstrează
un dump complet, consistent și independent al bazei de date. Aceasta este regula implicită și sigură
a produsului.

### Setări UI

```text
Strategie:
( ) Numai full
(•) Full + incremental

Backup full:
Frecvență: săptămânal
Zi: duminică
Oră: 02:00

Backup incremental:
Frecvență: zilnic
Oră: 03:00

Lanț maxim:
7 puncte incrementale

Retenție:
4 lanțuri complete
```

Utilizatorul poate modifica strategia, frecvența, ziua, ora, lungimea lanțului și retenția.

### Reguli de schimbare a strategiei

- trecerea de la `full_only` la `full_incremental` forțează un full nou;
- schimbarea listei de excluderi forțează un full nou;
- schimbarea versiunii majore a formatului forțează un full nou;
- pierderea sau coruperea bazei lanțului forțează un full nou;
- un incremental nu pornește dacă fullul de bază nu este `completed` și verificat;
- un lanț nu continuă după atingerea limitei configurate;
- dacă un incremental eșuează, următoarea rulare poate relua acel punct sau poate forța un full,
  conform erorii și politicii de recuperare.

---

## 7. Detectarea fișierelor incrementale

Fullul creează un inventar persistent:

```json
{
  "path": "wp-content/uploads/2026/07/image.jpg",
  "size": 482193,
  "mtime": 1785520123,
  "sha256": "..."
}
```

La incremental:

1. se scanează arborele de fișiere în loturi;
2. se compară `path`, `size` și `mtime` cu inventarul precedent;
3. pentru candidații modificați se calculează SHA-256 în streaming;
4. se încarcă numai fișierele noi sau cu hash diferit;
5. căile existente în manifestul precedent, dar absente acum, sunt scrise în `deleted-files.json`;
6. se generează un inventar nou pentru următorul punct.

Fullul periodic reface baza de comparație și limitează riscul acumulării erorilor de inventar.

Nu se încarcă din nou un fișier dacă hashul său este identic, chiar dacă metadatele s-au schimbat.

---

## 8. Dumpul bazei de date

### Cerință nenegociabilă

Dumpul trebuie să provină dintr-un singur snapshot consistent:

```sql
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
START TRANSACTION WITH CONSISTENT SNAPSHOT;
```

Toate tabelele compatibile trebuie citite prin aceeași conexiune. Chunkurile sunt segmente ale
fluxului de ieșire, nu cereri independente cu conexiuni diferite.

### Reguli

- fără paginare `LIMIT offset` nesortată;
- fără snapshoturi amestecate între cereri HTTP;
- fără declararea backupului ca valid dacă `consistent=false`;
- fără downgrade tăcut la metoda V1;
- memorie constantă prin citire nebufferizată sau batch adaptiv;
- eliberarea explicită a rezultatelor și `$wpdb->flush()` unde este relevant;
- compresie în streaming;
- chunkurile temporare se șterg imediat după confirmarea uploadului;
- tabelele non-tranzacționale se raportează explicit în manifest;
- dacă nu se poate produce un snapshot sigur în limitele mediului, backupul eșuează controlat și
  alertează; nu se pretinde că este consistent.

### Preflight DB

Înainte de pornire se verifică:

- versiunea MySQL/MariaDB;
- lista motoarelor de stocare ale tabelelor;
- dimensiunea estimată a bazei;
- memoria disponibilă;
- timpul maxim de execuție;
- capabilitatea motorului de a menține conexiunea necesară;
- spațiul temporar minim pentru un singur chunk.

---

## 9. Protejarea site-ului clientului

### 9.1 Preflight obligatoriu

Backupul nu începe până când nu sunt validate:

- plugin instalat și versiune compatibilă;
- autentificare și semnătură valide;
- spațiu liber peste pragul calculat;
- memorie PHP suficientă;
- directoare temporare scriibile;
- acces la fișierele WordPress;
- acces la baza de date;
- răspuns HTTP normal;
- absența altui backup/restore activ;
- starea Hetzner S3 și permisiunile necesare;
- configurația și cheia de criptare;
- capacitatea de a curăța fișierele temporare.

Eșecul de preflight produce un mesaj precis, de exemplu:

```text
PRECHECK_DISK_SPACE_LOW
Necesari: 768 MB
Disponibili: 412 MB
Backupul nu a fost pornit.
```

### 9.2 Limite reale de resurse

Profilul `low_impact` trebuie să controleze efectiv:

- dimensiunea chunkului;
- numărul de fișiere pe lot;
- memoria maximă țintă;
- timpul maxim al unui pas;
- pauza dintre loturi;
- nivelul compresiei;
- concurența pe site;
- concurența pe server/IP;
- concurența globală.

Configurație implicită orientativă:

```text
max_concurrency_per_site = 1
max_concurrency_per_origin = 1
max_concurrency_global = 2
file_chunk_target = 8–32 MB, adaptiv
compression_level = redus/moderat
pause_between_batches = adaptiv
```

Valorile finale trebuie calibrate prin testele de încărcare, nu lăsate ca simple etichete în UI.

### 9.3 Spațiu temporar

Pe client se permite simultan numai:

- chunkul aflat în procesare;
- o marjă controlată pentru operațiunea curentă.

Nu se creează niciodată o arhivă completă a site-ului. După confirmarea checksumului obiectului în S3,
chunkul local este șters imediat.

### 9.4 Circuit breaker

Managerul verifică periodic:

- codul HTTP;
- timpul de răspuns;
- erorile 5xx;
- time-outurile;
- memoria disponibilă;
- spațiul liber;
- erorile PHP fatale;
- semnalele de throttling sau overload raportate de plugin.

Acțiuni:

1. reduce ritmul;
2. mărește pauza dintre loturi;
3. pune sesiunea în `paused`;
4. oprește controlat sesiunea și o marchează `failed` dacă riscul persistă.

Pragurile trebuie să folosească o bază de referință măsurată înainte de backup, nu doar valori fixe.

---

## 10. Upload, reluare și idempotență

Fiecare pas trebuie să fie idempotent.

- același job poate fi reluat fără duplicarea obiectelor logice;
- fiecare chunk are un ID stabil;
- uploadul multipart se reia din părțile confirmate;
- managerul verifică existența, dimensiunea și checksumul înainte de a reîncărca;
- o excepție marchează sesiunea `failed` prin metoda `failed()` și printr-un mecanism de reconciliere;
- un reconciler periodic identifică sesiunile blocate, fără a porni motorul V1 peste V2;
- retry-ul folosește backoff și limită de încercări;
- erorile nerecuperabile nu sunt reluate automat la infinit.

### Clasificarea erorilor

```text
RETRYABLE
- timeout tranzitoriu
- întrerupere S3
- eroare rețea
- throttling

PAUSABLE
- degradare site
- încărcare crescută
- spațiu apropiat de limită

FATAL
- checksum diferit repetat
- cheie de criptare invalidă
- snapshot DB inconsistent
- manifest incompatibil
- autentificare invalidă
```

---

## 11. Criptare

Backupurile clienților conțin baze de date, date personale, comenzi și fișiere private. Ele nu trebuie
stocate în clar.

### Cerințe

- criptare înainte de stocarea obiectului;
- cheie de date unică pentru fiecare backup sau lanț;
- cheia de date este protejată cu o cheie principală păstrată numai în Manager;
- cheia principală nu se salvează în bucket;
- nonce/IV unic pentru fiecare obiect;
- autentificarea criptografică a conținutului;
- rotația cheii principale fără reîncărcarea tuturor datelor, prin reîmpachetarea cheilor de date;
- restore-ul refuză obiectele care nu pot fi autentificate;
- checksumul și manifestul disting clar hashul conținutului criptat de identificatorul conținutului
  logic.

Pierderea cheii principale trebuie tratată ca incident critic, deoarece backupurile nu mai pot fi
restaurate.

---

## 12. Retenție

### Politici suportate

- număr de fulluri;
- număr de lanțuri complete;
- număr de zile;
- combinație de backupuri zilnice, săptămânale și lunare.

### Regula de bază

Un full și incrementalele dependente sunt o singură unitate de retenție.

Sistemul nu poate:

- șterge fullul dacă există incrementale păstrate;
- păstra un incremental fără baza lui;
- raporta spațiul eliberat înainte de confirmarea ștergerii obiectelor;
- considera succes un `DeleteObject` aplicat accidental pe un prefix greșit.

### Flux de ștergere

1. se încarcă manifestul lanțului;
2. se construiește lista exactă de obiecte;
3. se șterg obiectele;
4. se verifică absența lor;
5. se marchează backupul `expired`/`deleted`;
6. se actualizează spațiul utilizat din date reale, nu prin scădere optimistă.

Înainte de activare, retenția rulează un ciclu de simulare și produce un raport explicit. După
validarea raportului, `retention_dry_run` trebuie dezactivat intenționat.

### Implicit recomandat

- full săptămânal;
- incremental zilnic;
- maximum 7 incrementale într-un lanț;
- 4 lanțuri complete păstrate.

---

## 13. Download și pachet portabil

Backupul multi-obiect nu trebuie expus printr-un link către prefixul S3.

Butonul **Generează pachet descărcabil** pornește un job pe Manager:

1. validează `_COMPLETE`;
2. verifică manifestul și checksumurile;
3. descarcă/stream-uiește obiectele în ordinea corectă;
4. decriptează;
5. reconstruiește starea fișierelor pentru punctul ales;
6. folosește dumpul DB aferent punctului ales;
7. creează o arhivă portabilă fără a folosi serverul clientului;
8. încarcă arhiva temporară în Hetzner S3;
9. oferă un URL presemnat cu expirare;
10. șterge automat pachetul după expirare.

Conținut recomandat:

```text
portable-backup-{domain}-{date}.zip
├── site-files.zip
├── database.sql
├── restore-instructions.txt
├── manifest.json
├── checksums.json
└── metadata.json
```

Pentru backupurile foarte mari, arhiva se generează în streaming către un upload multipart S3,
fără a necesita spațiu local egal cu dimensiunea totală.

Acest pachet garantează că backupul poate fi descărcat și reinstalat manual chiar dacă restaurarea
automată nu este disponibilă într-un caz particular.

---

## 14. Restaurarea directă

### Moduri

- restaurare completă;
- numai baza de date;
- numai fișierele;
- restaurare selectivă de directoare, după ce fluxul complet este dovedit.

### Flux obligatoriu

1. preflight de restore;
2. verificarea completă a lanțului;
3. backup de siguranță al stării curente;
4. descărcarea și decriptarea într-o zonă temporară;
5. reconstruirea fișierelor pentru punctul selectat;
6. validarea arhivei și a dumpului;
7. activarea mentenanței numai pentru etapa finală;
8. importul DB și schimbarea controlată a fișierelor;
9. rescrierea URL-urilor numai dacă este necesar și explicit configurat;
10. golirea cacheurilor;
11. teste automate de sănătate;
12. ieșirea din mentenanță;
13. rollback automat dacă validarea finală eșuează.

### Observație de disponibilitate

Crearea backupului trebuie să fie fără downtime. Restaurarea completă poate necesita o fereastră
scurtă de mentenanță pentru a evita divergența dintre fișiere și baza de date în momentul comutării.

### Restore din incremental

Pentru restaurarea unui punct incremental:

1. se pornește de la fullul de bază;
2. se aplică, în ordine, modificările fișierelor din fiecare incremental până la punctul ales;
3. se aplică listele de ștergeri;
4. se folosește dumpul complet al bazei de date din punctul ales.

Dacă lipsește orice element al lanțului, restaurarea este blocată. Nu se încearcă o restaurare
„best effort”.

---

## 15. Integrarea V1–V2

`backup_sessions.backup_id` trebuie folosit ca legătură de produs, dar identitatea stocării nu trebuie
recalculată după această legare.

### Probleme care trebuie închise înainte de integrare

1. `BackupDispatcher::recoverStuckBackups()` trebuie să filtreze explicit motorul și formatul;
2. reconcilerul V2 trebuie separat de retry-ul V1;
3. prefixul S3 trebuie bazat pe `backup_uuid` imutabil;
4. toate comenzile de verify, restore și safety backup trebuie să folosească aceeași funcție unică de
   rezolvare a prefixului;
5. ștergerea trebuie să fie manifest-driven;
6. rândul V1 nu se șterge înainte de confirmarea ștergerii obiectelor;
7. starea sesiunii V2 trebuie sincronizată în obiectul vizibil în interfața V1;
8. observerul de alerte trebuie să primească toate tranzițiile V2;
9. `backup_id` trebuie populat tranzacțional pentru a evita sesiuni invizibile;
10. comenzile de laborator nu trebuie să poată modifica starea backupurilor din producție.

### O singură sursă de adevăr

Interfața poate continua să folosească modelul `Backup`, însă detaliile tehnice ale execuției trebuie
să vină din `BackupSession`. Nu trebuie să existe două stări contradictorii pentru aceeași rulare.

---

## 16. Scheduler și concurență

Schedulerul trebuie să fie real, nu doar configurat.

### Reguli

- un backup activ per site;
- un backup activ per origine/server atunci când mai multe site-uri împart resursele;
- concurență globală configurabilă;
- orele sunt distribuite cu jitter pentru a evita pornirea simultană;
- restore-ul are prioritate și blochează backupul aceluiași site;
- un full nou nu pornește peste un incremental activ;
- joburile vechi sunt reconciliate înainte de lansarea altora;
- timezone-ul configurat este aplicat consecvent;
- schimbarea programului nu dublează joburile deja planificate.

---

## 17. Alerte și observabilitate

### Evenimente minime

- backup eșuat;
- backup blocat;
- preflight eșuat;
- site degradat în timpul backupului;
- retenție eșuată;
- verificare eșuată;
- restore eșuat;
- rollback executat;
- site fără backup valid de X zile;
- spațiu S3 peste prag;
- cheie de criptare/configurație invalidă.

### Informații în alertă

- site;
- backup/session UUID;
- etapă;
- cod de eroare stabil;
- mesaj pentru operator;
- date tehnice relevante;
- număr de retry-uri;
- link direct către sesiune;
- acțiunea recomandată.

### Dashboard unic

Trebuie să existe o vedere de flotă cu:

- ultimul backup valid;
- ultimul full;
- ultimul incremental;
- puncte de restaurare disponibile;
- vârsta ultimului backup;
- strategie activă;
- următoarea rulare;
- starea lanțului;
- verificare;
- restore dovedit;
- spațiu ocupat;
- motivul exact pentru site-urile neprotejate.

---

## 18. Verificare și „proven restore”

Butonul **Verify** nu poate doar să seteze `verified_at`.

### Verificare standard

- existența `_COMPLETE`;
- parsarea manifestului;
- existența tuturor obiectelor;
- dimensiunea corectă;
- checksumul corect;
- autenticitatea criptografică;
- consistența referințelor de lanț;
- existența fullului de bază;
- validarea sintactică a dumpului DB;
- testarea deschiderii arhivelor.

### Proven restore

Un backup este marcat `restore_proven` numai după o restaurare reală într-un mediu izolat și după
verificări automate:

- WordPress pornește;
- baza de date poate fi accesată;
- pagina principală răspunde;
- `wp-admin` răspunde;
- numărul de tabele este valid;
- fișierele esențiale există;
- nu există erori fatale;
- checksumurile fișierelor reconstruite sunt corecte.

Restore-ul dovedit trebuie rulat periodic pe eșantioane și obligatoriu înainte de activarea generală
a restore-ului direct.

---

## 19. Excluderi

Excluderile implicite trebuie să fie prudente și configurabile:

```text
wp-content/cache/
wp-content/upgrade/
wp-content/wflogs/
wp-content/ai1wm-backups/
wp-content/updraft/
wp-content/backups/
*.log
error_log
.git/
node_modules/
```

Nu se exclud implicit:

- `wp-content/uploads`;
- teme;
- pluginuri;
- `wp-config.php`;
- `.htaccess`;
- fișierele custom ale site-ului.

Modificarea excluderilor invalidează baza incrementală și forțează un full nou.

---

## 20. Model minim de date

Câmpurile pot fi adaptate convențiilor repository-ului, dar conceptele trebuie să existe.

### `backups`

```text
id
uuid
site_id
strategy                 full | incremental
engine                   v2
format_version
status
base_backup_id           nullable
previous_backup_id       nullable
restore_point_at
started_at
completed_at
verified_at
restore_proven_at
logical_bytes
stored_bytes
object_count
error_code
error_message
metadata
```

### `backup_sessions`

```text
id
uuid
backup_id
phase
cursor
attempt
heartbeat_at
paused_reason
resource_snapshot
error_context
started_at
finished_at
```

### `backup_objects`

```text
id
backup_id
object_key
role
sequence
plain_size
stored_size
sha256
cipher
nonce
upload_id
etag
status
```

### `backup_file_inventory`

```text
backup_id
path
size
mtime
sha256
state
```

Constrângerile și indexurile trebuie să împiedice:

- duplicate de `uuid`;
- două obiecte cu același `backup_id + role + sequence`;
- două sesiuni active pentru același site;
- incremental fără `base_backup_id`;
- legături între site-uri diferite;
- ștergerea accidentală a unui full cu incrementale păstrate.

---

## 21. Calitatea codului și regulile de implementare

Nu se acceptă ca „funcțional” doar pentru că un happy path rulează o dată.

### Cerințe de cod

- `declare(strict_types=1)` unde proiectul și versiunea PHP permit;
- servicii mici, cu responsabilitate clară;
- interfețe pentru storage, criptare, clock și transport;
- dependency injection, fără instanțieri ascunse în business logic;
- tranzacții DB pentru schimbările de stare;
- state machine explicită;
- idempotency keys;
- excepții tipizate și coduri de eroare stabile;
- fără `catch (Throwable)` care înghite eroarea;
- toate joburile au `failed()` și reconciliation;
- loguri structurate, fără secrete și fără chei de criptare;
- timeouts explicite pentru HTTP și S3;
- backoff cu jitter;
- validare strictă a manifestului;
- aceeași funcție pentru calcularea cheilor S3;
- feature flags pentru rollout și restore;
- migrații reversibile și compatibile cu datele existente;
- niciun buton UI care pretinde o acțiune neexecutată;
- nicio setare care nu este consumată efectiv de motor.

### Principiu

> Orice stare afișată în UI trebuie să poată fi demonstrată din date și din obiectele S3.

---

## 22. Teste obligatorii

### 22.1 Unit tests

- tranziții de stare;
- calcularea lanțului;
- retenție;
- rezolvarea prefixului S3;
- validarea manifestului;
- criptare/decriptare;
- inventar fișiere;
- fișiere create/modificate/șterse;
- clasificarea erorilor;
- calcularea programărilor;
- protecția fullurilor cu incrementale dependente.

### 22.2 Integration tests

- upload multipart și reluare;
- pierderea conexiunii la fiecare etapă;
- checksum greșit;
- obiect lipsă;
- marker `_COMPLETE` lipsă;
- S3 răspunde 429/500;
- dublă livrare a aceluiași job;
- worker omorât după fiecare fază;
- cleanup obiecte incomplete;
- retenție pe un lanț complet;
- generarea pachetului portabil;
- restore full;
- restore incremental;
- rollback după import DB eșuat;
- alertare la fiecare tip de eșec.

### 22.3 Teste de consistență DB

- WooCommerce cu scrieri concurente;
- comenzi și order items;
- actualizări și ștergeri în timpul dumpului;
- tabele mari;
- chei primare compuse;
- tabele fără cheie primară;
- tabele non-InnoDB raportate corect;
- dumpul restaurat fără rânduri orfane produse de metodă.

### 22.4 Teste de impact

- site mic;
- site mediu;
- WooCommerce activ;
- shared hosting cu memorie redusă;
- disc aproape plin;
- latență mare;
- site care începe să răspundă lent;
- eroare fatală simulată;
- mai multe site-uri pe aceeași origine.

Se măsoară înainte, în timpul și după backup:

- p50/p95/p99 response time;
- coduri 5xx;
- CPU;
- memorie;
- I/O;
- spațiu temporar;
- durată;
- bytes transferați.

### 22.5 Teste de restore înainte de producție

Minim:

1. site WordPress mic;
2. site cu multe fișiere;
3. WooCommerce activ;
4. full direct;
5. incremental din mijlocul lanțului;
6. ultimul incremental;
7. rollback forțat;
8. restaurare manuală din pachetul descărcat.

---

## 23. Criterii de acceptare

Motorul poate fi declarat gata numai dacă toate sunt adevărate:

- [ ] folosește exclusiv Hetzner S3;
- [ ] oferă `full_only` și `full_incremental` selectabile;
- [ ] dumpul DB este consistent și provine dintr-o singură conexiune/snapshot;
- [ ] baza de date completă există la fiecare punct de restaurare incremental;
- [ ] nu creează arhiva completă pe serverul clientului;
- [ ] spațiul temporar este limitat și verificat;
- [ ] profilul `low_impact` modifică efectiv execuția;
- [ ] circuit breakerul oprește sau suspendă backupul la degradare;
- [ ] uploadul poate fi reluat;
- [ ] joburile sunt idempotente;
- [ ] orice excepție ajunge într-o stare finală sau reconciliabilă;
- [ ] alertele sunt trimise și verificate;
- [ ] fiecare obiect are checksum valid;
- [ ] backupul incomplet nu apare ca restaurabil;
- [ ] datele sunt criptate înainte de stocare;
- [ ] retenția nu rupe lanțurile;
- [ ] ștergerea verifică obiectele reale;
- [ ] se poate genera și descărca un pachet portabil;
- [ ] pachetul poate fi restaurat manual;
- [ ] restore-ul direct face safety backup;
- [ ] restore-ul direct are rollback;
- [ ] full restore și incremental restore au fost testate real;
- [ ] dashboardul arată corect site-urile protejate și neprotejate;
- [ ] nu există configurări sau butoane decorative;
- [ ] rolloutul poate fi oprit prin feature flag.

---

## 24. Ordinea implementării

### Faza 0 — Stabilizare imediată

1. repararea igienei de memorie V1 pentru site-urile care eșuează;
2. alertarea site-urilor fără backup valid;
3. verificarea retenției V1 în dry-run și activarea controlată;
4. blocarea oricărei prezentări false a backupului V2 ca verificat.

### Faza 1 — Fundația V2 de producție

1. state machine și `failed()`;
2. legarea `Backup`–`BackupSession`;
3. UUID și prefix S3 imutabil;
4. preflight complet;
5. profil low-impact real;
6. circuit breaker;
7. criptare;
8. verificare reală;
9. alerte;
10. scheduler și concurență.

### Faza 2 — Full backup complet

1. DB consistent;
2. fișiere chunked;
3. upload reluabil;
4. manifest/checksum/_COMPLETE;
5. retenție full;
6. download portabil;
7. restore full și rollback;
8. proven restore.

### Faza 3 — Incremental

1. inventar full;
2. detectare create/modify/delete;
3. lanțuri;
4. dump DB complet la fiecare punct;
5. retenție pe lanț;
6. restore incremental;
7. forțarea unui full când baza lanțului nu mai este sigură.

### Faza 4 — Rollout

1. un site intern;
2. trei site-uri reprezentative;
3. 10% din flotă;
4. 25%;
5. 50%;
6. toate site-urile compatibile.

V1 rămâne fallback până când fiecare fază are teste și restore dovedit. Nu se face migrare în masă
după o singură rulare reușită.

---

## 25. Decizia finală, într-o frază

> Se păstrează produsul și experiența V1, se înlocuiește motorul de creare cu V2 finalizat, se
> folosește exclusiv Hetzner S3, iar utilizatorul alege între full și full + incremental; fiecare
> punct de restaurare păstrează o bază de date completă și consistentă, fișierele sunt procesate în
> chunkuri cu impact minim, iar sistemul nu este declarat funcțional până când downloadul, restore-ul,
> rollbackul, retenția și testele de impact sunt dovedite end-to-end.

---

## Anexă — sursele cifrelor din auditul inițial

| Afirmație | Sursă |
|---|---|
| 544/601 = 90,5%, 296 s medie, 0 incrementale | interogare pe `backups`, ultimele 30 de zile, 31 iulie 2026 |
| 342 rânduri orfane | `docs/backup/spike/DATABASE-CONSISTENCY.md` |
| 1,18 TB, 1448 rânduri, cel mai vechi 12 martie | `storage_destinations.used_bytes` + `backups` |
| `retention_dry_run = true` | configurația containerului de producție |
| `florinpasat.com`: OOM la chunk 3/70 | `backups` + logul Laravel din 31 iulie 2026 |
| V2: 13 obiecte, 451,5 MB, 1m47s | sesiunea V2 și listarea obiectelor Hetzner |
| Impact: HTTP 200 / 70 ms | `uptime_checks`, monitorul din 31 iulie 2026 |
| Setările de profil neconsumate | căutare per cheie în `app/` |
| Criptarea acoperă numai managerul | consumatorii existenți ai `BACKUP_ENCRYPTION_KEY` |
