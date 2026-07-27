<?php

declare(strict_types=1);

namespace App\Backup\V2\Chain;

use RuntimeException;

/**
 * Thrown when an incremental chain cannot be resolved into a complete, ordered,
 * restorable sequence [full, inc_1, …, inc_N] — a missing/incomplete base full, a
 * gap or duplicate in chain_position, or a member whose manifest is missing or
 * corrupt.
 *
 * It is raised BEFORE any restore work begins: a broken chain fails loud and
 * early rather than silently materialising a partial (data-losing) state.
 */
final class BrokenChainException extends RuntimeException
{
    public static function missingBase(int $sessionId): self
    {
        return new self(
            "Broken chain: session {$sessionId} references a base full that is missing or not completed."
        );
    }

    public static function missingPosition(int $sessionId): self
    {
        return new self(
            "Broken chain: incremental session {$sessionId} has no chain_position."
        );
    }

    public static function gap(int $baseId, int $expected, ?int $found): self
    {
        $foundStr = $found === null ? 'nothing' : "position {$found}";

        return new self(
            "Broken chain (base full {$baseId}): expected incremental at position {$expected}, found {$foundStr}."
        );
    }

    public static function unreachableTarget(int $baseId, int $target, int $reached): self
    {
        return new self(
            "Broken chain (base full {$baseId}): target position {$target} unreachable — only {$reached} contiguous increments are completed."
        );
    }

    public static function missingManifest(int $sessionId, string $why): self
    {
        return new self(
            "Broken chain: manifest for session {$sessionId} is missing or unreadable ({$why})."
        );
    }
}
