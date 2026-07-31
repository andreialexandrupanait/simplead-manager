# Specification and build prompts

These are the source of truth for the §-numbered work referenced throughout the
codebase ("SPEC §7.4", "SPEC §12.3", and so on). They lived untracked in the repo
root for months, which meant the document every comment cites could not be read
from a fresh clone and had no history when it changed.

| File | What it is |
|---|---|
| `SPEC-SAD-MANAGER.md` | The product spec. The § numbers in code comments point here. |
| `PROMPT-SAD-MANAGER-CLAUDE-CODE.md` | The seven-phase plan for getting there. |
| `PROMPT-FAZA-1-AUTONOM.md` | The Faza 1 deletion brief. |
| `PROMPT-BACKUP-V2.md` | The backup-v2 engine brief. |
| `REFERINTA-VIZUALA.html` | The visual reference the app was re-themed against. |

The spec is meant to stay small: "Nimic nu intră în el fără să iasă altceva."
When the code deliberately departs from it, the reason belongs in a comment at
the point of departure, not in a revision here — see `SafeUpdateService` on the
pixel-diff stage and `BrokenLinkChecker` on the Redirects module for the shape
that takes.
