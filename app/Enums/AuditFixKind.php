<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Faza D4: the kind of fix a recommendation proposes to apply through the WP
 * connector. Drives which (future) connector action executes it. `Content` is the
 * catch-all for changes that have no dedicated connector action yet.
 */
enum AuditFixKind: string
{
    case MetaTitle = 'meta_title';
    case MetaDescription = 'meta_description';
    case ImageAlt = 'image_alt';
    case Redirect = 'redirect';
    case Content = 'content';

    public function label(): string
    {
        return match ($this) {
            self::MetaTitle => 'Meta title',
            self::MetaDescription => 'Meta description',
            self::ImageAlt => 'Alt-text imagini',
            self::Redirect => 'Redirect 301',
            self::Content => 'Conținut (manual)',
        };
    }

    /**
     * Whether a connector action exists to apply this kind automatically. All are
     * false for now — Faza D4 ships the Manager-side proposal + preview only;
     * real application is wired once the connector endpoints land.
     */
    public function isConnectorApplicable(): bool
    {
        return false;
    }
}
