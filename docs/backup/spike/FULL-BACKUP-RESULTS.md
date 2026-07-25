# Test 1 — Full backup without a monolithic archive

**Question:** Can we back up DB + core + uploads + plugins + themes + custom dirs **without**
building a whole-site ZIP on the client, keeping temp space bounded, streaming directly to S3?

## Method

Seeded fixtures generated into the live WP tree
(`spike/fixtures/gen-files.php --profile=small --seed=42`): 5,000 files ≈ 750 MB under
`wp-content/uploads/spike`, plus the full WordPress 6.9.4 core/plugins/themes. Ground-truth
sha256 per file recorded (5,000 lines).

The orchestrator drove the **real connector chunked endpoints**:
`prepare-init` (plan chunks) → per chunk `prepare-chunk-exec` → `prepare-chunk-download?delete=1`
(pull-and-free) → stream to MinIO → verify size → record cursor → manifest + composite + `_COMPLETE`.

## Results (measured)

### Files backup (`spikewp.local/files-test1`)
- **20 file chunks** + `manifest.json` + `_COMPLETE` = **22 discrete objects**. Object listing:
  [`data/mc-ls-files-test1.txt`](data/mc-ls-files-test1.txt). **No whole-site archive exists at
  any point.**
- Largest single chunk ≈ **14 MB** (`chunks/0.zip`, WP core); most chunks 80 B–8 MB.
- Wall time ≈ **15.8 s** for the full tree.

### DB backup (`spikewp.local/db-test1`)
- DB (with a 61k-row test dataset) split into **4 chunks** (`database/0..3.sql.gz`) + manifest +
  `_COMPLETE`. Pure-PHP paged dump (connector reports `mysqldump:false`).

### A "full backup" = files session + db session
- Both are collections of small discrete objects under one `{domain}/{type}-{ts}/` prefix.
  Total spike bucket after several sessions: **92 MB across 73 objects** — never one big file.

## No-monolithic-archive: PASS

The connector builds one small ZIP **per chunk** and the orchestrator **skips the recombine
(`prepare-finalize`) step**, streaming each chunk straight to S3. The only code path that would
build a single combined archive (`prepare-finalize` / legacy `run_prepare_work`) was never
invoked.

## Temp-space cap: PASS

Because each chunk is pulled with `delete=1` (removed from WP `/tmp` immediately after the
manager fetches it), WP `/tmp` holds **at most one chunk at a time**.

- Sampler (`data/sampler-files-small.csv`, 1 s cadence) peak `wp_tmp_bytes` = **1.1 MB**
  (the 1 s cadence undersamples the sub-second per-chunk lifetime).
- **Deterministic bound:** peak temp = size of the largest single chunk ≈ **14 MB**, versus a
  **750 MB** source tree — i.e. temp is bounded by *chunk size*, not *backup size*. On a real
  10 GB site this stays ~chunk-sized (≤ the 200 MB dir-split / 100 MB files_list thresholds),
  never approaching site size. **No disk-full risk from a monolithic archive.**

## Completed ⇒ verified: PASS

`_COMPLETE` is written **only after** every chunk object is confirmed present in S3 with matching
size, and the manifest (with a `composite_checksum` = sha256 of the ordered per-chunk sha256s)
is uploaded. A backup cannot be "completed" without a manifest — the exact defect found in
production (191 completed-without-manifest) is structurally prevented here.

## Acceptance mapping

| Criterion | Measurement | Result |
|---|---|---|
| No monolithic archive | `mc ls` = 22 discrete objects, no whole-site zip | ✅ |
| Temp bounded | peak temp ≈ largest chunk (14 MB) ≪ 750 MB | ✅ |
| Direct-to-S3 | each chunk streamed to MinIO, WP `/tmp` freed | ✅ |
| Completed⇒manifest | `_COMPLETE` written after manifest + verify | ✅ |
| Restorable | see [`RESUME-FAILURE-RESULTS.md`](RESUME-FAILURE-RESULTS.md) restore oracle | ✅ |

## Limitations

Small profile only this pass. Medium/large (100k/500k files, 1–5 GB single files) are supported by
the generator but not executed here; the temp-cap argument is size-independent by construction, but
absolute timings on large sites remain to be measured.
