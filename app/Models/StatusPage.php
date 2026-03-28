<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorState;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $client_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property string|null $logo_url
 * @property string $primary_color
 * @property string|null $custom_domain
 * @property bool $is_public
 * @property bool $show_uptime_percentage
 * @property bool $show_response_time
 * @property bool $show_incident_history
 * @property int $incident_history_days
 * @property bool $auto_incidents
 * @property string|null $password_hash
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Client|null $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StatusPageSite> $statusPageSites
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StatusPageIncident> $incidents
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\StatusPageIncident> $activeIncidents
 * @property-read string|null $overall_status
 */
class StatusPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'slug',
        'title',
        'description',
        'logo_url',
        'primary_color',
        'custom_domain',
        'is_public',
        'show_uptime_percentage',
        'show_response_time',
        'show_incident_history',
        'incident_history_days',
        'auto_incidents',
        'password_hash',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'show_uptime_percentage' => 'boolean',
        'show_response_time' => 'boolean',
        'show_incident_history' => 'boolean',
        'incident_history_days' => 'integer',
        'auto_incidents' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function statusPageSites(): HasMany
    {
        return $this->hasMany(StatusPageSite::class)->orderBy('sort_order');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(StatusPageIncident::class);
    }

    public function activeIncidents(): HasMany
    {
        return $this->hasMany(StatusPageIncident::class)->where('status', '!=', 'resolved');
    }

    protected function overallStatus(): Attribute
    {
        return Attribute::get(function () {
            $sites = $this->statusPageSites()->with('site.uptimeMonitor')->get();

            if ($sites->isEmpty()) {
                return 'operational';
            }

            $hasOutage = $sites->contains(fn (StatusPageSite $sps) => $sps->site && ! $sps->site->is_up);
            if ($hasOutage) {
                return 'outage';
            }

            $hasDegraded = $sites->contains(fn (StatusPageSite $sps) => $sps->site?->uptimeMonitor?->current_state === MonitorState::Degraded);
            if ($hasDegraded) {
                return 'degraded';
            }

            return 'operational';
        });
    }

    protected function publicUrl(): Attribute
    {
        return Attribute::get(fn () => url("/status/{$this->slug}"));
    }
}
