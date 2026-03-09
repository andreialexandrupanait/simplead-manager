<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
