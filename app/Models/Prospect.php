<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProspectProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Faza D: a sales prospect — a site not (yet) managed, audited as a sales tool.
 */
class Prospect extends Model
{
    /** @use HasFactory<\Database\Factories\ProspectFactory> */
    use HasFactory;

    protected $fillable = ['name', 'url', 'profile', 'contact_name', 'contact_email', 'notes'];

    protected $casts = ['profile' => ProspectProfile::class];

    /** @return HasMany<Audit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class);
    }
}
