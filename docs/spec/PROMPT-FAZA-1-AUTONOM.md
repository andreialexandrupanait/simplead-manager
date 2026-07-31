# Faza 1 autonomă — brief de execuție pentru Claude Code

Ești inginer pe repo-ul `simplead-manager`. Execuți Faza 1 — Ștergeri — **autonom, de la cap la coadă**, fără să ceri confirmare între sub-pași.

**Citește întâi:** `SPEC-SAD-MANAGER.md` (ce trebuie să facă aplicația) și `PROMPT-SAD-MANAGER-CLAUDE-CODE.md` (contextul complet al fazelor).

---

## De ce facem asta

Aplicația are trei domenii care nu au ce căuta împreună: mentenanță WordPress, audit SEO/CRO, și evidență financiară. Ultimele două pleacă — auditul într-o aplicație separată, banii în SAD Hub.

Nu lipsesc funcționalități. Sunt duplicate. Există **două motoare de audit paralele** care fac același lucru, și modele financiare care se bat cap în cap cu SAD Hub.

**Măsura succesului nu e „am șters mult". E „aplicația face exact ce făcea înainte pentru mentenanță, cu o treime mai puțin cod."**

---

## Pre-flight — obligatoriu înainte de orice ștergere

```
[ ] Ești pe ramura faza-1-stergeri, creată din ramura curentă
[ ] `git status` e curat
[ ] `bin/test` rulează și cunoști numărul exact de teste verzi
[ ] BASELINE.md există și e citit
[ ] .baseline-artifacts/routes-before.json există
```

**Dacă baseline-ul are teste roșii preexistente**, notează-le nominal într-o listă `ROSII_CUNOSCUTE`. Orice roșu care nu e în listă e regresie cauzată de tine și declanșează protocolul de triaj.

**Nu rula niciodată migrări pe baza de date de producție.** Doar pe `simplead_test` și pe local.

---

## Bucla principală

Pentru fiecare sub-pas `N` din `[1.1, 1.2, 1.3, 1.4, 1.5]`:

### Pasul 1 — Măsoară

Rulează `bin/test`. Notează verzi, roșii, skipped. Actualizează dashboard-ul cu starea „rulează".

### Pasul 2 — Inventariază înainte să ștergi

Înainte de a șterge orice fișier, caută **toate** referințele la el:

```bash
grep -rn "NumeModel\|NumeServiciu" app/ routes/ resources/ tests/ database/ config/
```

Scrie lista în jurnal. Dacă găsești referințe în locuri neașteptate — de exemplu un model pe care îl ștergi e folosit de `Site.php` sau de un job de mentenanță — **oprește-te și raportează**. Asta e informație, nu un obstacol.

### Pasul 3 — Șterge

Execută ștergerile din sub-pasul N, în ordinea: teste → componente Livewire → blade-uri → rute → controllere → joburi → servicii → modele.

Ordinea contează: ștergi consumatorii înainte de furnizori, ca erorile să apară devreme și clar.

### Pasul 4 — Curăță urmele

- Relații în `app/Models/Site.php` și `app/Models/Traits/HasSiteRelationships.php`
- Importuri orfane
- Intrări în `config/`
- Referințe în navigație și în layout-uri
- Factories și seeders
- Migrare nouă de tip `drop` pentru tabelele afectate — **nu edita migrări existente**

### Pasul 5 — Măsoară din nou

Rulează `bin/test`.

### Pasul 6 — Triaj, dacă apar roșii

Pentru **fiecare** test roșu, aplică arborele de decizie:

```
Testul e în ROSII_CUNOSCUTE?
├─ DA  → ignoră, nu e al tău
└─ NU  → Ce testa?
         ├─ Exact funcționalitatea ștearsă
         │    → șterge testul, notează în jurnal
         ├─ Altceva, dar folosea un model șters ca fixture
         │    → rescrie fixture-ul, NU schimba ce verifică testul
         └─ Altceva, și nu înțelegi de ce pică
              → OPREȘTE-TE. Scrie ce ai găsit. Cere ajutor.
```

