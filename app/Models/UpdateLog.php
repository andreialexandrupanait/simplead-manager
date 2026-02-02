<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpdateLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'user_id',
        'type',
        'name',
        'slug',
        'from_version',
        'to_version',
        'success',
        'error_message',
        'performed_at',
    ];

    protected $casts = [
        'success' => 'boolean',
        'performed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
