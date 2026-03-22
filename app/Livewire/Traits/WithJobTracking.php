<?php

namespace App\Livewire\Traits;

use App\Services\JobTracker;

trait WithJobTracking
{
    public array $trackedJobs = [];

    public bool $hasRunningJobs = false;

    /**
     * Define tracked job keys: ['logicalName' => 'cache-key', ...]
     */
    abstract protected function jobTrackingKeys(): array;

    /**
     * Call from mount() to detect already-running jobs on page load.
     */
    public function initJobTracking(): void
    {
        $this->checkJobProgress();
    }

    /**
     * Dispatch a job and pre-seed the tracker so UI updates instantly.
     */
    public function dispatchTrackedJob(string $name, $job, string $message = 'Starting...'): void
    {
        $keys = $this->jobTrackingKeys();
        $cacheKey = $keys[$name] ?? null;
        if (! $cacheKey) {
            return;
        }

        JobTracker::start($cacheKey, $message);
        dispatch($job);

        $this->trackedJobs[$name] = JobTracker::get($cacheKey);
        $this->hasRunningJobs = true;
    }

    /**
     * Called by wire:poll — reads cache and updates component state.
     */
    public function checkJobProgress(): void
    {
        $keys = $this->jobTrackingKeys();
        $this->hasRunningJobs = false;

        foreach ($keys as $name => $cacheKey) {
            $data = JobTracker::get($cacheKey);

            if (! $data) {
                if (isset($this->trackedJobs[$name])) {
                    unset($this->trackedJobs[$name]);
                }

                continue;
            }

            $previousStatus = $this->trackedJobs[$name]['status'] ?? null;

            // Don't show jobs that finished before this page loaded
            if ($previousStatus === null && in_array($data['status'], ['complete', 'failed'])) {
                continue;
            }

            $this->trackedJobs[$name] = $data;

            if ($data['status'] === 'running') {
                $this->hasRunningJobs = true;
            } elseif (in_array($data['status'], ['complete', 'failed']) && $previousStatus === 'running') {
                $this->onJobFinished($name, $data);
            }
        }
    }

    /**
     * Override in component to refresh data when a job finishes.
     */
    protected function onJobFinished(string $jobName, array $data): void
    {
        // Override in component
    }
}
