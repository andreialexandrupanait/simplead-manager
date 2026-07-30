<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faza 5.2 — latest WooCommerce store-health snapshot for a site (one row per site).
 *
 * @property int $id
 * @property int $site_id
 * @property bool $applicable
 * @property int|null $checkout_status
 * @property bool $gateways_ok
 * @property int $pending_orders_count
 * @property bool $pending_over_threshold
 * @property bool $discount_cron_scheduled
 * @property array|null $gateways
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class WooCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'applicable',
        'checkout_status',
        'gateways_ok',
        'pending_orders_count',
        'pending_over_threshold',
        'discount_cron_scheduled',
        'gateways',
        'last_error',
        'checked_at',
    ];

    protected $casts = [
        'applicable' => 'boolean',
        'checkout_status' => 'integer',
        'gateways_ok' => 'boolean',
        'pending_orders_count' => 'integer',
        'pending_over_threshold' => 'boolean',
        'discount_cron_scheduled' => 'boolean',
        'gateways' => 'array',
        'checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Traffic-light for the store's checkout readiness. Kept in sync with the
     * severity mapping in WooHealthService::evaluate().
     */
    public function getStatusColorAttribute(): string
    {
        if (! $this->applicable) {
            return 'gray';
        }

        if (! $this->gateways_ok || ($this->checkout_status !== null && $this->checkout_status !== 200)) {
            return 'red';
        }

        if ($this->pending_over_threshold || ! $this->discount_cron_scheduled) {
            return 'yellow';
        }

        return 'green';
    }
}
