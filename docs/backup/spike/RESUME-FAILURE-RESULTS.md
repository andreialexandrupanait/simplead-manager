# Test 2 — Resume after interruption

**Question:** After an interruption, does the backup resume from the last confirmed checkpoint —
never restarting from zero, never duplicating chunks, always ending complete and restorable?

## Checkpoint / cursor design (validated)

The orchestrator owns a JSON cursor (`spike/scratch/cursor-<session>-<type>.json`,
sample: [`data/resume-cursor-test2.json`](data/resume-cursor-test2.json)):

```json
{"prefix":"spikewp.local/files-test2","type":"files",
 "confirmed":{"0":{"s3key":".../chunks/0.zip","sha256":"...","size":15256667}, ...}}
```

- A chunk becomes **`confirmed` only after** its object is verified present in S3 with matching
  size (S3 = truth). The manifest/composite advance only over confirmed chunks.
- **Idempotency key** = the chunk index → the S3 key `{prefix}/chunks/{i}.zip` is stable, so a
  retry overwrites the same key (never `5.zip` + `5(1).zip`).
- On restart, confirmed chunks are skipped; unconfirmed ones re-execute. The connector side is
  independently idempotent: `prepare-chunk-exec` returns `skipped:true` if the `chunk_N.done`
  marker **and** the output file both still exist, else it rebuilds the chunk.

## Injection A — kill orchestrator mid-backup (EXECUTED)

1. Started `files-test2`; killed the orchestrator (`kill -9`) after chunks **[0,1]** confirmed
   (cursor + S3 both showed exactly 2 objects).
2. Resumed (`backup.sh files test2 resume`):

```
chunk 0 already confirmed (resume skip)
chunk 1 already confirmed (resume skip)
chunk 2 OK ... chunk 19 OK
composite 6aad038b25b1681a28cf589c9b6e78b7b2e4dd83451c02d13ff2c6aae8d55da9 objects 20
```

**Post-conditions (all met):**
- Final object count = **20 chunks + manifest + `_COMPLETE`** (22), **0 duplicate keys**.
- Did **not** restart from zero — chunks 0,1 skipped.
- Composite checksum `6aad038b…` is **byte-identical to the uninterrupted `files-test1` run** →
  interruption + resume produced an identical, deterministic result.

## Injection B — duplicate operation / idempotent chunk-exec (EXECUTED)

Called `prepare-chunk-exec` for the same fresh chunk twice:
```
first exec:  {"success":true,"chunk_index":0,"chunk_size":126500}
second exec: {"success":true,"chunk_index":0,"chunk_size":126500,"skipped":true}
```
The duplicate is a no-op (`skipped:true`) — a duplicate/redelivered request cannot produce a
second object.

## Injection C — duplicate/replayed callback (EXECUTED, at auth layer)

Re-sending an identical signed request (same nonce) is rejected:
```
first use HTTP: 200 ; replay: {"code":"NONCE_REUSED", ... "status":401}
```
So a replayed callback cannot double-advance state.

## Restore reproduces content (EXECUTED)

`verify-restore.sh` on the **resumed** backup:
```
composite_recomputed 6aad038b… manifest 6aad038b… MATCH
restore-oracle: ok=5000 missing=0 mismatch=0   RESULT: PASS
```
Every one of the 5,000 ground-truth files was reproduced byte-for-byte after downloading +
extracting the chunk objects. The resumed backup is fully restorable.

## Designed, NOT executed this pass

The following injections are specified in the plan and supported by the same cursor/idempotency
primitives, but were not empirically run here (time-bounded): WP container restart, manager-worker
restart (distinct from orchestrator kill), S3 network cut, MinIO pause mid-part, **presigned-URL
expiry** (needs S3Driver multipart path — see below), disk-full `DiskSpaceGuard` trip, overlapping
sessions vs `SiteOperationLock`. The multipart abort-vs-continue logic (persist `mp_upload_id`,
`abortMultipartUpload` on expiry/mismatch, `ListMultipartUploads` = 0 dangling) is designed but not
exercised because this run used `mc` for uploads rather than the manager's `S3Driver` presign path.

## Acceptance mapping

| Criterion | Result |
|---|---|
| No restart-from-zero | ✅ (skipped confirmed chunks) |
| No duplicate chunks | ✅ (0 duplicate keys) |
| Manifest not advanced before confirmation | ✅ (composite over confirmed only) |
| Idempotent duplicate op / callback | ✅ (`skipped:true`, nonce 401) |
| Final backup complete + restorable | ✅ (5000/5000 restore oracle) |
| Reproducible | ✅ (composite identical to clean run) |
