<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $incident_response_id
 * @property string $action_type
 * @property string $tier
 * @property array|null $parameters
 * @property array|null $result
 * @property string $status
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property int $sequence
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read IncidentResponse|null $incidentResponse
 */
class IncidentResponseAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_response_id',
        'action_type',
        'tier',
        'parameters',
        'result',
        'status',
        'error_message',
        'duration_ms',
        'sequence',
    ];

    protected $casts = [
        'parameters' => 'array',
        'result' => 'array',
    ];

    public function incidentResponse(): BelongsTo
    {
        return $this->belongsTo(IncidentResponse::class);
    }
}
