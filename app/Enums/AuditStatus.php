<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Faza D: lifecycle of an audit — from configured, through data collection and
 * AI drafting, human validation, to a published public report.
 */
enum AuditStatus: string
{
    case Configurat = 'CONFIGURAT';
    case Colectare = 'COLECTARE';
    case Draft = 'DRAFT';
    case InValidare = 'IN_VALIDARE';
    case Validat = 'VALIDAT';
    case Publicat = 'PUBLICAT';

    /** The displayed label (with diacritics). */
    public function label(): string
    {
        return match ($this) {
            self::Configurat => 'Configurat',
            self::Colectare => 'Colectare',
            self::Draft => 'Draft',
            self::InValidare => 'În validare',
            self::Validat => 'Validat',
            self::Publicat => 'Publicat',
        };
    }

    /** The x-ui.badge variant for this status. */
    public function badge(): string
    {
        return match ($this) {
            self::Configurat => 'gray',
            self::Colectare => 'blue',
            self::Draft => 'yellow',
            self::InValidare => 'orange',
            self::Validat => 'green',
            self::Publicat => 'purple',
        };
    }
}
