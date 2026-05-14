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
}
