<?php

declare(strict_types=1);

namespace App\Backup\V2\Chain;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;

/**
 * Resolves and materialises V2 incremental chains.
 *
 * A chain is a full backup (full_base_id = null) plus the ordered incrementals that reference it
 * (full_base_id = full.id, chain_position = 1,2,3…). This class:
 *
 *  - resolveChain()   — orders [full, inc_1, …, inc_target] for a restore target, refusing a broken
 *                       chain (missing/incomplete base, gap/duplicate in chain_position) BEFORE any
 *                       restore work begins (BrokenChainException).
 *  - baseChainFor()   — the chain a NEW incremental layers on ([full, …, inc_{pos-1}]) — used to
 *                       compute the base_manifest handed to the plugin's files/inventory diff.
 *  - materialize()    — applies full then each incremental in order (new/changed OVERWRITE by path,
 *                       tombstones DELETE) to the exact final file-state a restore must reproduce.
 *  - baseFileState()  — the flattened [{p,sha256,s,m}] base_manifest for a pending incremental.
 *
 * The DB is NOT part of chain materialisation: the architecture dumps the full DB every backup, so a
 * restore always uses the target backup's own (full) DB dump, never a reconstructed one.
 */
final class ChainResolver
{
    /**
     * Ordered chain to restore $target: [full, inc_1, …, target]. A full resolves to just [full].
     *
     * @return list<BackupSession>
     *
     * @throws BrokenChainException
     */
    public function resolveChain(BackupSession $target): array
    {
        if ($this->isFull($target)) {
            return [$target];
        }

        /** @var BackupSession|null $base */
        $base = $target->fullBase;
        if ($base === null || $base->state !== BackupSessionState::Completed) {
            throw BrokenChainException::missingBase($target->id);
        }

        $pos = $target->chain_position;
        if ($pos === null || $pos < 1) {
            throw BrokenChainException::missingPosition($target->id);
        }

        $members = $this->completedIncrements($base->id, $pos);
        $this->assertContiguous($base->id, $members, $pos);

        return array_merge([$base], $members);
    }

    /**
     * The base chain a pending incremental depends on: [full, inc_1, …, inc_{chain_position-1}].
     *
     * @return list<BackupSession>
     *
     * @throws BrokenChainException
     */
    public function baseChainFor(BackupSession $incremental): array
    {
        /** @var BackupSession|null $base */
        $base = $incremental->fullBase;
        if ($base === null || $base->state !== BackupSessionState::Completed || ! $this->isFull($base)) {
            throw BrokenChainException::missingBase($incremental->id);
        }

        $pos = $incremental->chain_position;
        if ($pos === null || $pos < 1) {
            throw BrokenChainException::missingPosition($incremental->id);
        }

        if ($pos === 1) {
            return [$base];
        }

        $prev = $pos - 1;
        $members = $this->completedIncrements($base->id, $prev);
        $this->assertContiguous($base->id, $members, $prev);

        return array_merge([$base], $members);
    }

    /**
     * Apply the chain in order into a final file-state map keyed by relative path.
     *
     * @param  list<BackupSession>  $chain
     * @return array<string, array{p:string,s:int,m:int,sha256:string,chunk_index:int,source_session_id:int,key:?string}>
     *
     * @throws BrokenChainException when a member's manifest is missing/unreadable
     */
    public function materialize(array $chain, ManifestReader $reader): array
    {
        $state = [];

        foreach ($chain as $session) {
            $manifest = $reader->read($session);
            $keyByChunk = $this->fileObjectKeys($manifest);

            // Deletes before adds (a path can't be both in a single backup's diff, but this is the
            // safe order regardless).
            foreach ($this->tombstones($manifest) as $tomb) {
                unset($state[$tomb]);
            }

            foreach ((array) ($manifest['files']['included'] ?? []) as $entry) {
                $p = (string) ($entry['p'] ?? '');
                if ($p === '') {
                    continue;
                }
                $chunkIndex = (int) ($entry['chunk_index'] ?? 0);
                $state[$p] = [
                    'p' => $p,
                    's' => (int) ($entry['s'] ?? 0),
                    'm' => (int) ($entry['m'] ?? 0),
                    'sha256' => (string) ($entry['sha256'] ?? ''),
                    'chunk_index' => $chunkIndex,
                    'source_session_id' => (int) $session->id,
                    'key' => $keyByChunk[$chunkIndex] ?? null,
                ];
            }
        }

        ksort($state);

        return $state;
    }

    /**
     * Flatten the base chain of a pending incremental into the plugin base_manifest format.
     *
     * @return list<array{p:string,sha256:string,s:int,m:int}>
     *
     * @throws BrokenChainException
     */
    public function baseFileState(BackupSession $incremental, ManifestReader $reader): array
    {
        $state = $this->materialize($this->baseChainFor($incremental), $reader);

        $out = [];
        foreach ($state as $e) {
            $out[] = [
                'p' => $e['p'],
                'sha256' => $e['sha256'],
                's' => $e['s'],
                'm' => $e['m'],
            ];
        }

        return $out;
    }

    private function isFull(BackupSession $session): bool
    {
        return $session->full_base_id === null;
    }

    /**
     * @return list<BackupSession> completed incrementals of $baseId at positions 1..$maxPos, ordered.
     */
    private function completedIncrements(int $baseId, int $maxPos): array
    {
        /** @var list<BackupSession> $rows */
        $rows = BackupSession::query()
            ->where('full_base_id', $baseId)
            ->where('type', 'incremental')
            ->where('state', BackupSessionState::Completed->value)
            ->whereNotNull('chain_position')
            ->where('chain_position', '<=', $maxPos)
            ->orderBy('chain_position')
            ->get()
            ->values()
            ->all();

        return $rows;
    }

    /**
     * @param  list<BackupSession>  $members
     *
     * @throws BrokenChainException on a gap, duplicate, or unreachable target position
     */
    private function assertContiguous(int $baseId, array $members, int $target): void
    {
        $expected = 1;
        foreach ($members as $m) {
            $pos = $m->chain_position === null ? null : (int) $m->chain_position;
            if ($pos !== $expected) {
                throw BrokenChainException::gap($baseId, $expected, $pos);
            }
            $expected++;
        }

        $reached = $expected - 1;
        if ($reached !== $target) {
            throw BrokenChainException::unreachableTarget($baseId, $target, $reached);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function tombstones(array $manifest): array
    {
        $raw = $manifest['files']['tombstones'] ?? [];

        return array_values(array_map('strval', (array) $raw));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string> chunk_index => object key (files objects only)
     */
    private function fileObjectKeys(array $manifest): array
    {
        $out = [];
        foreach ((array) ($manifest['objects'] ?? []) as $o) {
            if (($o['kind'] ?? null) === 'files') {
                $out[(int) ($o['chunk_index'] ?? 0)] = (string) ($o['key'] ?? '');
            }
        }

        return $out;
    }
}
