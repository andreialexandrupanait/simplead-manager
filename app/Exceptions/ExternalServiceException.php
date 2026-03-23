<?php

declare(strict_types=1);

namespace App\Exceptions;

class ExternalServiceException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $service,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return array_filter([
            'service' => $this->service,
            'http_status' => $this->httpStatus,
        ]);
    }
}
