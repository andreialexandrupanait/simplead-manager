<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $domain
 * @property bool $spf_exists
 * @property string|null $spf_record
 * @property string|null $spf_status
 * @property array|null $spf_issues
 * @property bool $dkim_exists
 * @property string|null $dkim_selector
 * @property string|null $dkim_status
 * @property bool $dmarc_exists
 * @property string|null $dmarc_record
 * @property string|null $dmarc_policy
 * @property string|null $dmarc_status
 * @property array|null $blacklists_checked
 * @property int $blacklists_clean
 * @property int $blacklists_listed
 * @property array|null $mx_records
 * @property int $score
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 */
class EmailHealthCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'domain',
        'spf_exists',
        'spf_record',
        'spf_status',
        'spf_issues',
        'dkim_exists',
        'dkim_selector',
        'dkim_status',
        'dmarc_exists',
        'dmarc_record',
        'dmarc_policy',
        'dmarc_status',
        'blacklists_checked',
        'blacklists_clean',
        'blacklists_listed',
        'mx_records',
        'score',
        'status',
        'checked_at',
    ];

    protected $casts = [
        'spf_exists' => 'boolean',
        'dkim_exists' => 'boolean',
        'dmarc_exists' => 'boolean',
        'spf_issues' => 'array',
        'blacklists_checked' => 'array',
        'blacklists_clean' => 'integer',
        'blacklists_listed' => 'integer',
        'mx_records' => 'array',
        'score' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'excellent', 'good' => 'green',
            'warning' => 'yellow',
            'critical' => 'red',
            default => 'gray',
        };
    }

    public function getScoreColorAttribute(): string
    {
        return match (true) {
            $this->score >= 90 => 'green',
            $this->score >= 70 => 'green',
            $this->score >= 50 => 'yellow',
            default => 'red',
        };
    }

    public function getRecommendationsAttribute(): array
    {
        $recommendations = [];

        if (! $this->spf_exists) {
            $recommendations[] = 'Add an SPF record to specify which mail servers are authorized to send email for your domain.';
        } elseif ($this->spf_status === 'invalid') {
            $recommendations[] = 'Your SPF record exists but appears invalid. Review the syntax and ensure it follows the v=spf1 format.';
        }

        if (! $this->dmarc_exists) {
            $recommendations[] = 'Add a DMARC record (_dmarc.yourdomain.com) to define how receivers should handle unauthenticated email.';
        } elseif ($this->dmarc_policy === 'none') {
            $recommendations[] = 'Your DMARC policy is set to "none" (monitoring only). Consider upgrading to "quarantine" or "reject" for better protection.';
        }

        if (! $this->dkim_exists) {
            $recommendations[] = 'Configure DKIM signing for your domain to verify email authenticity and improve deliverability.';
        }

        if ($this->blacklists_listed > 0) {
            $recommendations[] = "Your mail server IP is listed on {$this->blacklists_listed} blacklist(s). Request delisting to improve email deliverability.";
        }

        return $recommendations;
    }
}
