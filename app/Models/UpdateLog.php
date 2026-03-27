<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int|null $user_id
 * @property string $type
 * @property string $name
 * @property string|null $slug
 * @property string|null $from_version
 * @property string|null $to_version
 * @property bool $success
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $performed_at
 * @property-read Site|null $site
 * @property-read User|null $user
 */
class UpdateLog extends Model
{
    use HasFactory;

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
