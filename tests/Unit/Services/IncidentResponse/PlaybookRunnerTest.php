<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IncidentResponse;

use App\Enums\IncidentTriggerType;
use App\Services\IncidentResponse\PlaybookRunner;
use PHPUnit\Framework\TestCase;

class PlaybookRunnerTest extends TestCase
{
    private PlaybookRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new PlaybookRunner;
    }

    public function test_site_down_trigger_matches_site_down_playbook(): void
    {
        $playbook = $this->runner->findPlaybook(IncidentTriggerType::SiteDown, []);

        $this->assertNotNull($playbook);
        $this->assertSame('site_down', $playbook->name());
    }

    public function test_vulnerability_trigger_matches_vulnerable_plugin_playbook(): void
    {
        $playbook = $this->runner->findPlaybook(IncidentTriggerType::Vulnerability, []);

        $this->assertNotNull($playbook);
        $this->assertSame('vulnerable_plugin', $playbook->name());
    }

    public function test_security_critical_trigger_matches_security_playbook(): void
    {
        $playbook = $this->runner->findPlaybook(IncidentTriggerType::SecurityCritical, []);

        $this->assertNotNull($playbook);
        $this->assertSame('security_critical', $playbook->name());
    }

    public function test_performance_drop_trigger_matches_performance_playbook(): void
    {
        $playbook = $this->runner->findPlaybook(IncidentTriggerType::PerformanceDrop, []);

        $this->assertNotNull($playbook);
        $this->assertSame('performance_drop', $playbook->name());
    }

    public function test_database_critical_trigger_matches_database_playbook(): void
    {
        $playbook = $this->runner->findPlaybook(IncidentTriggerType::DatabaseCritical, []);

        $this->assertNotNull($playbook);
        $this->assertSame('database_critical', $playbook->name());
    }

    public function test_first_match_semantics(): void
    {
        // SiteDown should always return the SiteDown playbook, not a later one
        $playbook1 = $this->runner->findPlaybook(IncidentTriggerType::SiteDown, []);
        $playbook2 = $this->runner->findPlaybook(IncidentTriggerType::SiteDown, []);

        $this->assertSame($playbook1->name(), $playbook2->name());
    }

    public function test_all_trigger_types_have_matching_playbook(): void
    {
        foreach (IncidentTriggerType::cases() as $trigger) {
            $playbook = $this->runner->findPlaybook($trigger, []);
            $this->assertNotNull($playbook, "No playbook found for trigger: {$trigger->value}");
        }
    }

    public function test_playbook_names_are_unique(): void
    {
        $names = [];
        foreach (IncidentTriggerType::cases() as $trigger) {
            $playbook = $this->runner->findPlaybook($trigger, []);
            if ($playbook) {
                $names[] = $playbook->name();
            }
        }

        $this->assertSame(count($names), count(array_unique($names)));
    }

    public function test_run_returns_false_when_no_playbook_found(): void
    {
        // Create a runner with empty playbooks list to force null
        $runner = new class extends PlaybookRunner
        {
            public function __construct()
            {
                // Don't call parent — no playbooks
            }

            public function findPlaybook(\App\Enums\IncidentTriggerType $trigger, array $context): ?\App\Services\IncidentResponse\Contracts\PlaybookInterface
            {
                return null;
            }
        };

        $response = $this->createMock(\App\Models\IncidentResponse::class);
        $site = $this->createMock(\App\Models\Site::class);
        $executor = $this->createMock(\App\Services\IncidentResponse\IncidentActionExecutor::class);

        $result = $runner->run($response, $site, IncidentTriggerType::SiteDown, $executor, []);

        $this->assertFalse($result);
    }
}
