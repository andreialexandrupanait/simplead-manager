<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackedKeyword extends Model
{
    protected $fillable = ['site_id', 'keyword'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(KeywordPosition::class);
    }
}
