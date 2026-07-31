# Brief pentru Claude Code — SAD Manager

> **Citește întâi `SPEC-SAD-MANAGER.md`.** Documentul acela e sursa de adevăr pentru *ce* trebuie să facă aplicația. Acest fișier descrie *cum* ajungem acolo.

---

## Context

**Repo:** `simplead-manager`
**Stack:** Laravel 11, PHP 8.3, Livewire 4, Blade, Tailwind, PostgreSQL, Docker pe Hetzner
**Stare curentă:** ~99 modele, ~162 servicii, ~111 componente Livewire, ~62 joburi, 212 fișiere de test
**Utilizator:** unul singur (Andrei). Fără roluri, fără echipe, fără portal de client.

**Ce este aplicația:** mentenanță WordPress pentru sub 60 de site-uri — monitorizare, operațiuni, rapoarte lunare.

**Ce NU este:** audit SEO/CRO (pleacă în aplicație separată), ERP financiar (e SAD Hub), firewall sau scanare de malware (sunt pluginuri pe site-ul clientului).

**Problema de rezolvat:** trei domenii care nu au ce căuta împreună trăiesc în aceeași aplicație. Nu lipsesc funcționalități — sunt duplicate și nefinalizate.

---

## Reguli de lucru — obligatorii

1. **O singură fază per sesiune.** Nu începe faza următoare fără confirmare explicită.
2. **Ramură separată per fază.** `git checkout -b faza-N-descriere`
3. **Rulează `php artisan test` înainte și după fiecare sub-pas.** Notează numărul de teste care trec înainte, ca referință.
4. **Nu edita migrări existente.** Creează migrări noi de tip `drop`.
5. **Commit mic și des**, cu mesaj care spune ce s-a șters sau adăugat.
6. **Când ceva se rupe, raportează — nu peteci în tăcere.** Dacă ștergerea unui model rupe 12 teste, spune care și de ce, înainte să le modifici.
7. **Nu atinge `wordpress-plugin/`** decât în fazele care o cer explicit.
8. **Nu refactoriza „de bine".** Dacă un cod funcționează și nu e pe lista fazei, se lasă în pace.
9. La final de fază, scrie un rezumat: fișiere șterse, modele rămase, teste care trec, ce s-a rupt.

---

## Faza 0 — Baseline

**Obiectiv:** o măsurătoare de pornire, ca să știm ce am schimbat.

```bash
php artisan test
php artisan route:list --json > /tmp/routes-before.json
ls app/Models | wc -l
find app/Services -name '*.php' | wc -l
find app/Livewire -name '*.php' | wc -l
```

**Livrabil:** un fișier `BASELINE.md` la rădăcina repo-ului cu numerele de mai sus și numărul de teste care trec.

---

## Faza 1 — Ștergeri

> **Cea mai mare reducere de confuzie, cel mai mic risc.** Cele 212 fișiere de test sunt plasa de siguranță.

Execută sub-pașii **în ordinea de mai jos**, cu commit și rulare de teste după fiecare.

### 1.1 — Motorul SEO duplicat

Există două motoare de audit paralele. Acesta e cel mic și redundant.

**Șterge:**
- `app/Services/SeoAudit/` (întreg directorul)
- `app/Models/`: `SeoAudit.php`, `SeoImage.php`, `SeoIssue.php`, `SeoKeywordRanking.php`, `SeoLink.php`, `SeoMonitor.php`, `SeoPage.php`
- `app/Livewire/Seo/` (întreg directorul)
- `app/Livewire/Sites/Detail/SiteSeoAudit.php`
- Rutele `/seo` și `/seo/quick-audit` din `routes/web.php`
- Blade-urile corespunzătoare din `resources/views/livewire/`

**Migrare nouă:** drop pentru tabelele `seo_*`.

**Curăță relațiile** din `app/Models/Site.php` și `app/Models/Traits/HasSiteRelationships.php`.

