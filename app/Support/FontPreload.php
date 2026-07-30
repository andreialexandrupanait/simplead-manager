<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolve the built Inter woff2 so the layout can <link rel="preload"> it.
 *
 * Without a preload the font request only starts after the CSS is parsed and
 * the first layout is done — late enough to be visible. The file name carries a
 * build hash, so it cannot be hard-coded; and fonts referenced from CSS (rather
 * than JS) are not reliably listed in Vite's manifest, so the manifest cannot be
 * trusted either. Globbing the built assets works regardless of Vite version.
 *
 * Memoised per directory for the request — this runs on every page render.
 */
final class FontPreload
{
    /** @var array<string, string|null> */
    private static array $cache = [];

    /**
     * Public URL of the primary (latin) Inter woff2, or null when the assets
     * have not been built — a fresh checkout or the dev server, in which case
     * the page simply renders without the hint.
     *
     * @param  string|null  $dir  build-assets directory; defaults to public/build/assets
     */
    public static function interHref(?string $dir = null): ?string
    {
        $dir ??= public_path('build/assets');

        if (array_key_exists($dir, self::$cache)) {
            return self::$cache[$dir];
        }

        // Only the latin subset is preloaded: it covers the entire UI. Preloading
        // latin-ext too would fetch a second file most pages never draw from.
        $matches = is_dir($dir) ? (glob($dir.'/inter-latin-wght-normal*.woff2') ?: []) : [];

        return self::$cache[$dir] = $matches === []
            ? null
            : '/build/assets/'.basename($matches[0]);
    }

    /** Test seam — the memo would otherwise leak between cases. */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
