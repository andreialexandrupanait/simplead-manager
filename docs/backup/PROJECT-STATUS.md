# simplead-backup — PROJECT STATUS (sursă vie de adevăr)

> Actualizat la finalul fiecărei faze interne. Directiva: [`DIRECTIVE-simplead-backup.md`](DIRECTIVE-simplead-backup.md).
> Design: [`TARGET-ARCHITECTURE.md`](TARGET-ARCHITECTURE.md) · Roadmap: [`IMPLEMENTATION-ROADMAP.md`](IMPLEMENTATION-ROADMAP.md)
> Acceptanță: [`ACCEPTANCE-TESTS.md`](ACCEPTANCE-TESTS.md) · Decizii: [`DECISION-LOG.md`](DECISION-LOG.md)

**Verdict curent:** IN PROGRESS (nu READY FOR PILOT).
**Branch:** `feature/simplead-backup-production-ready` (din `feature/snapshot-parity-backup-engine` + merge spike).
**Producție:** neatinsă. V2 dezactivat implicit (toate flag-urile `config/backup_v2.php` = false).

## Stare pe faze

| Fază | Titlu | Stare | Dovezi |
|---|---|---|---|
| P0 | Setup branch / bază / laborator / flags | 🟡 în lucru | branch creat; spike merge-uit; `config/backup_v2.php`; acest fișier |
| P1 | Fundația Laravel (FSM, migrări, observabilitate) | ⬜ | — |
| P2 | Plugin simplead-backup + backup de bază (nucleul) | ⬜ | — |
| P3 | Incremental + chain-uri + retenție | ⬜ | — |
| P4 | Restore (full + selectiv + rollback) | ⬜ | — |
| P5 | UI Manager + UI plugin + alerte + cote | ⬜ | — |
| P6 | Verificare + proven restore + import legacy | ⬜ | — |
| P7 | Pregătire rollout + raport final | ⬜ | — |

Legendă: ✅ trecut poartă · 🟡 în lucru · ⬜ neînceput · ❌ blocat

## Definition of Done (30) — urmărire

Toate ⬜ până sunt demonstrate prin teste. Se completează pe măsură ce fazele trec porțile.
Vezi lista completă în [`DIRECTIVE-simplead-backup.md`](DIRECTIVE-simplead-backup.md) (secțiunea DEFINITION OF DONE).

## Jurnal de faze (cel mai recent sus)

### P0 — Setup (în lucru)
- Branch `feature/simplead-backup-production-ready` creat din `snapshot-parity` (docs) + merge `spike/...` (cod validat).
- `config/backup_v2.php`: feature flags izolate, toate `false` (V2 inert în producție); profile de resurse seed; contract manifest/completion.
- Directiva copiată în repo: `docs/backup/DIRECTIVE-simplead-backup.md`.
- Urmează: laborator Docker (WP/Woo/Multisite/MySQL8/MariaDB11/MinIO) + mediu PHP/Composer de dev + CI.

## Blocaje / limitări curente
- Niciun blocaj extern. Mediul PHP/Composer de dev rulează prin container efemer (host-ul nu are php).
- S3 în laborator = MinIO; Hetzner real doar la pilot (gated de owner).
