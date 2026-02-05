<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WpAuditLog extends Model
{
    use HasFactory;

    protected $table = 'wp_audit_logs';

    protected $fillable = [
        'site_id',
        'wp_user_id',
        'wp_username',
        'user_role',
        'action_type',
        'object_type',
        'object_id',
        'object_title',
        'old_value',
        'new_value',
        'ip_address',
        'user_agent',
        'action_at',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'action_at' => 'datetime',
        'wp_user_id' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeAction(Builder $query, string $type): Builder
    {
        return $query->where('action_type', $type);
    }

    public function scopeUser(Builder $query, string $username): Builder
    {
        return $query->where('wp_username', $username);
    }

    public function getActionLabelAttribute(): string
    {
        return match ($this->action_type) {
            'login' => 'Login',
            'logout' => 'Logout',
            'failed_login' => 'Failed Login',
            'post_created' => 'Post Created',
            'post_updated' => 'Post Updated',
            'post_trashed' => 'Post Trashed',
            'post_deleted' => 'Post Deleted',
            'post_published' => 'Post Published',
            'plugin_activated' => 'Plugin Activated',
            'plugin_deactivated' => 'Plugin Deactivated',
            'plugin_updated' => 'Plugin Updated',
            'plugin_installed' => 'Plugin Installed',
            'plugin_deleted' => 'Plugin Deleted',
            'theme_switched' => 'Theme Switched',
            'theme_updated' => 'Theme Updated',
            'theme_installed' => 'Theme Installed',
            'theme_deleted' => 'Theme Deleted',
            'option_changed' => 'Option Changed',
            'media_uploaded' => 'Media Uploaded',
            'media_deleted' => 'Media Deleted',
            'user_created' => 'User Created',
            'user_updated' => 'User Updated',
            'user_deleted' => 'User Deleted',
            'core_updated' => 'Core Updated',
            default => ucfirst(str_replace('_', ' ', $this->action_type)),
        };
    }

    public function getActionColorAttribute(): string
    {
        return match ($this->action_type) {
            'post_deleted', 'plugin_deleted', 'theme_deleted', 'media_deleted', 'user_deleted', 'post_trashed', 'failed_login' => 'red',
            'post_updated', 'plugin_updated', 'theme_updated', 'user_updated', 'option_changed', 'plugin_deactivated', 'core_updated', 'theme_switched' => 'yellow',
            'post_created', 'post_published', 'plugin_installed', 'theme_installed', 'media_uploaded', 'user_created', 'plugin_activated' => 'green',
            'login', 'logout' => 'blue',
            default => 'gray',
        };
    }

    public function getActionIconAttribute(): string
    {
        return match ($this->object_type) {
            'post', 'page' => 'file-text',
            'plugin' => 'puzzle',
            'theme' => 'layers',
            'user' => 'user',
            'option' => 'settings',
            'media' => 'image',
            default => 'activity',
        };
    }
}
