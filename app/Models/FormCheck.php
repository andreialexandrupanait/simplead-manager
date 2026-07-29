<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Result of a contact-form deliverability self-test (Faza 5.1).
 *
 * @property int $id
 * @property int $site_id
 * @property string|null $form_plugin
 * @property bool $supported
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property bool $delivery_verified
 * @property bool $suppression_confirmed
 * @property string|null $status
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class FormCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'form_plugin',
        'supported',
        'submitted_at',
        'delivery_verified',
        'suppression_confirmed',
        'status',
        'checked_at',
    ];

    protected $casts = [
        'supported' => 'boolean',
        'delivery_verified' => 'boolean',
        'suppression_confirmed' => 'boolean',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
