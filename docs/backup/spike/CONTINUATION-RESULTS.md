# Spike — Continuation Results (medium profile, WooCommerce/HPOS, remaining injections, real S3 multipart)

> Second pass extending the spike with larger scale, real WooCommerce HPOS, more failure
> injections, and the real S3 multipart path. Same isolated stack; no production/client-site/real-S3
> contact. All numbers measured.

## 1. Medium profile — full backup at scale

Fixtures generated into the live WP tree → **ABSPATH = 71,381 files / 8.9 GB** (WP core + 5k small
+ 56.6k medium-profile files + WooCommerce).

| Metric | Value |
|---|---|
| Chunks | **106** file chunks + `manifest.json` + `_COMPLETE` = **108 objects** |
| Duplicates | **0** |
| Wall time | **2:00** (71k-file pure-PHP walk + per-chunk round-trips; CPU 21% → I/O-bound) |
| Orchestrator peak RSS | **29 MB** (flat, streaming) |
| WP `/tmp` peak (0.3 s sampler) | **43.5 MB** vs **8.9 GB** source |
| Restore oracle | **56,624 / 56,624 files sha256 match, 0 missing, 0 mismatch**; composite MATCH |

**No-monolith + temp-cap hold at 5× the file count.** Peak WP `/tmp` is bounded by the largest
single chunk, not the site size.

> **Honesty note on sizes:** the fixture content is highly compressible (8.9 GB → 87 MB in S3), so
> absolute *chunk/backup sizes* here are not representative of incompressible media. The temp-cap
> conclusion is **size-independent by construction** — WP `/tmp` is bounded by the chunk-split
> thresholds (dir 200 MB / files_list 100 MB), regardless of source size — and the 71k-file / 2-min
> walk time is real. A truly-incompressible large run (real chunk sizes near the thresholds) remains
> on the pre-production checklist.

## 2. WooCommerce HPOS — DB consistency on real Woo tables

Installed **WooCommerce 10.9.4 with HPOS enabled** (`wp_wc_orders`, `wp_wc_order_product_lookup`,
`wp_wc_order_stats`). A writer created orders the HPOS way (order + line items + stats,
transactionally) while dumping.

| Method | Orphan order line-items |
|---|---|
| Connector paged (table-by-table, no transaction) | **22** (broken cross-table FK on real HPOS) |
| Single `START TRANSACTION WITH CONSISTENT SNAPSHOT` | **0** |

Confirms the Test 3 finding **on the actual WooCommerce HPOS schema**: the current dump tears
(dangling order lines), and the single-connection consistent-snapshot fixes it — no change-journal.
(`id` in `wp_wc_orders` has no auto-increment — Woo allocates from the posts id space — noted for the
production dumper.)

## 3. Failure injections (empirically executed this pass)

| # | Injection | Result |
|---|---|---|
| WP container restart | Restarted `spike-wp` mid-session → chunk session **survived on the `spike_wp_tmp` volume**: re-exec chunk 0 → `skipped:true`, still downloadable. Resume does not recompute completed chunks. | ✅ |
| MinIO pause (S3 unavailable) | Paused MinIO mid-backup → **0 chunks confirmed, 0 objects** (no corrupt/partial confirmed). Unpaused → resume completed 6 DB chunks, **0 duplicates**. | ✅ |

Together with pass 1 (kill-orchestrator resume, idempotent duplicate exec, nonce replay) → **7
injection classes** validated. Not run (manager-side, covered by existing unit tests
`DiskSpaceGuardTest` / `SiteOperationLockTest`): disk-full guard, overlapping-session lock.

## 4. Real S3 multipart + presigned expiry (the P0 revision)

Validated directly against MinIO with the AWS CLI (mirrors `S3Driver`'s multipart methods):

| Step | Result |
|---|---|
| `create-multipart-upload` → UploadId | ✅ |
| `upload-part` ×2 (5 MB each) → ETags | ✅ |
| `list-multipart-uploads` (in-progress) | shows 1 |
| **`abort-multipart-upload` → `list` = 0** | **no dangling parts** ✅ |
| fresh multipart → `complete` | object assembled, **size = 10,485,760 (10 MB)** ✅ |
| presigned URL, 3 s TTL, used after 5 s | **HTTP 403** (expired) ✅ |
| presigned URL, 120 s TTL | **HTTP 200** ✅ |

This closes the "exercise real multipart + shorter presigned TTL" revision: initiate/upload/complete/
**abort-with-no-dangling** and presigned-expiry enforcement all behave correctly on S3-compatible
storage.

## Updated verdict impact

- Test 3 fix confirmed on real HPOS. Multipart/abort/presigned-expiry validated (revision 2 closed).
- Still on the pre-production checklist: **large** profile with **incompressible** content (real
  chunk sizes), full multi-table Woo/HPOS desync at high write rate, Multisite, `mariadb:11`, and
  the manager-side disk-full / overlapping-lock paths wired end-to-end.
