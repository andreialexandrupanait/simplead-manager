<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooCommerceStat extends Model
{
    protected $fillable = [
        'site_id',
        'date',
        'orders_count',
        'revenue',
        'currency',
        'average_order_value',
        'products_sold_count',
        'refunds_count',
        'refunds_amount',
        'new_customers',
        'returning_customers',
    ];

    protected $casts = [
        'date' => 'date',
        'revenue' => 'decimal:2',
        'average_order_value' => 'decimal:2',
        'refunds_amount' => 'decimal:2',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
