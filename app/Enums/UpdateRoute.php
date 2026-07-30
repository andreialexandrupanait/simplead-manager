<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Faza 6 (SPEC §7.2/§7.3) — the three routes an available plugin update can be
 * sent down by the smart update-decision engine.
 *
 *  - AutoMinor       minor/patch, low-risk → apply automatically via the safe
 *                    update pipeline, no human in the loop.
 *  - AwaitApproval   major bump, site-flagged-risky, or an unknown/risky AI
 *                    assessment → hold for a human to approve.
 *  - CriticalBypass  an active *critical* vulnerability with a reachable fix →
 *                    update now, skipping the approval gate (security wins).
 */
enum UpdateRoute: string
{
    case AutoMinor = 'auto_minor';
    case AwaitApproval = 'await_approval';
    case CriticalBypass = 'critical_bypass';

    public function label(): string
    {
        return match ($this) {
            self::AutoMinor => 'Auto (minor)',
            self::AwaitApproval => 'Awaiting approval',
            self::CriticalBypass => 'Critical security bypass',
        };
    }
}
