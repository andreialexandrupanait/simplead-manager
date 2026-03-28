<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorState;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $status_page_id
 * @property int $site_id
 * @property string|null $display_name
 * @property int $sort_order
 * @property bool $is_visible
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\StatusPage|null $statusPage
 * @property-read \App\Models\Site|null $site
 * @property-read string|null $name
 * @property-read string $current_status
 */
class StatusPageSite extends Model
{
    use HasFactory;

    protected $fillable = [
        'status_page_id',
        'site_id',
        'display_name',
        'sort_order',
        'is_visible',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
    ];

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => $this->display_name ?? $this->site?->name);
    }

    protected function currentStatus(): Attribute
    {
        return Attribute::get(function () {
            $site = $this->site;
            if (! $site) {
                return 'unknown';
            }

            if (! $site->is_up) {
                return 'down';
            }

            $monitor = $site->uptimeMonitor;
            if ($monitor && $monitor->current_state === MonitorState::Degraded) {
                return 'degraded';
            }

            return 'operational';
        });
    }
}
