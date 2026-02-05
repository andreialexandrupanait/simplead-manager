<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteStatus extends Model
{
    protected $fillable = [
        'name',
        'color',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
