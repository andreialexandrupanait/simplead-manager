<?php

namespace Tests\Traits;

use App\Models\Client;
use App\Models\Site;

trait CreatesSite
{
    /**
     * Create a site without triggering the booted() cascading events/jobs.
     * Site::booted() dispatches 4 jobs and creates 5 related models on created,
     * which breaks isolated tests.
     */
    protected function createSite(array $attributes = []): Site
    {
        return Site::withoutEvents(fn () => Site::factory()->create($attributes));
    }

    /**
     * Create a site with an existing client.
     */
    protected function createSiteForClient(Client $client, array $attributes = []): Site
    {
        return $this->createSite(array_merge(['client_id' => $client->id], $attributes));
    }

    /**
     * Create multiple sites without triggering cascading events.
     */
    protected function createSites(int $count, array $attributes = []): \Illuminate\Database\Eloquent\Collection
    {
        return Site::withoutEvents(function () use ($count, $attributes) {
            return Site::factory()->count($count)->create($attributes);
        });
    }
}
