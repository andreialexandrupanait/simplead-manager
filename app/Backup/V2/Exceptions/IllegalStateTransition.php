<?php

declare(strict_types=1);

namespace App\Backup\V2\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller attempts a state transition that is not present in the
 * legal transition graph of a V2 FSM (BackupStateMachine / RestoreStateMachine).
 */
final class IllegalStateTransition extends RuntimeException
{
    public function __construct(
        public readonly string $machine,
        public readonly string $from,
        public readonly string $to,
    ) {
        parent::__construct(
            sprintf('Illegal %s transition: %s → %s', $machine, $from, $to)
        );
    }

    public static function for(string $machine, \BackedEnum $from, \BackedEnum $to): self
    {
        return new self($machine, (string) $from->value, (string) $to->value);
    }
}