### 1.2 — Modulul de audit

Pleacă integral într-o aplicație separată. Nu se migrează nimic în Manager.

**Șterge:**
- `app/Services/Audit/` (~30 fișiere)
- `app/Models/`: `Audit.php`, `AuditCard.php`, `AuditCheck.php`, `AuditCheckResult.php`, `AuditReport.php`, `AuditRun.php`, `Prospect.php`
- `app/Livewire/Audit/`
- `app/Http/Controllers/PublicAuditReportController.php`
- `tests/Feature/Audit/`, `tests/Feature/Livewire/Audit/`
- Rutele `/audits*` și ruta publică de raport
- Joburile de audit din `app/Jobs/`

**Atenție:** constrângerea `site_id XOR prospect_id` din migrarea de audit dispare odată cu tabelele.

### 1.3 — Modelele financiare

Se bat cap în cap cu SAD Hub, care e sursa de adevăr pentru bani.

**Șterge:**
- `app/Models/ClientCost.php`, `app/Models/ClientRevenue.php`
- `app/Livewire/Clients/ClientProfitability.php`
- `app/Livewire/Clients/ClientForm.php` — clienții devin doar-citire

**Verifică `MaintenancePlan`:** dacă are câmpuri de preț, scoate-le. Planul rămâne strict configurare tehnică (nivel uptime, ritm backup, checkuri active, cadență raport).

### 1.4 — Pagini de status și portal de client

**Șterge:**
- `app/Models/`: `StatusPage.php`, `StatusPageIncident.php`, `StatusPageIncidentTemplate.php`, `StatusPageIncidentUpdate.php`, `StatusPageSite.php`
- `app/Livewire/StatusPages/`
- `app/Http/Controllers/StatusPageController.php`
- `app/Http/Controllers/ClientPortalController.php`
- `app/Models/Invitation.php` și componentele Livewire de gestiune utilizatori
- Rutele publice `/status*` și `/portal*`

**Păstrează:** autentificarea proprie și 2FA.

### 1.5 — Remediere automată

Decizie: nimic nu se execută pe site-uri fără aprobare.

**Șterge:**
- `app/Models/IncidentResponse.php`, `app/Models/IncidentResponseAction.php`
- `app/Services/IncidentResponse/`
- `tests/Unit/Services/IncidentResponse/`
- `AuditAutoApprover` (dacă n-a dispărut deja la 1.2)
- Ecranul de setări pentru răspuns automat la incidente

### Criterii de acceptare — Faza 1

- [ ] `php artisan test` trece integral
- [ ] `php artisan route:list` nu conține rute către nimic șters
- [ ] Numărul de modele a scăzut cu ~40
- [ ] Aplicația pornește și navigarea principală funcționează
- [ ] `BASELINE.md` actualizat cu cifrele noi

---

## Faza 2 — Un singur site, cap-coadă

> **Dacă merge pentru unul, merge pentru șaizeci. Acum nu e demonstrat pentru niciunul.**

**Obiectiv:** alege cel mai simplu site din bază — nu cel mai important — și fă lanțul complet să funcționeze fără intervenție manuală.

**Lanțul:**

1. Conectare: URL → instalare conector → detectare automată (versiune WP, PHP, pluginuri, WooCommerce, plugin de formulare, disponibilitate Search Console) → profil propus → confirmare
2. Monitorizare: uptime, SSL, expirare domeniu, integritate fișiere, vulnerabilități — toate produc semnale
3. Un update cu rollback: aplicare, smoke check, verificare, rollback la eșec
4. Un backup restaurat: backup → restore într-un container efemer → confirmare că a funcționat
5. Un raport livrat pe email: generare → bariere de siguranță → trimitere

**Livrabil:** un document `E2E-VERIFICAT.md` care notează, pentru fiecare pas, ce a funcționat și ce a cerut intervenție manuală.

**Nu trece la faza 3 până când toți cei cinci pași nu merg fără intervenție.**

