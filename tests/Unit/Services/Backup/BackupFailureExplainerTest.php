<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\BackupFailureExplainer;
use Tests\TestCase;

/**
 * What the engine threw, turned into what the operator should read.
 *
 * The message this exists for is the one four production sites emitted nightly for five days:
 * "The database did not finish dumping in 270 seconds, the most one request may hold the snap" —
 * truncated mid-word by the column it lived in, and silent on what to do next.
 */
class BackupFailureExplainerTest extends TestCase
{
    private BackupFailureExplainer $explainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->explainer = new BackupFailureExplainer;
    }

    public function test_the_dump_budget_failure_names_the_budget_and_the_fix(): void
    {
        $result = $this->explainer->explain(
            'The database did not finish dumping in 270 seconds, the most one request may hold the snap'
        );

        $this->assertStringContainsString('270', $result['message']);
        $this->assertNotNull($result['action']);
    }

    public function test_an_http_failure_names_the_code(): void
    {
        $result = $this->explainer->explain('simplead-backup database/dump returned HTTP 500');

        $this->assertStringContainsString('500', $result['message']);
        $this->assertNotNull($result['action']);
    }

    public function test_a_storage_hiccup_says_nothing_was_lost(): void
    {
        $result = $this->explainer->explain('Error executing "HeadObject" on "https://nbg1.your-objectstorage.com/sad/..."');

        $this->assertNotNull($result['action']);
        $this->assertStringNotContainsString('HeadObject', $result['message']);
    }

    public function test_an_empty_error_does_not_pretend_to_know_why(): void
    {
        $result = $this->explainer->explain(null);

        $this->assertNotSame('', $result['message']);
        $this->assertNull($result['action']);
    }

    /**
     * A message we cannot improve is still better than a vague catch-all — "Something went wrong"
     * throws away the only clue anyone had.
     */
    public function test_an_unrecognised_error_is_passed_through_rather_than_replaced(): void
    {
        $result = $this->explainer->explain('Some novel failure nobody has classified yet');

        $this->assertStringContainsString('novel failure', $result['message']);
        $this->assertNull($result['action']);
    }
}
