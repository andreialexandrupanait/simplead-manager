<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property IncidentTriggerType $trigger_type
 * @property string $trigger_source
 * @property int|null $trigger_source_id
 * @property IncidentResponseStatus $status
 * @property string|null $resolution_method
 * @property string|null $playbook_name
 * @property array|null $diagnosis
 * @property array|null $actions_taken
 * @property array|null $ai_context
 * @property string|null $summary
 * @property int $actions_count
 * @property int $ai_calls_count
 * @property int $total_tokens_used
 * @property bool $backup_created
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $escalated_at
 * @property-read Site|null $site
 * @property-read \Illuminate\Database\Eloquent\Collection<IncidentResponseAction> $actions
 */
class IncidentResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'trigger_type',
        'trigger_source',
        'trigger_source_id',
        'status',
        'resolution_method',
        'playbook_name',
        'diagnosis',
        'actions_taken',
        'ai_context',
        'summary',
        'actions_count',
        'ai_calls_count',
        'total_tokens_used',
        'backup_created',
        'resolved_at',
        'escalated_at',
    ];

    protected $casts = [
        'trigger_type' => IncidentTriggerType::class,
        'status' => IncidentResponseStatus::class,
        'diagnosis' => 'array',
        'actions_taken' => 'array',
        'ai_context' => 'array',
        'backup_created' => 'boolean',
        'resolved_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentResponseAction::class)->orderBy('sequence');
    }

    public function markDiagnosing(): void
    {
        $this->update(['status' => IncidentResponseStatus::Diagnosing]);
    }

    public function markExecuting(): void
    {
        $this->update(['status' => IncidentResponseStatus::Executing]);
    }

    public function markResolved(string $summary, string $method): void
    {
        $this->update([
            'status' => IncidentResponseStatus::Resolved,
            'resolution_method' => $method,
            'summary' => $summary,
            'resolved_at' => now(),
        ]);
    }

    public function markFailed(string $summary): void
    {
        $this->update([
            'status' => IncidentResponseStatus::Failed,
            'summary' => $summary,
        ]);
    }

    public function markEscalated(string $summary): void
    {
        $this->update([
            'status' => IncidentResponseStatus::Escalated,
            'summary' => $summary,
            'escalated_at' => now(),
        ]);
    }

    public function hasReachedActionLimit(): bool
    {
        return $this->actions_count >= config('incident-response.safety.max_actions_per_incident', 10);
    }

    public function hasReachedAiCallLimit(): bool
    {
        return $this->ai_calls_count >= config('incident-response.safety.max_ai_calls_per_incident', 5);
    }

    public function incrementActionsCount(): void
    {
        $this->increment('actions_count');
    }

    public function incrementAiCallsCount(int $tokensUsed = 0): void
    {
        $this->increment('ai_calls_count');
        if ($tokensUsed > 0) {
            $this->increment('total_tokens_used', $tokensUsed);
        }
    }
}
