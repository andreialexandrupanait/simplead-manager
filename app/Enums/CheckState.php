<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Faza D: the binary state of an audit check against a site's crawl data. Null
 * (no enum case) = not yet evaluated / left to manual review. Design decision
 * (locked): no scores, no weights — aggregation is only "X of Y implemented".
 */
enum CheckState: string
{
    case Exista = 'EXISTA';
    case NuExista = 'NU_EXISTA';
    case NuSeAplica = 'NU_SE_APLICA';

    /** The displayed label (with diacritics). Port of V2_STATE_LABELS. */
    public function label(): string
    {
        return match ($this) {
            self::Exista => 'EXISTĂ',
            self::NuExista => 'NU EXISTĂ',
            self::NuSeAplica => 'NU SE APLICĂ',
        };
    }
}
