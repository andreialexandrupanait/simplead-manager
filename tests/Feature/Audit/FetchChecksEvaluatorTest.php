<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\CheckState;
use App\Services\Audit\FetchChecksEvaluator;
use App\Services\Audit\Http\RobotsCollector;
use App\Services\Audit\Http\UrlHelper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Faza D (D3b): the fetch-based v2 checks (robots/UA/llms/host-canonical/404) +
 * the robots parser. Port of fetch-checks.ts + robots.ts tests. HTTP is faked —
 * no real network.
 */
class FetchChecksEvaluatorTest extends TestCase
{
    // --- UrlHelper ---------------------------------------------------------

    public function test_url_helper_normalizes_and_derives_origin_and_paths(): void
    {
        $this->assertSame('https://x.ro/', UrlHelper::normalizeUrl('x.ro'));
        $this->assertSame('https://x.ro', UrlHelper::originOf('https://x.ro/some/path?q=1'));
        $this->assertSame('https://x.ro/robots.txt', UrlHelper::resolvePath('https://x.ro/deep/page', '/robots.txt'));
        $this->assertSame('x.ro', UrlHelper::bareDomain('https://www.x.ro/path'));
    }

    // --- robots parser + isAllowed -----------------------------------------

    public function test_robots_longest_matching_rule_wins_allow_beats_disallow_on_tie(): void
    {
        $parsed = RobotsCollector::parse("User-agent: *\nDisallow: /admin\nAllow: /admin/public");

        $this->assertFalse(RobotsCollector::isAllowed($parsed, 'GPTBot', '/admin'));
        $this->assertTrue(RobotsCollector::isAllowed($parsed, 'GPTBot', '/admin/public'));
        $this->assertTrue(RobotsCollector::isAllowed($parsed, 'GPTBot', '/'));
    }

    public function test_robots_most_specific_user_agent_group_wins(): void
    {
        $parsed = RobotsCollector::parse("User-agent: *\nDisallow:\n\nUser-agent: GPTBot\nDisallow: /");

        // GPTBot's own group (Disallow: /) beats the permissive "*" group.
        $this->assertFalse(RobotsCollector::isAllowed($parsed, 'GPTBot', '/'));
        // bingbot only matches "*" → allowed.
        $this->assertTrue(RobotsCollector::isAllowed($parsed, 'bingbot', '/'));
    }

    // --- 6.1 robots AI access ---------------------------------------------

    public function test_61_robots_allows_all_ai_agents_is_exista(): void
    {
        Http::fake(['x.ro/robots.txt' => Http::response("User-agent: *\nAllow: /", 200)]);

        $result = (new FetchChecksEvaluator)->evalRobotsAiV2('https://x.ro');

        $this->assertSame(CheckState::Exista, $result->state);
        $this->assertSame([], $result->evidence['blocati']);
    }

    public function test_61_robots_blocking_one_agent_is_nu_exista(): void
    {
        Http::fake(['x.ro/robots.txt' => Http::response("User-agent: GPTBot\nDisallow: /", 200)]);

        $result = (new FetchChecksEvaluator)->evalRobotsAiV2('https://x.ro');

        $this->assertSame(CheckState::NuExista, $result->state);
        $this->assertSame(['GPTBot'], $result->evidence['blocati']);
    }

    public function test_61_missing_robots_means_everyone_allowed(): void
    {
        Http::fake(['x.ro/robots.txt' => Http::response('nope', 404)]);

        $result = (new FetchChecksEvaluator)->evalRobotsAiV2('https://x.ro');

        $this->assertSame(CheckState::Exista, $result->state);
    }

    // --- 6.2 UA blocking ---------------------------------------------------

    public function test_62_all_uas_get_200_is_exista(): void
    {
        Http::fake(fn () => Http::response('OK', 200));

        $result = (new FetchChecksEvaluator)->evalUaBlockingV2('https://x.ro');

        $this->assertSame(CheckState::Exista, $result->state);
        $this->assertSame([], $result->evidence['blocati']);
    }

    public function test_62_a_403_for_one_ua_is_nu_exista(): void
    {
        Http::fake(function ($request) {
            $ua = $request->header('User-Agent')[0] ?? '';

            return str_contains($ua, 'GPTBot') ? Http::response('', 403) : Http::response('OK', 200);
        });

        $result = (new FetchChecksEvaluator)->evalUaBlockingV2('https://x.ro');

        $this->assertSame(CheckState::NuExista, $result->state);
        $this->assertContains('GPTBot', $result->evidence['blocati']);
    }

    // --- 6.5 llms.txt ------------------------------------------------------

    public function test_65_valid_llms_txt_is_exista(): void
    {
        Http::fake(['x.ro/llms.txt' => Http::response("# llms.txt\n\nAbout this site.", 200, ['Content-Type' => 'text/plain'])]);

        $result = (new FetchChecksEvaluator)->evalLlmsTxtV2('https://x.ro');

        $this->assertSame(CheckState::Exista, $result->state);
    }

    public function test_65_html_served_as_llms_txt_is_nu_exista(): void
    {
        Http::fake(['x.ro/llms.txt' => Http::response('<!doctype html><html><body>404</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $result = (new FetchChecksEvaluator)->evalLlmsTxtV2('https://x.ro');

        $this->assertSame(CheckState::NuExista, $result->state);
    }

    // --- 3.8 real 404 ------------------------------------------------------

    public function test_38_real_404_is_exista_soft_404_is_nu_exista(): void
    {
        Http::fake([
            'x.ro/hard-404' => Http::response('Not found', 404),
            'x.ro/soft-404' => Http::response('<html>home</html>', 200),
        ]);

        $ok = (new FetchChecksEvaluator)->evalReal404V2('https://x.ro', '/hard-404');
        $this->assertSame(CheckState::Exista, $ok->state);

        $soft = (new FetchChecksEvaluator)->evalReal404V2('https://x.ro', '/soft-404');
        $this->assertSame(CheckState::NuExista, $soft->state);
    }

    // --- 3.1 host canonical ------------------------------------------------

    public function test_31_all_variants_reach_one_https_host_in_one_hop_is_exista(): void
    {
        Http::fake([
            'http://x.ro/' => Http::response('', 301, ['Location' => 'https://www.x.ro/']),
            'http://www.x.ro/' => Http::response('', 301, ['Location' => 'https://www.x.ro/']),
            'https://x.ro/' => Http::response('', 301, ['Location' => 'https://www.x.ro/']),
            'https://www.x.ro/' => Http::response('OK', 200),
        ]);

        $result = (new FetchChecksEvaluator)->evalHostCanonicalV2('https://www.x.ro');

        $this->assertSame(CheckState::Exista, $result->state);
        $this->assertSame('www.x.ro', $result->evidence['canonicalHost']);
        $this->assertTrue($result->evidence['singleHop']);
    }

    public function test_31_variant_not_ending_on_canonical_https_is_nu_exista(): void
    {
        Http::fake([
            'http://x.ro/' => Http::response('OK', 200),        // stays on insecure http
            'http://www.x.ro/' => Http::response('', 301, ['Location' => 'https://www.x.ro/']),
            'https://x.ro/' => Http::response('', 301, ['Location' => 'https://www.x.ro/']),
            'https://www.x.ro/' => Http::response('OK', 200),
        ]);

        $result = (new FetchChecksEvaluator)->evalHostCanonicalV2('https://www.x.ro');

        $this->assertSame(CheckState::NuExista, $result->state);
        $this->assertFalse($result->evidence['singleHop']);
    }
}
