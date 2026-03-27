<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $status_page_incident_id
 * @property string $status
 * @property string $message
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\StatusPageIncident|null $incident
 */
class StatusPageIncidentUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'status_page_incident_id',
        'status',
        'message',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(StatusPageIncident::class, 'status_page_incident_id');
    }
}
