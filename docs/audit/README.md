# Audit SAD Manager — index

**Data:** 2026-07-02 · **Scope:** întreg working tree-ul (inclusiv cod necomis) · **Metodă:** recon → 17 audituri paralele → verificare manuală a P0-urilor.

Livrabile la rădăcina repo-ului: [`../AUDIT.md`](../AUDIT.md) (audit tehnic, scoruri, top 10, remediere) · [`../ROADMAP.md`](../ROADMAP.md) (roadmap de produs, benchmark competitiv).

## Faza 0 — Recon
- [00-module-map.md](00-module-map.md) — harta modulelor: fișiere, entry points, integrări, operații distructive, cod orfan.

## Faza 1 — Audituri de modul
| Raport | Modul | P0 | P1 |
|---|---|----|----|
| [11-sites-connector.md](11-sites-connector.md) | Sites & Connector plugin | 0 | 3 |
| [12-backups-restore.md](12-backups-restore.md) | Backups & Restore | **2** | 8 |
| [13-plugin-management.md](13-plugin-management.md) | Plugin Management | **2** | 6 |
| [14-security-incident-response.md](14-security-incident-response.md) | Security & Incident Response | 0 | 5 |
| [15-uptime-dns.md](15-uptime-dns.md) | Uptime & DNS | 0 | 6 |
| [16-performance.md](16-performance.md) | Performance / PageSpeed | 0 | 2 |
| [17-seo.md](17-seo.md) | SEO Audits | 0 | 6 |
| [18-reports-clients.md](18-reports-clients.md) | Reports & Clients | 0 | 5 |
| [19-integrations.md](19-integrations.md) | Integrations (Cloudflare/GA/GSC/Dropbox) | 0 | 3 |
| [20-notifications.md](20-notifications.md) | Notificări & Alerting | 0 | 4 |
| [21-status-pages.md](21-status-pages.md) | Status Pages | 0 | 1 |
| [22-dashboard-health.md](22-dashboard-health.md) | Dashboard & Health Scores | 0 | 4 |

## Faza 2 — Audituri cross-cutting
| Raport | Temă | P0 | P1 |
|---|---|----|----|
| [25-security-appwide.md](25-security-appwide.md) | Securitate app-wide (auth/2FA/authz/secrete) | **1** | 1 |
| [26-infrastructure-docker.md](26-infrastructure-docker.md) | Infrastructură & Docker | 0 | 5 |
| [27-queues-scheduler.md](27-queues-scheduler.md) | Cozi Horizon & Scheduler | **1** | 4 |
| [28-testing-ci.md](28-testing-ci.md) | Testing & CI | 0 | 6 |
| [29-architecture.md](29-architecture.md) | Arhitectură & consistență | 0 | 2 |

**Total: 6 P0, ~71 P1** (unele constatări apar în mai multe rapoarte — vezi AUDIT.md pentru versiunea deduplicată). Cele 6 P0 au fost verificate manual pe fișierele citate în Faza 3.

## Notă de metodă
Fiecare constatare citează `path/to/file.php:line`. Constatările marcate „neverificat" în rapoarte n-au putut fi confirmate integral (ex. existența unui client de agent WP în connector). Auditul nu a modificat niciun fișier de cod — singurele fișiere scrise sunt aceste rapoarte + `AUDIT.md` + `ROADMAP.md`.
