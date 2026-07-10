<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $color
 */
class Tag extends Model
{
    use HasFactory;

    /** Tailwind-friendly accent names the UI maps to classes. */
    public const COLORS = ['gray', 'red', 'orange', 'amber', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink'];

    protected $fillable = ['name', 'color'];

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_tag');
    }
}
