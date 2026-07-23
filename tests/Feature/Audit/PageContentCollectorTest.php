<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Services\Audit\Http\PageContentCollector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Faza D (D3): the page-content collector — URL classification, representative
 * page selection, and content extraction. Port of page-content.ts tests. HTML is
 * faked; no real network.
 */
class PageContentCollectorTest extends TestCase
{
    public function test_classify_url_by_path(): void
    {
        $this->assertSame('homepage', PageContentCollector::classifyUrl('https://x.ro/'));
        $this->assertSame('contact', PageContentCollector::classifyUrl('https://x.ro/contact'));
        $this->assertSame('produs', PageContentCollector::classifyUrl('https://x.ro/produs/abc'));
        $this->assertSame('categorie', PageContentCollector::classifyUrl('https://x.ro/magazin/xyz'));
        $this->assertSame('articol', PageContentCollector::classifyUrl('https://x.ro/blog/post'));
        $this->assertSame('alta', PageContentCollector::classifyUrl('https://x.ro/random-page'));
    }

    public function test_select_representative_pages_picks_one_of_each_type_and_filters(): void
    {
        $candidates = [
            ['url' => 'https://x.ro/', 'status' => 200, 'indexable' => true, 'isHtml' => true],
            ['url' => 'https://x.ro/produs/a', 'status' => 200, 'indexable' => true, 'isHtml' => true],
            ['url' => 'https://x.ro/blog/post', 'status' => 200, 'indexable' => true, 'isHtml' => true],
            ['url' => 'https://x.ro/contact', 'status' => 200, 'indexable' => true, 'isHtml' => true],
            ['url' => 'https://x.ro/rupt', 'status' => 404, 'indexable' => true, 'isHtml' => true],
            ['url' => 'https://x.ro/noindex', 'status' => 200, 'indexable' => false, 'isHtml' => true],
        ];

        $chosen = PageContentCollector::selectRepresentativePages($candidates, 'https://x.ro', 6);

        $this->assertSame('https://x.ro/', $chosen[0]); // homepage first
        $this->assertContains('https://x.ro/produs/a', $chosen);
        $this->assertContains('https://x.ro/contact', $chosen);
        $this->assertContains('https://x.ro/blog/post', $chosen);
        $this->assertNotContains('https://x.ro/rupt', $chosen);      // 404 filtered
        $this->assertNotContains('https://x.ro/noindex', $chosen);   // non-indexable filtered
        $this->assertLessThanOrEqual(6, count($chosen));
    }

    public function test_select_representative_pages_always_includes_homepage(): void
    {
        $chosen = PageContentCollector::selectRepresentativePages([], 'https://x.ro');
        $this->assertSame(['https://x.ro/'], $chosen);
    }

    public function test_collect_extracts_the_qualitative_signals(): void
    {
        $html = <<<'HTML'
            <html><head><title>Acme Shop</title><meta name="description" content="Cel mai bun magazin"></head>
            <body>
              <h1>Bine ai venit</h1>
              <h2 id="faq">Întrebări frecvente?</h2>
              <a class="btn" href="/cos">Cumpără acum</a>
              <a href="/despre-noi">Află mai multe</a>
              <form>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" required>
                <button type="submit">Trimite mesajul</button>
              </form>
              <div class="testimonial">Produs excelent, îl recomand oricui!</div>
              <p>Preț special: 199,99 lei</p>
              <script type="application/ld+json">{"@type":"Product","name":"X"}</script>
              <a href="https://wa.me/40712345678">Scrie-ne pe WhatsApp</a>
              <div class="tawk-messenger"></div>
            </body></html>
            HTML;
        Http::fake(['x.ro/*' => Http::response($html, 200)]);

        $page = (new PageContentCollector)->collect('https://x.ro', 'homepage');

        $this->assertSame('Acme Shop', $page['title']);
        $this->assertSame('Cel mai bun magazin', $page['metaDescription']);
        $this->assertSame('homepage', $page['classification']);

        $headingTexts = array_column($page['headings'], 'text');
        $this->assertContains('Bine ai venit', $headingTexts);
        $this->assertContains('Întrebări frecvente?', $headingTexts);

        $this->assertContains('Cumpără acum', $page['ctas']);
        $this->assertContains('Află mai multe', $page['ctas']);

        $this->assertCount(1, $page['forms']);
        $this->assertSame('email', $page['forms'][0]['fields'][0]['name']);
        $this->assertTrue($page['forms'][0]['fields'][0]['required']);
        $this->assertSame('Trimite mesajul', $page['forms'][0]['submitText']);

        $this->assertTrue($page['hasFaq']);
        $this->assertContains('Întrebări frecvente?', $page['faqSample']);

        $this->assertNotEmpty($page['prices']);
        $this->assertStringContainsString('lei', $page['prices'][0]);

        $this->assertContains('Product', $page['jsonLdTypes']);
        $this->assertTrue($page['hasWhatsApp']);
        $this->assertTrue($page['hasChatWidget']);
        $this->assertGreaterThanOrEqual(1, $page['socialProof']['testimonials']);
        $this->assertNotSame('', $page['visibleText']);
        $this->assertGreaterThan(0, $page['textLength']);
    }

    public function test_collect_on_a_connection_failure_returns_the_empty_shape(): void
    {
        Http::fake(['x.ro/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        $page = (new PageContentCollector)->collect('https://x.ro');

        $this->assertNull($page['status']);
        $this->assertSame([], $page['headings']);
        $this->assertSame('', $page['visibleText']);
    }
}
