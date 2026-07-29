<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\Contracts\Operation;
use App\Operations\Operations\PurgeCloudflareCacheOperation;

/**
 * Maps a stable operation key to its concrete {@see Operation} class and
 * resolves it from the container. Bound as a singleton (see AppServiceProvider)
 * so runtime registrations persist for the request/worker lifetime.
 */
class OperationRegistry
{
    /** @var array<string, class-string<Operation>> */
    private array $map = [
        'cloudflare.purge' => PurgeCloudflareCacheOperation::class,
    ];

    /**
     * Register (or override) an operation key → class mapping.
     *
     * @param  class-string<Operation>  $class
     */
    public function register(string $key, string $class): void
    {
        $this->map[$key] = $class;
    }

    /**
     * Resolve an operation instance by key.
     */
    public function resolve(string $key): Operation
    {
        if (! isset($this->map[$key])) {
            throw new \InvalidArgumentException("Unknown operation key: {$key}");
        }

        return app($this->map[$key]);
    }

    /**
     * @return array<string, class-string<Operation>>
     */
    public function all(): array
    {
        return $this->map;
    }
}
