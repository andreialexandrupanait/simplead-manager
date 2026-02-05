<?php

namespace Tests\Unit\Services;

use App\Models\SeoCheck;
use App\Services\SeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class SeoServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    public function test_fetch_and_store_creates_seo_check_record(): void
    {
        $this->fakeWordPressApi();
        $site = $this->createSite();

        $service = new SeoService();
        $check = $service->fetchAndStore($site);

        $this->assertInstanceOf(SeoCheck::class, $check);
        $this->assertDatabaseHas('seo_checks', [
            'site_id' => $site->id,
        ]);
    }

    public function test_fetch_and_store_stores_data_from_api_response(): void
    {
        $this->fakeWordPressApi();
        $site = $this->createSite();

        $service = new SeoService();
        $check = $service->fetchAndStore($site);

        $this->assertEquals('Test Site', $check->homepage_title);
        $this->assertEquals('A test site description', $check->homepage_meta_description);
        $this->assertTrue($check->has_sitemap);
        $this->assertTrue($check->has_robots_txt);
        $this->assertTrue($check->has_og_tags);
        $this->assertTrue($check->has_canonical);
        $this->assertTrue($check->has_h1);
    }

    public function test_calculate_score_returns_max_100_for_all_features_present(): void
    {
        $data = [
            'homepage_title' => str_repeat('A', 55), // 50-60 chars = +10
            'homepage_meta_description' => str_repeat('B', 155), // 150-160 chars = +10
            'has_sitemap' => true, // +15
            'has_robots_txt' => true, // +10 (no issues)
            'robots_txt_issues' => [],
            'has_og_tags' => true, // +10
            'has_schema_markup' => true, // +15
            'indexability_issues' => [], // +20
            'has_h1' => true, // +10 (with hierarchy)
            'heading_hierarchy_ok' => true,
        ];

        $service = new SeoService();
        $score = $service->calculateScore($data);

        $this->assertEquals(100, $score);
    }

    public function test_calculate_score_deducts_for_missing_sitemap(): void
    {
        $allPresent = [
            'homepage_title' => str_repeat('A', 55),
            'homepage_meta_description' => str_repeat('B', 155),
            'has_sitemap' => true,
            'has_robots_txt' => true,
            'robots_txt_issues' => [],
            'has_og_tags' => true,
            'has_schema_markup' => true,
            'indexability_issues' => [],
            'has_h1' => true,
            'heading_hierarchy_ok' => true,
        ];

        $withoutSitemap = array_merge($allPresent, ['has_sitemap' => false]);

        $service = new SeoService();
        $fullScore = $service->calculateScore($allPresent);
        $reducedScore = $service->calculateScore($withoutSitemap);

        $this->assertLessThan($fullScore, $reducedScore);
        $this->assertEquals($fullScore - 15, $reducedScore);
    }

    public function test_calculate_score_deducts_for_missing_robots_txt(): void
    {
        $withRobots = [
            'homepage_title' => '',
            'homepage_meta_description' => '',
            'has_sitemap' => false,
            'has_robots_txt' => true,
            'robots_txt_issues' => [],
            'has_og_tags' => false,
            'has_schema_markup' => false,
            'indexability_issues' => [],
            'has_h1' => false,
            'heading_hierarchy_ok' => false,
        ];

        $withoutRobots = array_merge($withRobots, ['has_robots_txt' => false]);

        $service = new SeoService();
        $withScore = $service->calculateScore($withRobots);
        $withoutScore = $service->calculateScore($withoutRobots);

        $this->assertLessThan($withScore, $withoutScore);
    }

    public function test_calculate_score_deducts_for_missing_og_tags(): void
    {
        $withOg = [
            'homepage_title' => '',
            'homepage_meta_description' => '',
            'has_sitemap' => false,
            'has_robots_txt' => false,
            'has_og_tags' => true,
            'has_schema_markup' => false,
            'indexability_issues' => [],
            'has_h1' => false,
            'heading_hierarchy_ok' => false,
        ];

        $withoutOg = array_merge($withOg, ['has_og_tags' => false]);

        $service = new SeoService();
        $withScore = $service->calculateScore($withOg);
        $withoutScore = $service->calculateScore($withoutOg);

        $this->assertEquals(10, $withScore - $withoutScore);
    }

    public function test_get_recommendations_returns_array_of_recommendations(): void
    {
        $site = $this->createSite();

        $check = SeoCheck::create([
            'site_id' => $site->id,
            'homepage_title' => null,
            'homepage_meta_description' => null,
            'has_sitemap' => false,
            'has_robots_txt' => false,
            'has_og_tags' => false,
            'has_twitter_cards' => false,
            'has_schema_markup' => false,
            'has_canonical' => false,
            'has_h1' => false,
            'heading_hierarchy_ok' => false,
            'score' => 0,
            'checked_at' => now(),
        ]);

        $service = new SeoService();
        $recommendations = $service->getRecommendations($check);

        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);

        // Should have recommendations for all missing features
        $titles = array_column($recommendations, 'title');
        $this->assertContains('Add a title tag', $titles);
        $this->assertContains('Create an XML sitemap', $titles);
        $this->assertContains('Add schema markup', $titles);
    }

    public function test_fetch_and_store_updates_existing_check(): void
    {
        $this->fakeWordPressApi();
        $site = $this->createSite();

        $service = new SeoService();
        $firstCheck = $service->fetchAndStore($site);
        $secondCheck = $service->fetchAndStore($site);

        // Both should be created as separate records (SeoCheck::create, not updateOrCreate)
        $this->assertNotEquals($firstCheck->id, $secondCheck->id);
        $this->assertEquals(2, SeoCheck::where('site_id', $site->id)->count());
    }
}
