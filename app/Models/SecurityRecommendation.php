<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'key',
        'category',
        'title',
        'status',
        'can_auto_fix',
        'last_checked_at',
    ];

    protected $casts = [
        'can_auto_fix' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    const DEFINITIONS = [
        'disable_file_editing' => [
            'category' => 'file_security',
            'title' => 'Disable file editor in wp-admin',
            'description' => 'The built-in file editor allows anyone with admin access to modify plugin and theme files directly. Disabling it prevents accidental or malicious code changes via the dashboard.',
            'can_auto_fix' => true,
        ],
        'prevent_php_uploads' => [
            'category' => 'file_security',
            'title' => 'Prevent PHP execution in uploads',
            'description' => 'The uploads directory should not execute PHP files. Adding rules to prevent PHP execution blocks attackers from running malicious scripts uploaded through vulnerabilities.',
            'can_auto_fix' => true,
        ],
        'protect_wp_config' => [
            'category' => 'file_security',
            'title' => 'Protect wp-config.php',
            'description' => 'wp-config.php contains database credentials and security keys. Restricting access to this file prevents unauthorized users from reading sensitive configuration data.',
            'can_auto_fix' => true,
        ],
        'protect_htaccess' => [
            'category' => 'file_security',
            'title' => 'Protect .htaccess',
            'description' => 'The .htaccess file controls server behavior and URL routing. Protecting it prevents attackers from modifying server configuration through web requests.',
            'can_auto_fix' => true,
        ],
        'hide_wp_version' => [
            'category' => 'file_security',
            'title' => 'Hide WordPress version',
            'description' => 'Exposing the WordPress version helps attackers target known vulnerabilities for that specific version. Hiding it adds an extra layer of obscurity.',
            'can_auto_fix' => true,
        ],
        'disable_directory_listing' => [
            'category' => 'file_security',
            'title' => 'Disable directory listing',
            'description' => 'Directory listing exposes the file structure of your site when no index file exists. Disabling it prevents attackers from browsing your server directories.',
            'can_auto_fix' => true,
        ],
        'change_admin_username' => [
            'category' => 'login_security',
            'title' => 'Change default admin username',
            'description' => 'Using "admin" as a username makes brute-force attacks easier since attackers only need to guess the password. Use a unique, non-predictable username instead.',
            'can_auto_fix' => false,
        ],
        'strong_passwords' => [
            'category' => 'login_security',
            'title' => 'Strong password policy',
            'description' => 'Weak passwords are the most common attack vector. Enforce strong passwords with minimum length, mixed characters, and regular rotation for all user accounts.',
            'can_auto_fix' => false,
        ],
        'limit_login_attempts' => [
            'category' => 'login_security',
            'title' => 'Limit login attempts',
            'description' => 'Without login attempt limits, attackers can try unlimited password combinations. Rate limiting locks out IPs after repeated failed attempts.',
            'can_auto_fix' => false,
        ],
        'disable_xmlrpc' => [
            'category' => 'login_security',
            'title' => 'Disable XML-RPC',
            'description' => 'XML-RPC is an older API that can be exploited for brute-force attacks and DDoS amplification. If not needed by plugins or mobile apps, it should be disabled.',
            'can_auto_fix' => true,
        ],
        'disable_trackbacks' => [
            'category' => 'login_security',
            'title' => 'Disable trackbacks/pingbacks',
            'description' => 'Trackbacks and pingbacks can be abused for DDoS attacks and spam. Disabling them removes this attack surface without affecting normal site functionality.',
            'can_auto_fix' => true,
        ],
        'change_table_prefix' => [
            'category' => 'database_security',
            'title' => 'Change default table prefix',
            'description' => 'The default "wp_" table prefix makes SQL injection attacks easier since attackers know the table names. Using a custom prefix adds a layer of protection.',
            'can_auto_fix' => false,
        ],
        'remove_unused_tables' => [
            'category' => 'database_security',
            'title' => 'Remove unused database tables',
            'description' => 'Leftover tables from deleted plugins can contain sensitive data and increase the attack surface. Regularly clean up unused database tables.',
            'can_auto_fix' => false,
        ],
        'header_x_frame' => [
            'category' => 'http_headers',
            'title' => 'X-Frame-Options header',
            'description' => 'This header prevents your site from being embedded in iframes on other domains, protecting against clickjacking attacks where users are tricked into clicking hidden elements.',
            'can_auto_fix' => true,
        ],
        'header_x_content_type' => [
            'category' => 'http_headers',
            'title' => 'X-Content-Type-Options header',
            'description' => 'This header prevents browsers from MIME-sniffing content types, which can be exploited to execute malicious content disguised as harmless file types.',
            'can_auto_fix' => true,
        ],
        'header_x_xss' => [
            'category' => 'http_headers',
            'title' => 'X-XSS-Protection header',
            'description' => 'This header enables the browser\'s built-in XSS filter, providing an additional layer of protection against cross-site scripting attacks.',
            'can_auto_fix' => true,
        ],
        'header_referrer_policy' => [
            'category' => 'http_headers',
            'title' => 'Referrer-Policy header',
            'description' => 'Controls how much referrer information is sent with requests. A strict policy prevents leaking sensitive URL parameters to third-party sites.',
            'can_auto_fix' => true,
        ],
        'header_permissions_policy' => [
            'category' => 'http_headers',
            'title' => 'Permissions-Policy header',
            'description' => 'This header controls which browser features and APIs can be used on your site, limiting the potential impact of XSS attacks.',
            'can_auto_fix' => true,
        ],
        'header_csp' => [
            'category' => 'http_headers',
            'title' => 'Content-Security-Policy header',
            'description' => 'CSP defines which content sources are allowed on your pages. It is the most effective defense against XSS attacks but requires careful configuration to avoid breaking functionality.',
            'can_auto_fix' => false,
        ],
        'force_https' => [
            'category' => 'ssl_https',
            'title' => 'Force HTTPS',
            'description' => 'All traffic should be redirected from HTTP to HTTPS to ensure data is encrypted in transit. Mixed content or HTTP access exposes users to man-in-the-middle attacks.',
            'can_auto_fix' => true,
        ],
        'hsts_header' => [
            'category' => 'ssl_https',
            'title' => 'HSTS header',
            'description' => 'HTTP Strict Transport Security tells browsers to always use HTTPS, preventing protocol downgrade attacks and cookie hijacking.',
            'can_auto_fix' => true,
        ],
        'secure_cookies' => [
            'category' => 'ssl_https',
            'title' => 'Secure cookies',
            'description' => 'Cookies should be marked as Secure (HTTPS only) and HttpOnly (no JavaScript access) to prevent session hijacking and cross-site scripting attacks.',
            'can_auto_fix' => true,
        ],
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopePassed(Builder $query): Builder
    {
        return $query->where('status', 'passed');
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'passed' => 'green',
            'failed' => 'red',
            default => 'gray',
        };
    }

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'passed' => 'check-circle',
            'failed' => 'x-circle',
            default => 'clock',
        };
    }

    public function getDescriptionAttribute(): string
    {
        return self::DEFINITIONS[$this->key]['description'] ?? '';
    }
}
