<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\ManifestReader;
use App\Backup\V2\Models\BackupSession;

/**
 * Turns a restore TARGET (a completed backup, possibly the tip of an incremental chain) plus a
 * restore SCOPE into the concrete transfer plan the RestoreRunner executes:
 *
 *   - fileChunks : the distinct S3 file-chunk objects to pull + push, ORDERED so a later chunk
 *                  overwrites an earlier one (chain order → latest-wins). Each carries a `seq` the
 *                  plugin extracts in ascending order.
 *   - keepPaths  : the EXACT final-state relative paths (after latest-wins + tombstones). Staging is
 *                  pruned to this set so a chunk that also carried a since-deleted/overwritten file
 *                  can never resurrect it.
 *   - dbChunks   : the TARGET backup's own DB segments (the architecture dumps the FULL DB every
 *                  backup, so a restore always uses the target's dump — never a reconstructed one).
 *   - tombstones : paths deleted across the chain (mirror belt-and-braces; pruning already covers it).
 *   - dbTables   : optional table whitelist (selective DB restore).
 *   - mirrorRoots: the relative roots MIRROR mode may delete live-only files under (scope-bounded).
 *
 * Scope (RestoreSession.scope):
 *   database:bool  files:bool  paths:list<string>(rel prefixes)  db_tables:list<string>
 * Absent keys default to a full restore (database+files, all paths, all tables).
 */
final class RestorePlan
{
    /**
     * @param  list<array{seq:int,key:string,source_session_id:int,chunk_index:int,bytes:int}>  $fileChunks
     * @param  list<string>  $keepPaths
     * @param  list<array{seq:int,key:string,chunk_index:int}>  $dbChunks
     * @param  list<string>  $tombstones
     * @param  list<string>  $dbTables
     * @param  list<string>  $mirrorRoots
     * @param  int  $fileBytes  logical size of the files this restore puts back, from the manifest
     * @param  int  $largestChunkBytes  the biggest single chunk, i.e. the working space one apply step needs
     */
    public function __construct(
        public readonly array $fileChunks,
        public readonly array $keepPaths,
        public readonly array $dbChunks,
        public readonly array $tombstones,
        public readonly array $dbTables,
        public readonly array $mirrorRoots,
        public readonly bool $includeDatabase,
        public readonly bool $includeFiles,
        public readonly int $fileBytes = 0,
        public readonly int $largestChunkBytes = 0,
    ) {}