---

## Faza 3 — Restructurarea navigației

Vezi secțiunea 3 din `SPEC-SAD-MANAGER.md` pentru structura completă.

### 3.1 — Două contexte

Două layout-uri Blade separate: `x-layout.fleet` și `x-layout.site`. Sidebar-ul global dispare complet când intri pe un site.

### 3.2 — Sidebar flotă

Grupat: **Vedere** (Panou, Alerte) · **Operațiuni** (Site-uri, Actualizări) · **Evidență** (Rapoarte, Activitate) · Setări jos, după separator.

Contoarele apar doar când sunt nenule, colorate după gravitate.

### 3.3 — Sidebar site

Secțiuni pliabile, cu link de întoarcere sus și comutator de site. Butoanele rapide — wp-admin, backup, verifică — rămân vizibile în toate secțiunile.

De la 25 de taburi la 11 secțiuni. Vezi specificația.

### 3.4 — Ecranul de listă

Model: WPMU DEV Hub. Vedere listă și grid, comutabile.

Rând: `[avatar] [nume + client] [nr. update-uri] │ [grup 1] │ [grup 2] │ [grup 3] │ [uptime] [⋮]`

Trei grupuri de iconițe, **separate prin linii verticale**:

| Grup | Iconițe |
|---|---|
| Disponibilitate | uptime, SSL, backup |
| Sănătate | securitate, performanță, erori PHP |
| Funcționare | formulare, WooCommerce |

Culori: verde ok · galben atenție · roșu critic · gri nu se aplică.

**Selecție multiplă** cu bară de acțiuni: actualizează · backup · aplică plan · aplică presetări · golește cache · verifică acum.

### 3.5 — Ecranul de prezentare al site-ului

Grid de carduri, 3 coloane pe ecran lat. Fiecare card: iconiță colorată, titlu, roțiță de setări, meniu, rânduri pe fundal `surface-1`, acțiune principală ca link jos.

**Carduri:** Info site · Securitate · Verificări · Sarcini · Actualizări · Uptime · Backupuri · Trafic

**Încărcare — important.** Componenta părinte încarcă într-un singur query tot ce vine din baza locală: Info site, Actualizări, Securitate, Verificări, Sarcini. Se randează la prima pictură.

Doar **trei** componente sunt leneșe (`#[Lazy]` + placeholder), pentru că lovesc API-uri externe și au taburi de perioadă: **Uptime**, **Trafic**, **Backupuri**.

Taburile de perioadă sunt proprietăți ale componentei leneșe, nu ale părintelui — altfel un clic pe „30 zile" reîmprospătează tot ecranul.

Cache de o oră pe cheia `site + perioadă` pentru datele externe.

### 3.6 — Sistem de design

Vezi secțiunea 13bis din specificație. **Nu instala niciun kit de componente** — nici Flowbite, nici Preline, nici Filament.

19 token-uri, 12 componente Blade, Tabler Icons outline auto-găzduite ca sprite SVG, două greutăți de font (400 și 500), rând de tabel la **44px**, fără umbre, fără gradienți, fără dark mode în v1.

---

## Faza 4 — Motorul comun de operațiuni

**Nu porni de la zero.** Există deja `CircuitBreakerService`, `JobTracker` și `app/Dispatchers/` (7 fișiere). Consolidează-le.

**Interfața:**

```php
interface Operation
{
    public function prepare(OperationContext $ctx): void;
    public function execute(OperationContext $ctx): OperationResult;
    public function verify(OperationContext $ctx): bool;
    public function rollback(OperationContext $ctx): void;
    public function isReversible(): bool;
}
```

Primesc gratuit: selecția site-urilor, coada, limita de concurență, progresul, rezultatul per site, retry, timeout, log, poarta de aprobare, notificarea la final.

**Cozi separate pe clasă de durată** — un purge de cache nu așteaptă după un backup de 20 de minute.

