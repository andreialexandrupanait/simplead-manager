<?php

namespace App\Exceptions;

use App\Models\Site;

class WordPressApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?Site $site = null,
        public readonly ?string $endpoint = null,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return array_filter([
            'site_id' => $this->site?->id,
            'site_name' => $this->site?->name,
            'endpoint' => $this->endpoint,
            'http_status' => $this->httpStatus,
        ]);
    }
}
