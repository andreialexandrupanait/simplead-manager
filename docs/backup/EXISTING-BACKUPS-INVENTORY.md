# Existing Backups — Inventory & Classification

> Read-only inventory of every backup known to the manager (1,319 DB rows, Mar 12 – Jul 25
> 2026) reconciled against actual objects in Hetzner S3 and Dropbox. Full per-row data:
> [`storage/app/backup-audit/existing-backups.csv`](../../storage/app/backup-audit/existing-backups.csv)
> (no secrets, no signed URLs — `file_path` is a plain object key).
>
> **Method.** DB export via read-only `\copy`. Object presence via each destination's own
> driver `exists()` + `size()` (authoritative path resolution) over **all 1,253 completed
> backups** — not sampled. Storage-side orphans via `listRecursive()` (see limitation below).
> No object was downloaded; no object or row was modified or deleted.

## Classification scheme

| Cat | Meaning | Definition used |
|---|---|---|
| **A** | Valid & keepable | `completed`, object present, size matches, `verification_status=passed` |
| **B** | Probably valid — verify | `completed`, object present, but never verified (or size mismatch) |
| **C** | Recoverable by migration | Object exists under a different prefix/layout; DB path stale |
| **D** | Invalid / incomplete | `failed`/`cancelled`; no usable object |
| **E** | Orphan | Object in storage with **no** DB row |
| **F** | Unknown / phantom | `completed` row but object **not retrievable** at any known path |

## Results

| Cat | Count | Nominal size | Real storage | Notes |
|---|---|---|---|---|
| **A** | **849** | 1,153 GB | **1,153 GB (Hetzner)** | 100% present + size-match + verified. 24 distinct sites. |
| **B** | **57** | 36 GB | 36 GB (Dropbox) | Present but `never_tested`. May 2026 Dropbox era. |
| **C** | **0** | — | — | Prefix-remap (`websites/…`) tested and failed — none recoverable that way. |
| **D** | **66** | ~0 | 0 | `failed` (60) + `cancelled` (6); no object (expected). |
| **E** | ~**3 + ≥29** | — | ~50 GB (Dropbox legacy) | Hetzner: 3 orphan zips. Dropbox: ≥29 legacy zips in `websites/`. Listing incomplete — see limitation. |
| **F** | **347** | 238 GB *nominal* | **0 (object gone)** | `completed` in DB but object absent from Dropbox at recorded path **and** `websites/` prefix. All Dropbox, Mar (93) + Apr (254) 2026. |
| **Total rows** | **1,319** | | | A 849 · B 57 · C 0 · D 66 · F 347 |

### By destination (DB rows)

| Destination | A | B | D | F |
|---|---|---|---|---|
| Hetzner (primary) | 849 | 0 | 62 | 0 |
| Dropbox (legacy primary / replica) | 0 | 57 | 4 | 347 |

## The headline finding (and why it is not a crisis)

**347 "completed" backups are phantom** — the DB says completed, but the object is gone from
Dropbox (verified via authoritative `exists()` at both the recorded path and the `websites/`
legacy prefix; May-2026 Dropbox objects on the *same* code path resolve fine, ruling out a
namespace quirk). These are Mar–Apr 2026, the Dropbox-primary era, and were most plausibly
removed out-of-band (manual cleanup or a pre-dry-run retention pass) while the DB rows survived.

**However, every site in categories B and F also has ≥1 Category-A backup on Hetzner.**
(`sites with B but no A = ∅`; `sites in F with no A = ∅`.) So the phantom rows and the
unverified rows are a **data-integrity / reporting defect** — the manager overstates each
site's restore-point history — **not an actual loss of protection.** No site's only backup is
missing or unverified.

## Reconciliation defects observed

| Defect (user's checklist) | Found? | Detail |
|---|---|---|
| Row in DB without object | **Yes — 347** | Category F (Dropbox Mar–Apr). |
| Object without row (orphan) | **Yes — ≥32** | Category E (Hetzner 3, Dropbox legacy ≥29). |
| `completed` but incomplete | **Yes — 347** | Same as F. |
| `failed` with usable objects | No | 0 — no failed backup had a retrievable object. |
| Abandoned multipart uploads | Not assessed | Requires `ListMultipartUploads` — deferred (read-only, low risk). |
| Broken chain (incremental w/o full base) | No | 23 incrementals, all have a completed parent. |
| Missing checksum | No | 0 — all completed have a checksum. |
| Missing manifest | **Yes — 191** | Completed w/o manifest (185 full). Degrades incremental/verify. |
| Zero-byte object | No | 0. |
| Duplicate object | Not systematically checked | — |

## Limitations (honest scope)

1. **Dropbox `listRecursive` is unreliable** — it returned only 50.8 GB / a legacy `websites/`
   folder while `exists()` finds current objects it omits. Orphan (E) counts on Dropbox are a
   **lower bound**; storage-side Dropbox enumeration cannot be trusted for a full sweep without
   fixing the driver.
2. **Presence ≠ deep integrity.** Category A/B means the object exists at the right size. Full
   archive-open + DB-dump-parse verification was *not* run over all 906 present objects (that
   would require downloading ~1.2 TB). Creation-time `verifyV3Zip` + `verification_status`
   provide the integrity signal used here; a sampled deep-verify is proposed in
   [`ACCEPTANCE-TESTS.md`](ACCEPTANCE-TESTS.md).
3. **Replica presence not exhaustively verified** — the 849 Hetzner backups record Dropbox
   replicas; replica-object presence was not swept (blocked by limitation 1).
4. **`used_bytes` accounting is untrustworthy** (Dropbox 2,346 GB reported vs 50.8 GB listed).

## Per-site protection snapshot

- 24 distinct sites hold ≥1 Category-A (verified, present) backup.
- 23 sites are backup-enabled; 1 enabled site has **never** produced a backup, 2 are stale
  (>48h since last success). These three are the real protection risks (not the phantom rows)
  and are listed in the CSV by `site_id`.

See [`LEGACY-BACKUPS-DECISION.md`](LEGACY-BACKUPS-DECISION.md) for what to keep, migrate,
mark legacy, and (only with approval) delete.
