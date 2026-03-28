<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string|null $description
 * @property string $severity
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class StatusPageIncidentTemplate extends Model
{
    protected $fillable = [
        'name',
        'title',
        'description',
        'severity',
        'sort_order',
    ];
}
