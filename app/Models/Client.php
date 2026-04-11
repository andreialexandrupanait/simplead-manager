<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $company
 * @property string|null $website
 * @property string|null $address
 * @property string|null $city
 * @property string|null $country
 * @property string|null $vat_number
 * @property string|null $registration_number
 * @property bool $vat_payer
 * @property string|null $company_status
 * @property string|null $county
 * @property string|null $postal_code
 * @property string|null $logo
 * @property string|null $notes
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $portal_token
 * @property bool $portal_enabled
 * @property-read string|null $logo_path
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Site> $sites
 */
class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            if (! $client->portal_token) {
                $client->portal_token = Str::random(64);
            }
        });
    }

    protected $fillable = [
        'name',
        'email',
        'phone',
        'company',
        'website',
        'address',
        'city',
        'country',
        'vat_number',
        'registration_number',
        'vat_payer',
        'company_status',
        'county',
        'postal_code',
        'logo',
        'notes',
        'status',
        'portal_token',
        'portal_enabled',
    ];

    protected $attributes = [
        'portal_enabled' => true,
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
        'portal_enabled' => 'boolean',
        'vat_payer' => 'boolean',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(ClientCost::class);
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(ClientRevenue::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<User, $this> */
    public function assignedUsers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->orWhere('company', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%");
        });
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->company ?: $this->name;
    }

    public function getLogoPathAttribute(): ?string
    {
        return $this->logo;
    }

    public function getInitialsAttribute(): string
    {
        $name = $this->company ?: $this->name;
        $words = explode(' ', $name);

        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1).substr($words[1], 0, 1));
        }

        return strtoupper(substr($name, 0, 2));
    }
}
