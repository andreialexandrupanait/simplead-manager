<?php

declare(strict_types=1);

namespace App\Backup\V2\Plugin;

/**
 * The manager-side contract for driving the simplead-backup WP plugin over its
 * HMAC-signed REST namespace (simplead-backup/v1).
 *
 * An interface so the orchestrator (App\Backup\V2\Orchestration\BackupRunner) can
 * be driven by the real HTTP client in the lab AND by an in-process fake in
 * deterministic contract tests (empty-chunk skip, resume idempotency).
 */
interface PluginClient
{
    /**
     * GET/POST capabilities — host/DB/PHP probe used to pick a strategy.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array;

    /**
     * POST files/inventory — walk ABSPATH, apply exclusion rules, return totals +
     * exclusion_policy_hash + the chunk plan summary. Persists the plan server-side
     * under $sessionId so chunk-exec/-download need only (session_id, chunk_index).
     *
     * @param  list<mixed>  $rules  exclusion/inclusion rules (see SAM_Backup_Exclusions)
     * @param  array<string, mixed>  $options  include_defaults|threshold|compression|preview
     * @return array<string, mixed>
     */
    public function filesInventory(string $sessionId, array $rules, array $options = []): array;

    /**
     * POST database/dump — run the consistent logical dump into $sessionId's temp
     * dir and return the segment manifest (segments[].file/sha256/sizes, done,
     * consistent, ...).
     *
     * @param  array<string, mixed>  $params  time_budget|segment_bytes|exclude_tables
     * @return array<string, mixed>
     */
    public function dbDump(string $sessionId, array $params = []): array;

    /**
     * POST database/chunk-download — pull DB segment N to a local temp file;
     * $deleteAfter frees the plugin's copy (pull-and-free).
     */
    public function dbChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk;

    /**
     * POST files/chunk-exec — materialise ONE file chunk in the plugin temp.
     * Empty chunks come back empty=true / chunk_size=0 (contract: skip).
     *
     * @return array<string, mixed>
     */
    public function filesChunkExec(string $sessionId, int $chunkIndex): array;

    /**
     * POST files/chunk-download — pull file chunk N (zip) to a local temp file;
     * $deleteAfter frees the plugin's copy (pull-and-free).
     */
    public function filesChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk;
}
