<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * The result of one phase of a per-site operation. Each phase
 * (prepare/execute/verify/rollback) returns one of these; the runner reads the
 * outcome to decide progress accounting and whether a rollback is warranted.
 */
final class OperationResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public OperationOutcome $outcome,
        public int $siteId,
        public ?string $message = null,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(int $siteId, ?string $message = null, array $data = []): self
    {
        return new self(OperationOutcome::Success, $siteId, $message, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function failure(int $siteId, ?string $message = null, array $data = []): self
    {
        return new self(OperationOutcome::Failure, $siteId, $message, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function skipped(int $siteId, ?string $message = null, array $data = []): self
    {
        return new self(OperationOutcome::Skipped, $siteId, $message, $data);
    }

    /**
     * A successful verification. Semantically identical to success() but named
     * for readability at the verify() call site.
     *
     * @param  array<string, mixed>  $data
     */
    public static function verified(int $siteId, ?string $message = null, array $data = []): self
    {
        return new self(OperationOutcome::Success, $siteId, $message, $data);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === OperationOutcome::Success;
    }

    public function isSkipped(): bool
    {
        return $this->outcome === OperationOutcome::Skipped;
    }

    public function isFailure(): bool
    {
        return $this->outcome === OperationOutcome::Failure;
    }
}
