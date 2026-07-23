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
}
