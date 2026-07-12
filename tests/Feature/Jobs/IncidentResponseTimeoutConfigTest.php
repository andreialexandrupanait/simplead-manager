<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CreateBackup;
use App\Jobs\RunIncidentResponse;
use ReflectionClass;
use Tests\TestCase;

/**
 * P2-56: RunIncidentResponse runs nested SYNCHRONOUS work — createBackup() uses
 * CreateBackup::dispatchSync() (its own budget) and runSafeUpdate() dispatchSyncs
 * another backup. If the incident job's timeout were below the nested backup
 * budget, the worker would be SIGKILLed mid-backup. This config-consistency test
 * pins the invariant: the incident-response job (and its Horizon supervisor)
 * timeout must be >= the nested CreateBackup dispatchSync budget.
 */
class IncidentResponseTimeoutConfigTest extends TestCase
{
    private function defaultTimeout(string $class): int
    {
        return (int) (new ReflectionClass($class))->getDefaultProperties()['timeout'];
    }

    public function test_incident_job_timeout_covers_nested_backup_budget(): void
    {
        $incidentTimeout = $this->defaultTimeout(RunIncidentResponse::class);
        $backupTimeout = $this->defaultTimeout(CreateBackup::class);

        $this->assertGreaterThanOrEqual(
            $backupTimeout,
            $incidentTimeout,
            'RunIncidentResponse::$timeout must be >= CreateBackup::$timeout so a nested '
                .'dispatchSync backup is never SIGKILLed mid-operation.'
        );
    }

    public function test_incident_unique_lock_outlives_the_job_timeout(): void
    {
        $incidentTimeout = $this->defaultTimeout(RunIncidentResponse::class);
        $uniqueFor = (int) (new ReflectionClass(RunIncidentResponse::class))->getDefaultProperties()['uniqueFor'];

        $this->assertGreaterThan(
            $incidentTimeout,
            $uniqueFor,
            'uniqueFor must exceed the job timeout so a killed worker cannot hold a stale lock forever.'
        );
    }

    public function test_horizon_supervisor_timeout_covers_nested_backup_budget(): void
    {
        $supervisorTimeout = (int) config('horizon.defaults.supervisor-incident-response.timeout');
        $backupTimeout = $this->defaultTimeout(CreateBackup::class);

        $this->assertGreaterThanOrEqual(
            $backupTimeout,
            $supervisorTimeout,
            'The supervisor-incident-response timeout must be >= CreateBackup::$timeout.'
        );
    }
}
