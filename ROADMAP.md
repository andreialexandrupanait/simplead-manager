# ROADMAP.md — SAD Manager (roadmap de produs)

**Data:** 2026-07-02 · Derivat din secțiunile „Oportunități de îmbunătățire" ale celor 12 rapoarte de modul + benchmark competitiv (SpinupWP, WPMU DEV, ManageWP, MainWP). Precondițiile tehnice fac referire la constatările din [`AUDIT.md`](AUDIT.md).

> **Nota despre wishlist-ul ownerului:** secțiunea „Owner's wishlist" din `sad-manager-audit-prompt.md:84-87` a rămas necompletată la momentul auditului (`[TODO — to be filled by the owner]`). Roadmap-ul de mai jos e construit exclusiv din constatările auditului + benchmark. **Când completezi wishlist-ul, itemii se inserează aici** și îi reconciliez cu prioritățile existente.

---

## Benchmark competitiv (cele 8 capabilități din specificație)

| Capabilitate | Stare | Dovadă / gap |
|---|---|---|
| **Transparent updates** (listă exactă versiune curentă→țintă + istoric per site) | 🟡 parțial | `UpdateLog` există și pagina Updates arată update-uri disponibile, dar fluxul nu prezintă un plan explicit „X: 1.2→1.3" înainte de aplicare, iar istoricul e fragmentat (rollback rupt: `PM-P1-1`). |
| **Assistant / Todo system** (feed acționabil per site, priorități calibrate, one-click remediere) | ❌ lipsă | Există `SecurityRecommendationService` și `ReportRecommendationService`, dar niciun feed unificat de todo-uri per site cu execuție one-click. Health scores nu sunt acționabile (`D-P1-2`). |
| **Dashboard at scale** (tabel cu type-to-filter, sortare, coloane configurabile, 50+ site-uri) | 🟡 parțial | `GlobalDashboard` + `SitesList` cu filtre/sortare există, dar filtrarea/sortarea pe health e ruptă (`sites.health_score` mereu NULL, `D-P1-1`), coloanele nu sunt configurabile, și există risc N+1 la scală. |
| **Bulk actions** (selecție multi-site → acțiune) | 🟡 parțial | `WithBulkSiteActions` + `updatePluginAcrossSites` există, dar fără authz per-site (`PM-P1-3`), fără canary/rollout etapizat și fără backup garantat — periculos la scală azi. |
| **Tags/labels pe site-uri** (prod/staging, client, plan; filtrabile) | ❌ lipsă | Nu există model de tag-uri pe `Site`. Gruparea se face doar pe client. |
| **Remote configuration** (maintenance mode, wp-config constants, setări din dashboard) | 🟡 parțial | Există `Tweaks/` + `SiteTweaksSettingsService` + hardening push, dar acoperire limitată; connectorul are endpoint-uri neexpuse în UI. |
| **REST API pentru management** (SAD Hub / SAD Tasks pot declanșa și citi operații) | 🟡 parțial | `/v1/*` cu Bearer PAT există dar e minimal (`/me`, `/sites`) și citește `health_score` NULL; niciun endpoint de trigger (backup/restore/update). `OpenApiService` există dar neexploatat. |
| **Guided safety flows** (modal de confirmare care recomandă/declanșează backup înainte de acțiuni riscante) | 🟡 parțial | Toggle „backup before updates" există dar e async și doar DB (`PM-P1-6`), iar modalul de restore n-are backup full obligatoriu. UX-ul e prezent, politica de siguranță lipsește. |

**Verdict:** SAD Manager acoperă *lățimea* funcțională a unui WPMU DEV (backup, security, uptime, SEO, performance, reports — mai mult decât SpinupWP), dar *profunzimea și fiabilitatea* fiecărei capabilități sunt sub pragul de producție. Cele mai multe capabilități sunt 🟡 „parțial" tocmai pentru că infrastructura există, dar e deconectată, ruptă la runtime sau nesecurizată.

