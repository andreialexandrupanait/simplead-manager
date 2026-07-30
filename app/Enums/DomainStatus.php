<?php

declare(strict_types=1);

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Error = 'error';
    /** The registry does not publish an expiry date (e.g. ROTLD/.ro). Not an error. */
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::ExpiringSoon => 'Expiring Soon',
            self::Expired => 'Expired',
            self::Error => 'Error',
            self::Unsupported => 'Expiry N/A',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::ExpiringSoon => 'yellow',
            self::Expired, self::Error => 'red',
            self::Pending, self::Unsupported => 'gray',
        };
    }
}
