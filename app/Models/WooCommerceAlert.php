<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooCommerceAlert extends Model
{
    protected $fillable = [
        'site_id',
        'type',
        'product_id',
        'product_name',
        'message',
        'is_acknowledged',
    ];

    protected $casts = [
        'is_acknowledged' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeUnacknowledged($query)
    {
        return $query->where('is_acknowledged', false);
    }
}