---

## Gap analysis vs. WPMU DEV (core capabilities lipsă/nesigure)

- **Safe updates cu rollback vizual** — infrastructura există (`RunSafeUpdate`, `runVisualRegression`) dar e cod mort (`PM-P0-2`). Aceasta e capabilitatea-vedetă a unui WPMU DEV și azi nu e livrată.
- **Restore de încredere** — restore-ul nu e atomic, nu are undo, nu e testat (`B-P0-2`). WPMU DEV/Snapshot oferă restore verificat.
- **Uptime din locații multiple** — o singură sondă, cu fals-pozitive (`U-P1-1/2`). Schema are deja `check_locations` nefolosit.
- **Todo/assistant acționabil** — lipsă completă (vezi benchmark).
- **Tag-uri pe site-uri** — lipsă completă.

---

## Quick wins (efort S, valoare mare)

| Item | Modul | Efort | Valoare | Precondiție tehnică |
|---|---|---|---|---|
| Conectează `RunSafeUpdate` (backup→update→health→rollback) la fluxul UI prin coadă | Plugin Mgmt | S/M | Elimină cea mai mare clasă de risc; feature „safe update" devine real | Necesită întâi authz pe update — AUDIT P0-2/P1-7 |
| Un singur `health_score` persistat (scrie `HealthScoreService::calculate()` în `sites.health_score`) | Dashboard | S | Repară dintr-o lovitură filtrare/sortare/distribuție/API | `D-P1-1`, `D-P1-2` |
| Dead-man's switch pe monitoring (alertă când `last_checked_at < now()-3×interval`) | Uptime | S | Închide toate clasele de eșec silențios de monitorizare | `U-P1-1/2/2b` |
| Heartbeat extern (healthchecks.io) pe scheduler + alertă Horizon sincronă | Cozi/Notif | S | Singura soluție la „cine păzește paznicul" | `QS-02`, `N-P1-3` |
| Pagină „Delivery log" peste `NotificationLog` + badge „X livrări eșuate/24h" | Notificări | S | Închide jumătate din orbirea de alertare | `N-P1-1` |
| Livrarea link-ului de ack (buton Slack/email) | Notificări | S | Activează tot lanțul ack→escaladare deja construit | `N-P1-2` |
| Alertare pe pipeline-ul de rapoarte (`report_failed`/`schedule_deactivated`) | Reports | S | Un schedule nu mai moare silențios după 3 luni | `R-P1-1` |
| Reînvie editorul de performance budgets (UI ~70 linii șters) + repornește dispatch-ul de teste | Performance | S | Backend complet există; azi monitorizarea nu rulează deloc | `PF-P1-1` |
| Fix backup manager (`$config` + exit-code pg_dump + offsite S3) | Infra | S | DR-ul propriu din inexistent în verificabil | `INF-02/03` |
| Bară de uptime 90 zile + scheduled maintenance pe status pages | Status Pages | S/M | Elemente standard ale categoriei; datele există | — |
| Alertă expirare domeniu via RDAP pe `DnsMonitor` | Uptime/DNS | S/M | Acoperă cel mai jenant incident posibil pentru o agenție | — |
| Audit trail „cine-a-făcut-ce" pe operații distructive | Dashboard | S/M | Obligatoriu pentru o platformă cu operații distructive | `D-P1-3` |

---

## Strategic (efort M–L, valoare mare, necesită precondiții)

