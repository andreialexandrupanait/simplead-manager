<?php

declare(strict_types=1);

namespace App\Enums;

enum IncidentResponseStatus: string
{
    case Pending = 'pending';
    case Diagnosing = 'diagnosing';
    case Executing = 'executing';
    case Resolved = 'resolved';
    case Failed = 'failed';
    case Escalated = 'escalated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Diagnosing => 'Diagnosing',
            self::Executing => 'Executing',
            self::Resolved => 'Resolved',
            self::Failed => 'Failed',
            self::Escalated => 'Escalated',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Diagnosing => 'blue',
            self::Executing => 'purple',
            self::Resolved => 'green',
            self::Failed => 'red',
            self::Escalated => 'orange',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'clock',
            self::Diagnosing => 'search',
            self::Executing => 'loader',
            self::Resolved => 'check-circle',
            self::Failed => 'x-circle',
            self::Escalated => 'arrow-up-circle',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Failed, self::Escalated]);
    }
}
