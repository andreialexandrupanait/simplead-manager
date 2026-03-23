<?php

declare(strict_types=1);

namespace App\Enums;

enum SslStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Valid => 'Valid',
            self::ExpiringSoon => 'Expiring Soon',
            self::Expired => 'Expired',
            self::Error => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Valid => 'green',
            self::ExpiringSoon => 'yellow',
            self::Expired, self::Error => 'red',
            self::Pending => 'gray',
        };
    }
}
