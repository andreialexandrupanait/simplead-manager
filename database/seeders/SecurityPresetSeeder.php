<?php

namespace Database\Seeders;

use App\Models\SecurityPreset;
use Illuminate\Database\Seeder;

class SecurityPresetSeeder extends Seeder
{
    public function run(): void
    {
        SecurityPreset::updateOrCreate(
            ['name' => 'Basic Protection'],
            [
                'description' => 'Recommended baseline security: common hardening toggles, brute force protection, and activity logging for failed logins.',
                'is_default' => true,
                'settings' => [
                    'hardening' => [
                        'disable_theme_editor' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'disable_user_enumeration' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'hide_wp_version' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'restrict_xmlrpc' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'security_headers' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'block_application_passwords' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'restrict_rest_api' => ['enabled' => false, 'value' => ['enabled' => false]],
                    ],
                    'htaccess' => [
                        'block_default_files' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'block_readme_access' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'block_debug_log' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'disable_directory_listing' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'firewall_enabled' => ['enabled' => true, 'value' => ['enabled' => true]],
                    ],
                    'login' => [
                        'brute_force_protection' => [
                            'enabled' => true,
                            'value' => ['max_attempts' => 5, 'window_minutes' => 10, 'block_duration_minutes' => 60],
                        ],
                    ],
                    'activity_log' => [
                        'activity_log_config' => [
                            'enabled' => true,
                            'value' => ['events' => ['failed_login']],
                        ],
                    ],
                ],
            ],
        );

        SecurityPreset::updateOrCreate(
            ['name' => 'Maximum Security'],
            [
                'description' => 'All hardening features enabled with strict brute force settings, full activity logging, and REST API restrictions.',
                'is_default' => false,
                'settings' => [
                    'hardening' => [
                        'disable_theme_editor' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'disable_user_enumeration' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'hide_wp_version' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'restrict_xmlrpc' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'security_headers' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'block_application_passwords' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'restrict_rest_api' => ['enabled' => true, 'value' => ['enabled' => true]],
                    ],
                    'htaccess' => [
                        'block_default_files' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'block_readme_access' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'block_debug_log' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'disable_directory_listing' => ['enabled' => true, 'value' => ['enabled' => true]],
                        'firewall_enabled' => ['enabled' => true, 'value' => ['enabled' => true]],
                    ],
                    'login' => [
                        'brute_force_protection' => [
                            'enabled' => true,
                            'value' => ['max_attempts' => 3, 'window_minutes' => 5, 'block_duration_minutes' => 120],
                        ],
                        'two_factor_auth' => ['enabled' => true, 'value' => ['enabled' => true]],
                    ],
                    'activity_log' => [
                        'activity_log_config' => [
                            'enabled' => true,
                            'value' => ['events' => ['failed_login', 'login', 'plugin_change', 'user_change', 'settings_change', 'post_change']],
                        ],
                    ],
                    'ip_management' => [
                        'firewall_config' => [
                            'enabled' => true,
                            'value' => ['enabled' => true, 'ip_header_override' => '', 'role_whitelist' => true],
                        ],
                    ],
                ],
            ],
        );

        SecurityPreset::updateOrCreate(
            ['name' => 'Minimal / Monitoring Only'],
            [
                'description' => 'All hardening features disabled. Only activity logging for failed logins is enabled for monitoring purposes.',
                'is_default' => false,
                'settings' => [
                    'hardening' => [
                        'disable_theme_editor' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'disable_user_enumeration' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'hide_wp_version' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'restrict_xmlrpc' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'security_headers' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'block_application_passwords' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'restrict_rest_api' => ['enabled' => false, 'value' => ['enabled' => false]],
                    ],
                    'htaccess' => [
                        'block_default_files' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'block_readme_access' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'block_debug_log' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'disable_directory_listing' => ['enabled' => false, 'value' => ['enabled' => false]],
                        'firewall_enabled' => ['enabled' => false, 'value' => ['enabled' => false]],
                    ],
                    'activity_log' => [
                        'activity_log_config' => [
                            'enabled' => true,
                            'value' => ['events' => ['failed_login']],
                        ],
                    ],
                ],
            ],
        );
    }
}
