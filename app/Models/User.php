<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property bool $is_admin
 * @property \App\Enums\UserRole $role
 * @property string $timezone
 * @property string $date_format
 * @property string $language
 * @property bool $two_factor_enabled
 * @property string|null $two_factor_secret
 * @property array|null $two_factor_recovery_codes
 * @property string|null $avatar_path
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $initials
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Site> $sites
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DashboardWidget> $dashboardWidgets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ActivityLog> $activityLogs
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'role',
        'timezone',
        'date_format',
        'language',
        'theme',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'avatar_path',
        'google_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'is_admin' => 'boolean',
            'role' => UserRole::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function dashboardWidgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function personalAccessTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Client, $this> */
    public function assignedClients(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Client::class);
    }

    /**
     * Check if user can access a site — either owns it directly or is assigned to its client.
     */
    public function canAccessSite(Site $site): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        // Direct ownership
        if ($site->user_id === $this->id) {
            return true;
        }

        // Client assignment
        if ($site->client_id && $this->assignedClients()->where('clients.id', $site->client_id)->exists()) {
            return true;
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    public function isViewer(): bool
    {
        return $this->role === UserRole::Viewer;
    }

    public function canManageSites(): bool
    {
        return $this->role->canManageSites();
    }

    public function canDeleteResources(): bool
    {
        return $this->role->canDeleteResources();
    }

    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->name);

        return strtoupper(
            collect($words)->take(2)->map(fn ($w) => $w[0])->implode('')
        );
    }
}