**Interdicție absolută:** nu modifica un test ca să treacă. Dacă un test verifică un comportament și acum pică, ori comportamentul a dispărut legitim (șterge testul), ori l-ai rupt (repară codul). Nu există a treia variantă.

### Pasul 7 — Audit independent

Lansează un **subagent** care primește exclusiv:
- `git diff` al sub-pasului curent
- `SPEC-SAD-MANAGER.md`
- lista „Ce nu se atinge" de mai jos

**Nu-i da istoricul muncii tale, raționamentul tău, sau concluziile tale.**

Prompt pentru subagent:

```
Ești auditor independent. Ai în față un diff de ștergeri dintr-o aplicație
Laravel de mentenanță WordPress, și specificația a ceea ce aplicația
trebuie să rămână.

Nu ai context despre cine a făcut diff-ul sau de ce. Judecă doar ce vezi.

Răspunde la patru întrebări, cu dovezi din diff:
1. S-a șters ceva ce specificația spune că trebuie păstrat?
2. A rămas vreo referință orfană — import, relație, rută, config,
   factory, seeder — către ceva șters?
3. Migrarea de drop acoperă exact tabelele ale căror modele au dispărut,
   nici mai mult, nici mai puțin?
4. S-a modificat vreun test în așa fel încât să verifice mai puțin
   decât verifica înainte?

Pentru fiecare problemă găsită: fișier, linie, de ce e o problemă.
Dacă nu găsești nimic, spune explicit „nicio problemă găsită" —
nu inventa observații ca să pari util.
```

Dacă subagentul semnalează ceva, **rezolvă înainte de commit** și notează în jurnal ce a găsit.

### Pasul 8 — Commit

```
faza-1.N: <ce s-a șters>

- X modele, Y servicii, Z componente Livewire
- Migrare drop: <nume>
- Teste: <înainte> → <după> verzi
- Subagent: <verdict>
```

### Pasul 9 — Raportează

Actualizează `PROGRES-FAZA-1.md` și regenerează `progress.html` (vezi protocolul de mai jos).

### Pasul 10

Treci la `N+1`. Fără să întrebi.

---

## Protocol de raportare live

După **fiecare** eveniment semnificativ — început de sub-pas, ștergere, rulare de teste, triaj, subagent, commit — regenerează fișierul `progress.html` la rădăcina repo-ului.

Fișierul are `<meta http-equiv="refresh" content="5">`, deci se reîmprospătează singur în browser. Nu e nevoie de server.

**Menține în el:**

| Zonă | Conținut |
|---|---|
| Antet | faza, starea (rulează / oprit / gata), ora ultimei actualizări |
| Metrici | sub-pas curent din total, teste verzi, teste roșii, modele rămase |
| Listă sub-pași | stare per sub-pas: gata (verde), în lucru (galben), în așteptare (gri), blocat (roșu) |
| Jurnal | ultimele ~20 de evenimente cu marcaj de timp, cel mai recent jos |

Folosește `progress.template.html` ca punct de plecare. Păstrează structura, înlocuiește doar datele.

**Când te oprești pentru o întrebare**, starea devine „oprit", iar în jurnal scrii întrebarea vizibil. Ăsta e semnalul că e nevoie de mine.

---

## Condiții de oprire

Oprește-te și cere ajutor dacă:

- Un test pică dintr-o cauză pe care nu o poți explica
- Ar trebui să modifici cod în afara listei sub-pasului curent
- O ștergere ar afecta ceva din „Ce nu se atinge"
- Ai nevoie să atingi `wordpress-plugin/`
- Numărul de teste verzi scade fără explicație
- Subagentul semnalează ceva ce nu știi cum să rezolvi
- O migrare de drop ar afecta un tabel care nu e pe listă
- `grep` găsește referințe la ceva ce ștergi în locuri care sugerează că e folosit de mentenanță, nu de audit

**Când te oprești:** commit ce ai funcțional, actualizează dashboard-ul cu starea „oprit", și scrie clar ce ai găsit și ce opțiuni vezi.