| Item | Modul | Efort | Rațiune | Precondiție tehnică |
|---|---|---|---|---|
| **Rollout etapizat (canary) pentru „Update All Sites"** | Plugin Mgmt | M/L | Update pe flotă fără risc de a strica N site-uri simultan; health check + visual regression există | Bulk update trebuie întâi securizat + trecut prin SafeUpdate — AUDIT P0-2, `PM-P1-3` |
| **„Restore to new site" / staging one-click** | Backups | M | Elimină majoritatea restore-urilor riscante pe live; pipeline de materializare există | Restore path testat — AUDIT Nivel 2 |
| **Test de restore automat lunar în container WP efemer** | Backups | L | Singura garanție reală de restaurabilitate; azi `backup:verify-restore` face doar integritate | Restore path testat |
| **Sistem de Todo/Assistant per site** (feed acționabil, priorități calibrate, one-click) | Dashboard/Security | L | Capabilitate-cheie lipsă vs. SpinupWP; agregă recomandările existente | Health scores reparate; authz distructiv |
| **Auto-update real condus de vulnerabilități** (safe-update nocturn doar pe CVE cu patch) | Plugin/Security | M | Feed Wordfence + flag `auto_update` există (dar flagul e no-op azi) | `PM-P1-4`, SafeUpdate conectat |
| **Secțiune SEO + securitate în rapoartele lunare** | Reports/SEO | M | Date deja existente; valoare vandabilă la clienți | Repară generarea rapoartelor `R-P1-1` |
| **DNS management editabil din UI** (Cloudflare CRUD deja implementat) | Integrations | S/M | Paritate SpinupWP/ManageWP la efort mic; metodele există ca cod mort | Authz + audit pe scriere DNS |
| **A doua locație de sondare uptime** (probe extern) | Uptime | M | Elimină fals-pozitivele dintr-un punct unic; `check_locations` există în schemă | — |
| **REST API de trigger pentru SAD Hub/Tasks** (backup/restore/update programatic) | Sites/API | M | Unghiul de integrare SAD-ecosystem; `OpenApiService` există | Operațiile trebuie întâi securizate + testate |
| **Tag-uri/labels pe site-uri + filtrare** | Dashboard | M | Capabilitate lipsă; necesară la 50+ site-uri | — |
| **Provisioning automat al connectorului** (chei generate + mu-plugin injectat) | Sites | S/M | Elimină copy-paste manual; infra signed-download există | — |

---

## Not worth it (deocamdată) — cu argument

| Item | De ce nu acum |
|---|---|
| **Webhook inbound ca pipeline de leads** | Endpoint-ul e mort și e o gaură de securitate (`Integrations P1`). De transformat în receiver semnat HMAC *doar* dacă apare o nevoie reală de ingestie; altfel de dezactivat, nu de extins. |
| **Firewall/WAF orchestrat din incident response** | Metodele Cloudflare (`enableWaf`, `blockIpViaCloudflare`) există ca cod mort, dar incident-response-ul e rupt la runtime (`S-P1-5`) și remedierea AI n-are om-în-buclă. Prematur până când calea de bază e reparată și există aprobare umană. |
| **Extinderea remedierii AI automate** | Înainte de a adăuga capabilități, tool-ul `apply_security_fix` are nevoie de allowlist server-side (`S-P1-3`) și de dry-run cu aprobare; extinderea acum multiplică riscul pe site-uri live. |
| **Custom domains pe status pages** | Feature de polish; irelevant până când modulul are subscribe email și bara de uptime (quick wins de mai sus). |

---

## Prioritizare sugerată

**Regula:** niciun feature nou pe o cale distructivă înainte ca acea cale să fie securizată și testată (AUDIT Nivel 1–2). Ordinea recomandată:

1. **Nivel 1 AUDIT** (P0-uri) — precondiție pentru orice quick win pe update/restore/bulk.
2. **Quick wins de observabilitate** (heartbeat, delivery log, dead-man's switch, health_score) — ieftine, opresc orbirea.
3. **SafeUpdate conectat + canary** — deblochează bulk updates sigure, capabilitatea-vedetă.
4. **Restore-to-staging + test de restore lunar** — transformă restore-ul din pariu în operație de încredere.
5. **Todo/Assistant + tags + REST API de trigger** — paritatea de produs cu SpinupWP/WPMU DEV, după ce fundația e solidă.
