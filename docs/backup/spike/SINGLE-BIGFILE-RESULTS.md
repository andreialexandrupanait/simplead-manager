# Spike — Single-Big-File Round (incompressible `large`, MariaDB 11, Multisite)

> Third and final spike pass. Closes the one item the earlier rounds left open (the honesty
> note in [`CONTINUATION-RESULTS.md`](CONTINUATION-RESULTS.md) §1): a **single incompressible
> file larger than the chunk-split threshold**, plus **MariaDB 11** and **WordPress Multisite**.
> Same isolated `sam_spike` stack, existing volumes reused; the MariaDB 11 engine was added as a
> **separate sidecar service + own volume** (existing mysql:8.0 volume untouched). No
> production / client-site / real-S3 contact. All numbers measured. _Date: 2026-07-27._

## 0. What changed vs prior rounds

- **Truly incompressible fixtures.** `fixtures/gen-files.php` previously filled each block with
  `str_repeat()` of one 32-byte hash → highly compressible (8.9 GB → 87 MB in S3 last round).
  Fixed: every 32-byte segment is now a **fresh** `sha256(seed|path|counter)` → full entropy.
  Verified: a 256 MB sample **grows** under `gzip -1` (268,435,456 → 268,481,082 bytes, ratio
  1.0002). Generator stays memory-flat: **peak 4 MB** regardless of file size.
- New targeted profile `large-single`: 8,000 incompressible small files **+ one 2 GB single file**
  (`uploads/2025/01/huge_0.bin`, exactly 2,147,483,648 bytes). Total ABSPATH fixture ≈ 3.23 GB.

## 1. The single-big-file case — the actual unknown

The connector chunks files by **grouping** them under size thresholds
(`split_large_directory`, 100 MB). A file *larger* than that threshold cannot be grouped, so it
becomes **one chunk by itself** → chunk size = file size, and the chunk zip of incompressible
content ≈ the file size. This was the one case that could have forced *intra-file streaming
multipart*. It does not.

Measured (backup `bigfile1`, 30 planned chunks, in-container sampler @ ~10 Hz):

| Metric | Value | Meaning |
|---|---|---|
| Chunk for the 2 GB file | **chunk 22 = 2,147,831,457 B** | single file → single chunk; zip ≈ file size (incompressible, +0.02 %) |
| Every other chunk | **≤ 105 MB** | grouping honours the 100 MB `files_list` threshold |
| **WP `/tmp` peak** | **2,050 MB** | == the one big chunk; **not** the 3.23 GB source (pull-and-free) |
| `/tmp` between chunks | **~2 MB** | each chunk pulled with `delete=1` and freed before the next |
| **Real RSS (`cgroup anon`) peak** | **137 MB** | **flat** across the whole 2 GB zip → no intra-file buffering |
| PHP heap limit in exec | 512 MB | never approached; 137 MB ≪ 512 MB |
| OOMKilled / restarts | **false / 0** | container memory limit = 2 GB, never tripped |
| Wall time | 1:51 | zip + pull + MinIO multipart for 30 chunks |

**Reading the RAM number correctly.** `ps` RSS for the worker peaks at ~1,977 MB during the 2 GB
zip — but that is **mmap'd page cache** (reclaimable), not heap. The authoritative kernel counter
`cgroup memory.stat: anon` stays at **137 MB**, and `file` (reclaimable cache) is what tracks the
2 GB; the cgroup reclaimed clean cache to stay under its 2 GB limit (no OOM). ZipArchive
`addFile()` + `close()` and the `readfile()` download both stream. If anything buffered the file,
the 512 MB PHP limit would have thrown, or the 2 GB cgroup would have OOM-killed — neither happened.

Temp timeline (chunk 22): `/tmp` climbs 228 → 2,050 MB while `anon` holds flat at ~137 MB, then
drops back to 2 MB the instant the chunk is pulled.

