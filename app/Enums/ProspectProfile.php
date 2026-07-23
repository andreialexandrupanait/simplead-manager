<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Faza D: business profile of a prospect/site, used to decide check applicability
 * (e.g. e-commerce-only CRO checks are NU_SE_APLICA for non-ecommerce).
 */
enum ProspectProfile: string
{
    case B2bServicii = 'B2B_SERVICII';
    case Ecommerce = 'ECOMMERCE';
    case Local = 'LOCAL';
}
