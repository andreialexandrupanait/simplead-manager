<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\FetchSiteFavicon;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep the favicon fetch off the network.
     *
     * Site::created() dispatches FetchSiteFavicon, the test queue is `sync`, and
     * the job makes up to three HTTP requests with a 15s timeout each. The suite
     * calls Site::factory() around 480 times, and Faker hands out real domains
     * (hyatt.com, langosh.com), so every full run genuinely phoned hundreds of
     * strangers' servers. That, not the assertions, was most of the runtime — and
     * it made the wall-clock depend on internet latency rather than on the code.
     *
     * Nothing tests the favicon fetch; three test files had already worked around
     * this one at a time with exactly this line. Faking ONLY this job matters:
     * ApplyPlanToSite is dispatched by the same hook and several tests depend on
     * it running synchronously.
     *
     * A test that wants the real thing can still call Queue::fake() or
     * Http::fake() itself — the later call wins.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([FetchSiteFavicon::class]);
    }

    protected function createMockApi(): WordPressApiServiceInterface
    {
        return $this->createMock(WordPressApiServiceInterface::class);
    }

    protected function createMockApiFactory(?WordPressApiServiceInterface $api = null): WordPressApiServiceFactory
    {
        $api ??= $this->createMockApi();
        $factory = $this->createMock(WordPressApiServiceFactory::class);
        $factory->method('make')->willReturn($api);

        return $factory;
    }

    /**
     * Bind a scriptable connector fake into the container so anything that
     * resolves WordPressApiServiceFactory (jobs, services, Livewire) gets it.
     */
    protected function fakeApi(): Fakes\FakeWordPressApiService
    {
        $fake = new Fakes\FakeWordPressApiService;

        $factory = new class($fake) extends WordPressApiServiceFactory
        {
            public function __construct(private readonly Fakes\FakeWordPressApiService $fake) {}

            public function make(\App\Models\Site $site): WordPressApiServiceInterface
            {
                return $this->fake;
            }
        };

        $this->app->instance(WordPressApiServiceFactory::class, $factory);

        return $fake;
    }
}