**Verdict on temp-cap:** confirmed and now size-independent *including* the pathological case.
Temp is bounded by **`max(largest single file, 100 MB grouping threshold)`**. The only real-world
implication (documented, not a blocker): a site with an N-GB single file needs ≈ N GB free temp on
both WP host and manager during that one chunk. Intra-file streaming multipart is **not** required
for files up to a few GB on hosts with a few GB of temp.

## 2. Restore + integrity — incl. the 2 GB file

Level-B verify-before-complete (recompute composite from the downloaded objects) + restore-oracle
(every ground-truth `sha256` re-checked after extraction):

| Check | Result |
|---|---|
| Composite checksum (manifest vs recomputed) | **MATCH** |
| Restore-oracle | **ok = 8,001 / missing = 0 / mismatch = 0 → PASS** |
| 2 GB file after restore | size 2,147,483,648, sha256 `fd383cc4…` == ground truth |

## 3. Finding: empty-chunk contract (fixed in the spike harness)

The first run surfaced a real contract gap. `exec_files_chunk` returns `chunk_size=0` and
**deletes** the zip for a chunk that matched no files (empty `wp-content/upgrade`,
`wp-content/uploads/2026`), yet still writes the `.done` marker and reports success.
`prepare-chunk-download` then returns **HTTP 404** with a JSON error body. A naive puller that
only checks "response body non-empty" **stored the 404 JSON as if it were chunk data** (82/83-byte
objects) and folded them into the manifest + composite.

No data was lost (the dirs were genuinely empty; oracle still 8,001/8,001), but this is a
**must-fix for the production manager**:

- Treat `exec` `chunk_size == 0` as *nothing to pull* — skip download, **omit from the manifest**.
- Validate the download is a real chunk (HTTP 200 / expected content), never trust "non-empty body".

Applied to `orchestrator/backup.sh` (+ `verify-restore.sh` now iterates the manifest's actual,
non-contiguous object indices). Re-run `bigfile2`: **28 clean objects, composite MATCH,
restore-oracle 8,001/8,001 PASS**, second sampler run reconfirms `/tmp` 2,050 MB / anon 135 MB /
no OOM.

## 4. MariaDB 11 + WordPress Multisite — DB consistency

MariaDB **11.8.8** sidecar, Multisite schema `wp_2_*` / `wp_3_*`. Invariant: every
`wp_N_postmeta.post_id` references an existing `wp_N_posts.ID`. A writer created posts the
Multisite way (post + its postmeta, transactionally) on both sub-sites while dumping.

| Dump method | Orphan postmeta |
|---|---|
| Connector paged (posts-then-postmeta, per-page connection, no txn) | **44** |
| Single `START TRANSACTION WITH CONSISTENT SNAPSHOT` (one connection) | **0** |

Same result as MySQL 8.0 / WooCommerce-HPOS last round (22 → 0). The consistent-snapshot design
decision holds **across engine (MySQL 8.0 & MariaDB 11) and schema (HPOS & Multisite)**. The
current chunked-DB-across-HTTP model (new connection per chunk) **cannot** be consistent and must
be replaced by a one-connection consistent-snapshot dump.

## 5. Final verdict

**Architecture VALIDATED — GO.** Every previously-open item is now closed with measured data:

- Single incompressible file > chunk threshold → temp bounded by chunk size, **real RSS flat at
  137 MB**, no intra-file streaming needed, restore byte-identical.
- MariaDB 11 + Multisite reconfirm the consistent-snapshot DB fix.

**Carry into the production build (unchanged + one addition):**

1. DB dump in **one connection** inside `START TRANSACTION WITH CONSISTENT SNAPSHOT` (not
   chunked-DB-per-HTTP-request). — validated on MySQL 8.0 **and** MariaDB 11.
2. Shorten `S3Driver` presigned TTL from `+4h` to short, per-part just-in-time.
3. **NEW — empty-chunk contract:** manager skips `chunk_size==0` chunks (never pulls/manifests
   them) and validates each downloaded chunk (HTTP 200 / valid archive), never "non-empty body".
4. Optional hardening: if a customer can hold single files larger than available temp, add
   intra-file streaming multipart. Not required for the common case (few-GB files, few-GB temp).
