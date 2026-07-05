<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\WordPressApiServiceInterface;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
