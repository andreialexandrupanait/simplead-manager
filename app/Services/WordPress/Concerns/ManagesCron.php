<?php

namespace App\Services\WordPress\Concerns;

trait ManagesCron
{
    public function getCronList(): array
    {
        $response = $this->request('GET', '/cron-list');
        $response->throw();

        return $response->json();
    }

    public function runCron(string $hook, ?array $args = null): array
    {
        $data = ['hook' => $hook];
        if ($args !== null) {
            $data['args'] = $args;
        }
        $response = $this->request('POST', '/cron-run', $data);
        $response->throw();

        return $response->json();
    }

    public function disableCron(string $hook, ?array $args = null): array
    {
        $data = ['hook' => $hook];
        if ($args !== null) {
            $data['args'] = $args;
        }
        $response = $this->request('POST', '/cron-disable', $data);
        $response->throw();

        return $response->json();
    }

    public function enableCron(string $hook, string $schedule, ?array $args = null): array
    {
        $data = ['hook' => $hook, 'schedule' => $schedule];
        if ($args !== null) {
            $data['args'] = $args;
        }
        $response = $this->request('POST', '/cron-enable', $data);
        $response->throw();

        return $response->json();
    }
}
