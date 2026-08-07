<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Services\SettingsService;

/**
 * Branding for outgoing email.
 *
 * Deliberately narrower than ReportLogoService, which resolves a per-site,
 * per-template logo for PDF rendering and therefore needs a Site and a
 * ReportTemplate to construct. Email has neither.
 *
 * The logo rules are dictated by email clients, not by taste:
 *  - CSS filters do not exist, so the `brightness-0 invert` trick the app
 *    layout uses to whiten the mark on its dark rail is unavailable here.
 *  - Gmail refuses `<img src="data:...">`, so the base64 embedding used for
 *    PDFs would render as a broken image for most recipients.
 *  - SVG is not rendered at all by Outlook and several mobile clients.
 *
 * So `logoPath()` returns a raster file, to be embedded as a CID attachment via
 * `$message->embed()`.
 *
 * It deliberately does NOT use `branding.logo`. That setting holds the colour
 * mark meant for light backgrounds — navy wordmark on transparent — and the
 * mail header band is dark, where it would be invisible. What ships instead is
 * a pre-rendered white-on-transparent PNG of the same mark, the print equivalent
 * of the `brightness-0 invert` the app applies on its own dark sidebar. It is
 * pre-rendered because the app image has no SVG rasteriser: ImageMagick's
 * rsvg-convert delegate is absent, so there is no way to produce it at runtime.
 */
class EmailBrandingService
{
    private const DEFAULT_APP_NAME = 'SimpleAd Manager';

    private const DEFAULT_ACCENT = '#2271b1';

    public function __construct(private SettingsService $settings) {}

    public function appName(): string
    {
        $name = $this->settings->get('app_name');

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_APP_NAME;
    }

    public function accentColor(): string
    {
        $color = $this->settings->get('branding.accent_color');

        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1
            ? $color
            : self::DEFAULT_ACCENT;
    }

    /**
     * Absolute filesystem path of a logo that email clients can actually render,
     * or null when the wordmark should be used instead.
     */
    public function logoPath(): ?string
    {
        $packaged = resource_path('images/mail-logo-on-dark.png');

        return file_exists($packaged) ? $packaged : null;
    }
}
