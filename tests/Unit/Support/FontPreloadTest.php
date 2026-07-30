<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\FontPreload;
use Tests\TestCase;

/**
 * The preload hint is what makes `font-display: swap` cheap: without it the
 * font download only starts after first layout. The href cannot be hard-coded
 * because Vite hashes the filename at build time.
 */
class FontPreloadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        FontPreload::flush();
        $this->dir = sys_get_temp_dir().'/font-preload-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            rmdir($this->dir);
        }

        FontPreload::flush();
        parent::tearDown();
    }

    public function test_it_finds_the_hashed_latin_font(): void
    {
        mkdir($this->dir, 0777, true);
        touch($this->dir.'/inter-latin-wght-normal-D8b4ck7Q.woff2');

        $this->assertSame(
            '/build/assets/inter-latin-wght-normal-D8b4ck7Q.woff2',
            FontPreload::interHref($this->dir),
        );
    }

    public function test_it_preloads_only_the_latin_subset(): void
    {
        mkdir($this->dir, 0777, true);
        touch($this->dir.'/inter-latin-ext-wght-normal-AAAA1111.woff2');

        // latin-ext alone must not be preloaded — most pages never draw from it.
        $this->assertNull(FontPreload::interHref($this->dir));
    }

    public function test_an_unbuilt_checkout_simply_gets_no_hint(): void
    {
        // No build directory at all: the page must still render, just without
        // the preload link.
        $this->assertNull(FontPreload::interHref($this->dir.'/does-not-exist'));
    }

    public function test_the_lookup_is_memoised_per_directory(): void
    {
        mkdir($this->dir, 0777, true);
        touch($this->dir.'/inter-latin-wght-normal-FIRST123.woff2');

        $first = FontPreload::interHref($this->dir);

        // Change the file on disk; the memo must not re-glob on every render.
        unlink($this->dir.'/inter-latin-wght-normal-FIRST123.woff2');
        touch($this->dir.'/inter-latin-wght-normal-SECOND99.woff2');

        $this->assertSame($first, FontPreload::interHref($this->dir));
    }
}