---

## Protocol de recuperare

Dacă un sub-pas iese prost și nu-l poți repara:

```bash
git reset --hard HEAD    # revii la ultimul commit bun
```

Notează în jurnal ce ai încercat și de ce a eșuat. **Nu relua același sub-pas cu aceeași abordare.** Oprește-te și raportează.

---

## Sub-pașii

Listele complete sunt în `PROMPT-SAD-MANAGER-CLAUDE-CODE.md`, secțiunea Faza 1. Rezumat:

| Sub-pas | Ce dispare | Impact estimat |
|---|---|---|
| **1.1** | `Services/SeoAudit/`, modelele `Seo*`, `Livewire/Seo/`, `SiteSeoAudit`, rutele `/seo*` | ~12 modele |
| **1.2** | `Services/Audit/`, modelele `Audit*`, `Prospect`, `Livewire/Audit/`, `PublicAuditReportController`, testele de audit | ~21 modele, 29 fișiere test |
| **1.3** | `ClientCost`, `ClientRevenue`, `ClientProfitability`, `ClientForm` | clienții devin doar-citire |
| **1.4** | modelele `StatusPage*`, `Livewire/StatusPages/`, `StatusPageController`, `ClientPortalController`, `Invitation` | ~10 modele |
| **1.5** | `IncidentResponse*`, `Services/IncidentResponse/`, `AuditAutoApprover` | — |

**La 1.3, verifică `MaintenancePlan`:** dacă are câmpuri de preț, scoate-le. Planul rămâne strict configurare tehnică — nivel uptime, ritm backup, checkuri active, cadență raport.

---

## Ce nu se atinge

| Zonă | Motiv |
|---|---|
| `Services/Backup/`, `ProvenRestore`, `RunProvenRestore` | funcționale și complete |
| `WithWpAdminLogin`, `SAM_Login_Endpoint` | login instant funcționează |
| `SafeUpdateService`, `RollbackPoint`, `RollbackService` | nucleul aplicației |
| `CircuitBreakerService`, `JobTracker`, `app/Dispatchers/` | baza motorului de operațiuni din Faza 4 |
| `PluginRiskAssessmentService` | se conectează în Faza 6, nu se șterge |
| Integrările Google, Cloudflare, Dropbox | funcționale |
| Autentificare și 2FA | rămân |
| `wordpress-plugin/` | nu se atinge în Faza 1 |

---

## Interdicții

- Nu refactoriza nimic care nu e pe lista sub-pasului
- Nu redenumi fișiere „ca să fie mai clar"
- Nu adăuga funcționalități
- Nu modifica teste ca să treacă
- Nu edita migrări existente
- Nu rula migrări pe producție
- Nu sări peste auditul subagentului
- Nu continua peste o condiție de oprire

---

## Criterii de acceptare

```
[ ] Toate cele 5 sub-pașuri comise, pe ramura faza-1-stergeri
[ ] `bin/test` — doar ROSII_CUNOSCUTE rămân roșii
[ ] `php artisan route:list` fără rute către audit, seo, portal, status
[ ] Modele: ~97 → ~57
[ ] Aplicația pornește, navigarea principală funcționează
[ ] Un site se poate deschide și vedea în detaliu
[ ] PROGRES-FAZA-1.md complet
[ ] progress.html în stare „gata"
[ ] BASELINE.md actualizat cu cifrele finale
```

---

## Raportul final

La încheiere, scrie în `PROGRES-FAZA-1.md`:

1. Tabel cu cifrele înainte/după: modele, servicii, componente, joburi, teste, rute
2. Ce s-a rupt neașteptat și cum ai rezolvat
3. Ce a semnalat fiecare subagent
4. Referințe orfane găsite prin `grep` care sugerează cuplaje pe care nu le bănuiam
5. Ce ai lăsat nerezolvat și de ce
6. Recomandarea ta pentru Faza 2

Punctul 4 e cel mai valoros. Cuplajele descoperite în timpul ștergerii spun mai mult despre arhitectura reală a aplicației decât orice diagramă.
