<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Faza D: which team owns a check's remediation (from methodology-v2/checks.js).
 */
enum AuditTeam: string
{
    case Dev = 'DEV';
    case Continut = 'CONTINUT';
    case Marketing = 'MARKETING';
}
