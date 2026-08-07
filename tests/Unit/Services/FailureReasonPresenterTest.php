<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Uptime\FailureReasonPresenter;
use Tests\TestCase;

class FailureReasonPresenterTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function causes(): array
    {
        return [
            'http status' => ['HTTP 503', 'is returning HTTP 503 Service Unavailable'],
            'unmapped http status' => ['HTTP 599', 'is returning HTTP 599'],
            'missing keyword' => ['Keyword not found', 'loaded without the keyword we monitor'],
            'unwanted keyword' => ['Unwanted keyword found', 'is showing the keyword we watch out for'],
            'tcp port' => ['TCP connect to shop.example:8443 failed: Connection refused (111)', 'is refusing connections on port 8443'],
            'dns' => ['cURL error 6: Could not resolve host: shop.example', 'could not be resolved in DNS'],
            'refused' => ['cURL error 7: Failed to connect to shop.example port 443', 'is refusing connections'],
            'reset' => ['Error: connect ECONNRESET 185.243.134.219:443', 'is resetting the connection'],
            'certificate' => ['cURL error 60: SSL certificate problem: certificate has expired', 'is presenting an invalid TLS certificate'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('causes')]
    public function test_it_turns_a_stored_reason_into_a_sentence(string $cause, string $expected): void
    {
        $this->assertSame($expected, FailureReasonPresenter::clause($cause));
    }

    public function test_a_timeout_names_the_configured_budget_when_it_is_known(): void
    {
        $cause = 'cURL error 28: Operation timed out after 30001 milliseconds';

        $this->assertSame('did not respond within 30 seconds', FailureReasonPresenter::clause($cause, 30));
        $this->assertSame('did not respond in time', FailureReasonPresenter::clause($cause));
    }

    public function test_an_unrecognised_reason_is_quoted_rather_than_flattened(): void
    {
        $cause = 'Upstream sent an unparseable frame';

        $this->assertSame('is returning "Upstream sent an unparseable frame"', FailureReasonPresenter::clause($cause));
    }

    public function test_an_empty_reason_still_produces_a_sentence(): void
    {
        $this->assertSame('is not responding', FailureReasonPresenter::clause(null));
        $this->assertSame('is not responding', FailureReasonPresenter::clause(''));
    }

    public function test_the_second_location_suffix_is_lifted_out_of_the_reason(): void
    {
        $cause = 'HTTP 502 (confirmed from FRA)';

        $this->assertSame('is returning HTTP 502 Bad Gateway', FailureReasonPresenter::clause($cause));
        $this->assertSame('We confirmed this independently from our FRA probe.', FailureReasonPresenter::confirmation($cause));
        $this->assertNull(FailureReasonPresenter::confirmation('HTTP 502'));
    }
}
