<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §11 — the read-only, one-directional client for app.simplead.ro.
 *
 * "app.simplead.ro | sarcini per site, doar citire | API unidirecțional."
 *
 * The upstream task payload also carries rates, totals and invoice statuses.
 * SPEC §1 puts money, contracts and invoicing in SAD Hub, explicitly outside
 * this application — so {@see self::operationalFields()} drops every financial
 * field on the way in. Manager never stores what it has no business knowing,
 * and a report generated from this data cannot leak a partner rate to a client.
 *
 * Everything here is a GET. There is no method that writes upstream, by design.
 */
class SimpleAdAppClient
{
    public function enabled(): bool
    {
        return $this->baseUrl() !== '' && $this->token() !== '';
    }

    /**
     * Projects (called "lists" upstream), each roughly one client site.
     *
     * @return list<array{id: string, name: string, client: string|null, url: string|null}>
     */
    public function projects(): array
    {
        $body = $this->get('/api/projects');

        if (! is_array($body)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($p): ?array => is_array($p) && ! empty($p['id']) ? [
                'id' => (string) $p['id'],
                'name' => (string) ($p['name'] ?? ''),
                'client' => isset($p['client']) ? (string) $p['client'] : null,
                'url' => isset($p['url']) ? (string) $p['url'] : null,
            ] : null,
            $body,
        )));
    }

    /**
     * Tasks for one project, stripped to the operational fields.
     *
     * @return list<array<string, mixed>>
     */
    public function tasksForProject(string $projectId): array
    {
        $body = $this->get('/api/lists/'.rawurlencode($projectId).'/tasks');
        $tasks = is_array($body) ? ($body['tasks'] ?? []) : [];

        if (! is_array($tasks)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($t) => is_array($t) ? $this->operationalFields($t) : null,
            $tasks,
        )));
    }

    /**
     * The fields Manager is allowed to keep: what the work IS and where it
     * stands. Anything about what it COSTS stays upstream (SPEC §1).
     *
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>|null
     */
    private function operationalFields(array $task): ?array
    {
        if (empty($task['id'])) {
            return null;
        }

        $assignee = is_array($task['assignee'] ?? null) ? $task['assignee'] : [];

        return [
            'external_id' => (string) $task['id'],
            'name' => (string) ($task['name'] ?? ''),
            'status' => (string) ($task['status'] ?? 'unknown'),
            'task_type' => isset($task['taskType']) ? (string) $task['taskType'] : null,
            'assignee_name' => isset($assignee['name']) ? (string) $assignee['name'] : null,
            'due_date' => $this->date($task['dueDate'] ?? null),
            'started_at' => $this->date($task['startDate'] ?? null),
            'status_changed_at' => $this->date($task['statusEnteredAt'] ?? null),
            'external_updated_at' => $this->date($task['updatedAt'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $path): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.simplead_app.timeout', 20))
                ->withToken($this->token())
                ->acceptJson()
                ->get($this->baseUrl().$path);

            if (! $response->successful()) {
                Log::warning("app.simplead.ro returned HTTP {$response->status()} for {$path}");

                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            // A task list that cannot be fetched must never break a sync or a
            // report — it just means this run has no task data.
            Log::warning("app.simplead.ro unreachable for {$path}: {$e->getMessage()}");

            return null;
        }
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('services.simplead_app.base_url', '')), '/');
    }

    private function token(): string
    {
        return trim((string) config('services.simplead_app.token', ''));
    }
}
