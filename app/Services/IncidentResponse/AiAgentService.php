<?php

declare(strict_types=1);

namespace App\Services\IncidentResponse;

use App\Models\IncidentResponse;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAgentService
{
    public function diagnoseAndFix(
        IncidentResponse $response,
        Site $site,
        IncidentActionExecutor $executor,
        array $context,
    ): bool {
        $apiKey = config('incident-response.ai.api_key');
        if (! $apiKey) {
            Log::warning('AI Agent: No API key configured');

            return false;
        }

        $systemPrompt = $this->buildSystemPrompt($context);
        $tools = $this->getToolDefinitions();

        $messages = [
            [
                'role' => 'user',
                'content' => $this->buildInitialMessage($response, $context),
            ],
        ];

        $aiContextLog = [];

        // Tool-use loop
        while (! $response->hasReachedAiCallLimit()) {
            $aiResponse = $this->callClaude($systemPrompt, $tools, $messages);

            if (! $aiResponse) {
                Log::warning("AI Agent: Claude API call failed for incident {$response->id}");

                break;
            }

            $tokensUsed = ($aiResponse['usage']['input_tokens'] ?? 0) + ($aiResponse['usage']['output_tokens'] ?? 0);
            $response->incrementAiCallsCount($tokensUsed);

            $aiContextLog[] = ['role' => 'assistant', 'content' => $aiResponse['content'] ?? []];

            $stopReason = $aiResponse['stop_reason'] ?? 'end_turn';

            if ($stopReason === 'end_turn') {
                // AI is done — extract any final text message
                $this->saveAiContext($response, $aiContextLog);

                break;
            }

            if ($stopReason !== 'tool_use') {
                break;
            }

            // Process tool calls
            $toolResults = $this->processToolCalls(
                $response, $site, $executor, $aiResponse['content'] ?? [],
            );

            if ($toolResults === null) {
                // resolve_incident or escalate_to_human was called
                $this->saveAiContext($response, $aiContextLog);

                return true;
            }

            // Append assistant message and tool results to conversation
            $messages[] = ['role' => 'assistant', 'content' => $aiResponse['content']];
            $messages[] = ['role' => 'user', 'content' => $toolResults];
            $aiContextLog[] = ['role' => 'tool_results', 'content' => $toolResults];
        }

        $this->saveAiContext($response, $aiContextLog);

        return $response->fresh()->status->isTerminal();
    }

    private function callClaude(string $systemPrompt, array $tools, array $messages): ?array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => config('incident-response.ai.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('incident-response.ai.model', 'claude-sonnet-4-20250514'),
                'max_tokens' => config('incident-response.ai.max_tokens', 4096),
                'temperature' => config('incident-response.ai.temperature', 0.1),
                'system' => $systemPrompt,
                'tools' => $tools,
                'messages' => $messages,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('AI Agent: Claude API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error("AI Agent: Claude API exception: {$e->getMessage()}");

            return null;
        }
    }

    private function processToolCalls(
        IncidentResponse $response,
        Site $site,
        IncidentActionExecutor $executor,
        array $contentBlocks,
    ): ?array {
        $toolResults = [];

        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            $toolName = $block['name'];
            $toolInput = $block['input'] ?? [];
            $toolUseId = $block['id'];

            // Handle terminal tools
            if ($toolName === 'resolve_incident') {
                $response->markResolved($toolInput['summary'] ?? 'Resolved by AI agent', 'ai_agent');

                return null;
            }

            if ($toolName === 'escalate_to_human') {
                $summary = sprintf(
                    "AI escalation — Reason: %s\nDiagnosis: %s\nRecommended: %s",
                    $toolInput['reason'] ?? 'Unknown',
                    $toolInput['diagnosis'] ?? 'Unknown',
                    $toolInput['recommended_actions'] ?? 'Unknown',
                );
                $response->markEscalated($summary);

                return null;
            }

            // Execute action tool
            $result = $executor->execute($response, $site, $toolName, 'ai_agent', $toolInput);

            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolUseId,
                'content' => json_encode($result),
            ];
        }

        return $toolResults;
    }

    private function saveAiContext(IncidentResponse $response, array $contextLog): void
    {
        // Truncate large context to avoid bloating the DB
        $json = json_encode($contextLog);
        if (strlen($json) > 50000) {
            $contextLog = array_slice($contextLog, -4);
        }

        $response->update(['ai_context' => $contextLog]);
    }

    private function buildSystemPrompt(array $context): string
    {
        return <<<'PROMPT'
You are an autonomous WordPress site incident responder. You manage WordPress sites and must diagnose and fix problems without human intervention.

## Rules
1. ALWAYS run diagnostics first before taking action. Understand the problem before fixing it.
2. Before ANY destructive action (deactivating plugins, updating, rolling back), ensure a backup exists. Use create_backup if needed.
3. After each fix attempt, verify the fix worked (use check_site_up for downtime, health_check for general health).
4. If you cannot determine the cause or your fix attempts are not working, escalate to a human. Do not keep trying blindly.
5. Be conservative: prefer less-destructive actions first (flush cache → deactivate single plugin → rollback → escalate).
6. When deactivating plugins to diagnose, try the most recently updated plugin first, then plugins that appear in error logs.
7. Never delete plugins or themes — only deactivate. Deletion is too destructive for automated response.
8. After successfully resolving an issue, call resolve_incident with a clear summary.
9. You have a limited number of actions. Be efficient and targeted.

## Diagnostic Strategy for Site Down
1. run_diagnostic to get error logs and loopback test
2. If fatal error in a plugin → deactivate that plugin → check_site_up
3. If no clear plugin culprit → flush_cache → check_site_up
4. If Elementor-related errors → fix_elementor → check_site_up
5. If still down → escalate_to_human

## For Security Issues
1. Identify the specific issue from context
2. Apply the appropriate security fix
3. Verify with health_check

## For Performance Issues
1. flush_cache first
2. db_cleanup if database-related
3. Check server resources if needed
4. Verify improvement with health_check
PROMPT;
    }

    private function buildInitialMessage(IncidentResponse $response, array $context): string
    {
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $triggerLabel = $response->trigger_type->label();
        $siteName = $context['site']['name'] ?? 'Unknown';
        $siteUrl = $context['site']['url'] ?? 'Unknown';

        return <<<MSG
An incident has been detected that the playbook could not resolve. Please diagnose and fix the issue.

**Trigger:** {$triggerLabel}
**Site:** {$siteName} ({$siteUrl})

## Full Site Context
```json
{$contextJson}
```

Previous playbook actions have already been attempted and failed. Analyze the context above and take appropriate action using the available tools.
MSG;
    }

    private function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'run_diagnostic',
                'description' => 'Run a comprehensive diagnostic on the WordPress site. Returns debug.log tail, PHP fatal errors, active plugins, theme info, loopback test results, and paused extensions.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'health_check',
                'description' => 'Run a health check on the WordPress site. Returns database connectivity, filesystem writability, PHP/WP version status, SSL, cron status, pending updates.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'check_site_up',
                'description' => 'Make an HTTP GET request to the site URL to check if it responds. Returns status code and response time.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'flush_cache',
                'description' => 'Flush the WordPress object cache and OPcache.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'deactivate_plugin',
                'description' => 'Deactivate a specific plugin. Use when you suspect a plugin is causing issues.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'plugin_id' => ['type' => 'integer', 'description' => 'The SitePlugin model ID from the context'],
                        'reason' => ['type' => 'string', 'description' => 'Why this plugin is being deactivated'],
                    ],
                    'required' => ['plugin_id', 'reason'],
                ],
            ],
            [
                'name' => 'activate_plugin',
                'description' => 'Activate a previously deactivated plugin.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'plugin_id' => ['type' => 'integer', 'description' => 'The SitePlugin model ID'],
                    ],
                    'required' => ['plugin_id'],
                ],
            ],
            [
                'name' => 'update_plugin',
                'description' => 'Update a plugin to its available version. A backup is created automatically before updating.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'plugin_id' => ['type' => 'integer', 'description' => 'The SitePlugin model ID'],
                    ],
                    'required' => ['plugin_id'],
                ],
            ],
            [
                'name' => 'rollback_plugin',
                'description' => 'Roll back a plugin to a previous version using a rollback point.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'rollback_point_id' => ['type' => 'integer', 'description' => 'The RollbackPoint model ID'],
                    ],
                    'required' => ['rollback_point_id'],
                ],
            ],
            [
                'name' => 'create_backup',
                'description' => 'Create a database backup before performing destructive actions.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'db_cleanup',
                'description' => 'Run database cleanup: removes revisions, spam comments, trash, transients, and orphaned metadata.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'apply_security_fix',
                'description' => 'Apply a specific security hardening fix.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string', 'description' => 'The security fix key to apply'],
                    ],
                    'required' => ['key'],
                ],
            ],
            [
                'name' => 'fix_elementor',
                'description' => 'Run Elementor fix routine: sync versions, fix dynamic tag encoding, clear CSS caches.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'get_server_resources',
                'description' => 'Get server resource usage (disk, memory, CPU info) from the WordPress site.',
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
            ],
            [
                'name' => 'resolve_incident',
                'description' => 'Mark the incident as resolved. Call this when you have verified the problem is fixed.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string', 'description' => 'Human-readable summary of what was wrong and what was done to fix it'],
                    ],
                    'required' => ['summary'],
                ],
            ],
            [
                'name' => 'escalate_to_human',
                'description' => 'Escalate the incident to a human administrator. Use when you cannot resolve the issue or the action is too risky.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => ['type' => 'string', 'description' => 'Why this needs human intervention'],
                        'diagnosis' => ['type' => 'string', 'description' => 'Your complete diagnosis of the problem'],
                        'recommended_actions' => ['type' => 'string', 'description' => 'What you recommend the human do'],
                    ],
                    'required' => ['reason', 'diagnosis', 'recommended_actions'],
                ],
            ],
        ];
    }
}