    /**
     * @param  array<string, mixed>|null  $scope
     */
    public static function build(
        BackupSession $target,
        ChainResolver $resolver,
        ManifestReader $reader,
        ?array $scope,
    ): self {
        $scope ??= [];
        $includeFiles = (bool) ($scope['files'] ?? true);
        $includeDatabase = (bool) ($scope['database'] ?? true);

        /** @var list<string> $pathFilters */
        $pathFilters = array_values(array_map(
            static fn ($p): string => trim((string) $p, '/'),
            array_filter((array) ($scope['paths'] ?? []), static fn ($p): bool => (string) $p !== ''),
        ));
        /** @var list<string> $dbTables */
        $dbTables = array_values(array_map('strval', (array) ($scope['db_tables'] ?? [])));

        // ── files: the restore point's final state, filtered by scope paths ──
        $keepPaths = [];
        $fileChunks = [];
        $fileBytes = 0;
        $largestChunkBytes = 0;
        if ($includeFiles) {
            // One manifest for format/2; the chain replay only for older backups. Either way the
            // state that comes back already has latest-wins applied, so no path appears twice.
            $state = $resolver->stateFor($target, $reader);

            $keyOrder = []; // key => ['order'=>sourceSessionId,'chunk_index'=>..,'sid'=>..]
            $bytesByKey = [];
            foreach ($state as $path => $entry) {
                if (! self::pathInScope((string) $path, $pathFilters)) {
                    continue;
                }
                $keepPaths[] = (string) $path;
                $key = (string) ($entry['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                // Per chunk, not just in total: the apply now works one chunk at a time, so what
                // decides whether the site has room is the biggest single chunk, not the sum.
                $bytesByKey[$key] = ($bytesByKey[$key] ?? 0) + (int) $entry['s'];
                $sid = (int) $entry['source_session_id'];
                $keyOrder[$key] = [
                    // Ordering by the backup that produced the chunk, oldest first. It used to be the
                    // position in the resolved chain, which meant a plan could not be built at all
                    // unless the whole chain still resolved — a dependency a format/2 restore point
                    // does not have. Session ids are monotonic, so the order is the same one.
                    'order' => $sid,
                    'chunk_index' => (int) $entry['chunk_index'],
                    'sid' => $sid,
                ];
            }
            sort($keepPaths);

            // Order chunks so an older chunk is extracted (and thus overwritten) first.
            uasort($keyOrder, static function (array $a, array $b): int {
                return [$a['order'], $a['chunk_index']] <=> [$b['order'], $b['chunk_index']];
            });
            $seq = 0;
            foreach ($keyOrder as $key => $meta) {
                $fileChunks[] = [
                    'seq' => $seq++,
                    'key' => (string) $key,
                    'source_session_id' => (int) $meta['sid'],
                    'chunk_index' => (int) $meta['chunk_index'],
                    'bytes' => (int) ($bytesByKey[(string) $key] ?? 0),
                ];
            }
            $fileBytes = array_sum($bytesByKey);
            $largestChunkBytes = $bytesByKey === [] ? 0 : max($bytesByKey);
        }

        // ── tombstones (scope-filtered), for the MIRROR belt ──
        // Only the manifests this restore point actually depends on: its own for format/2, the whole
        // chain for format/1. A path deleted long ago is already simply absent from the state above,
        // so this stays a belt rather than the mechanism.
        $tombstones = [];
        foreach ($resolver->membersToVerify($target, $reader) as $session) {
            $manifest = $reader->read($session);
            foreach ((array) ($manifest['files']['tombstones'] ?? []) as $t) {
                $t = trim((string) $t, '/');
                if ($t !== '' && self::pathInScope($t, $pathFilters) && ! in_array($t, $keepPaths, true)) {
                    $tombstones[] = $t;
                }
            }
        }
        $tombstones = array_values(array_unique($tombstones));

        // ── db: the target's OWN full dump segments (never reconstructed) ──
        $dbChunks = [];
        if ($includeDatabase) {
            $targetManifest = $reader->read($target);
            $segments = [];
            foreach ((array) ($targetManifest['objects'] ?? []) as $o) {
                if (($o['kind'] ?? null) === 'database') {
                    $segments[(int) ($o['chunk_index'] ?? 0)] = (string) ($o['key'] ?? '');
                }
            }
            ksort($segments);
            foreach ($segments as $chunkIndex => $key) {
                if ($key === '') {
                    continue;
                }
                $dbChunks[] = ['seq' => (int) $chunkIndex, 'key' => $key, 'chunk_index' => (int) $chunkIndex];
            }
        }

        // ── mirror roots: explicit scope paths, else the covered top segments ──
        $mirrorRoots = $pathFilters !== []
            ? $pathFilters
            : self::topSegments($keepPaths);

        return new self(
            fileChunks: $fileChunks,
            keepPaths: $keepPaths,
            dbChunks: $dbChunks,
            tombstones: $tombstones,
            dbTables: $dbTables,
            mirrorRoots: $mirrorRoots,
            includeDatabase: $includeDatabase,
            includeFiles: $includeFiles,
            fileBytes: (int) $fileBytes,
            largestChunkBytes: (int) $largestChunkBytes,
        );
    }

    /**
     * @param  list<string>  $filters
     */
    private static function pathInScope(string $path, array $filters): bool
    {
        if ($filters === []) {
            return true;
        }
        foreach ($filters as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinct first path segments (e.g. wp-content, wp-admin) — the scope-bounded roots a full
     * MIRROR may delete live-only files under, so it never touches a sibling tree the backup omitted.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function topSegments(array $paths): array
    {
        $roots = [];
        foreach ($paths as $p) {
            $seg = explode('/', $p, 2)[0];
            if ($seg !== '') {
                $roots[$seg] = true;
            }
        }

        return array_keys($roots);
    }
}