**Tipuri de migrat:** update, backup, restore, push plugin, aplicare presetări, purge cache.

**Aprobarea e pe lot, nu per site.** Un click aprobă operațiunea pe toată selecția.

---

## Faza 5 — Cele patru verificări noi

Zero cod existent pentru toate patru. Detalii complete în specificație, secțiunile 5.4 și 5.7.

### 5.1 — Formulare de contact

Trimitere reală săptămânală cu marcaj, plus verificare de livrare.

> **Conectorul trebuie să seteze un flag pe durata testului care suprimă integrările** — CRM, Zapier, autoresponder, Mailchimp. Ștergerea intrării după test nu e suficientă: contactul fals ajunge deja în sistemele clientului.

### 5.2 — WooCommerce

Condiționat, doar unde WooCommerce e detectat: checkout returnează 200, gateway de plată răspunde, comenzi blocate în `pending` peste prag, cron de reduceri programate.

### 5.3 — Linkuri rupte

Conectorul scanează conținutul din baza de date și extrage URL-urile unice; Manager le verifică prin HEAD.

Doar interne implicit. Externe opțional, per site. Lunar. **Raportare pe diferență, nu pe stare.**

Verifică întâi dacă modulul existent `Redirecturi` acoperă deja detectarea lanțurilor de redirect — dacă da, alimentează-l, nu dubla logica.

### 5.4 — Licențe premium

Câmp per site cu data expirării, plus citirea stării de la pluginurile care o expun prin API. Alertă la 30 de zile.

---

## Faza 6 — Reguli inteligente de update

`PluginRiskAssessmentService` **există deja și nu e legat de fluxul de update.** Conectează-l — e cea mai ieftină funcționalitate din tot documentul.

Cele trei reguli, cu sintaxa completă, sunt în secțiunea 7.2 din specificație:

1. **Update minor automat** — condiții + canary + smoke check + rollback
2. **Așteaptă aprobarea** — versiune majoră sau plugin din lista de risc
3. **Vulnerabilitate critică** — singura care sare peste aprobare

**Lista de risc** e per site, populată automat la conectare din categoria pluginului și din istoricul de incidente, suprascriabilă manual.

**Smoke check, etapa 1** — pe URL-urile cheie: status 200, absența unui `Fatal error` în corp, prezența selectorului-canar, numărul de noduri DOM față de referință.

**Etapa 2** — capturi înainte/după, stocate, fără diff automat. Retenție: ultimele 3 seturi per site.

**Etapa 3 nu se implementează.** Diff-ul de pixeli produce false pozitive din carusele, bannere rotative și conținut lazy-loaded, iar gestionarea zonelor mascate devine o slujbă în sine.

---

## Faza 7 — Decizie pe modulele în observație

Instrumentează cu log de acces, apoi decide la 60 de zile pe date:

- `Tweaks` (cele patru taburi)
- `DatabaseCleanup`
- `PluginConflict`
- `Redirecturi`

---

## Ce nu se atinge, în nicio fază

| Zonă | Motiv |
|---|---|
| `Services/Backup/` și `ProvenRestore` | funcționale și complete — `RunProvenRestore` are model, job și UI |
| `WithWpAdminLogin` + `SAM_Login_Endpoint` | login instant funcționează deja |
| `SafeUpdateService`, `RollbackPoint`, `RollbackService` | nucleul aplicației |
| Integrările Google, Cloudflare, Dropbox | funcționale |
| Autentificare și 2FA | rămân |

---

## Verificare finală

- [ ] `php artisan test` trece integral
- [ ] ~30 de modele, nu 99
- [ ] Două contexte de navigație, fără coliziuni de nume
- [ ] Un site parcurge lanțul complet fără intervenție manuală
- [ ] Un raport ajunge la destinatar, cu barierele de siguranță active
- [ ] Nicio referință rămasă la audit, SEO, prospecți, profitabilitate, status pages sau portal
