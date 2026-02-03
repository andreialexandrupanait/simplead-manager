# SimpleAd Manager — Feature Spec: WordPress Integration

---

## Overview

This is a two-part feature:

**Part A — WordPress Connector Plugin**: A lightweight PHP plugin installed on each managed WordPress site. It exposes a REST API that SimpleAd Manager uses to fetch data and perform actions.

**Part B — Dashboard Side**: The Laravel backend that communicates with the plugin, syncs data, stores it locally, and displays it in the UI (Plugins & Themes page, Updates page, Site Overview).

---

## PART A: WORDPRESS CONNECTOR PLUGIN

### A.1 Plugin Structure

```
simplead-connector/
├── simplead-connector.php          # Main plugin file (plugin header, bootstrap)
├── includes/
│   ├── class-api.php               # REST API endpoint registration
│   ├── class-auth.php              # HMAC authentication
│   ├── class-site-info.php         # Site info endpoint logic
│   ├── class-plugins.php           # Plugins list + update logic
│   ├── class-themes.php            # Themes list + update logic
│   ├── class-core.php              # WP core update logic
│   ├── class-login.php             # Auto-login URL generation
│   └── class-admin.php             # Admin settings page
├── assets/
│   ├── admin.css                   # Settings page styles
│   └── admin.js                    # Copy API key, test connection
└── readme.txt                      # WordPress readme
```

### A.2 Plugin Header

```php
<?php
/**
 * Plugin Name: SimpleAd Manager Connector
 * Plugin URI: https://manager.simplead.ro
 * Description: Connects your WordPress site to SimpleAd Manager for monitoring, updates, and backups.
 * Version: 1.0.0
 * Author: SimpleAd
 * Requires at least: 5.6
 * Requires PHP: 8.0
 * License: GPL v2 or later
 */

defined('ABSPATH') || exit;

define('SAM_CONNECTOR_VERSION', '1.0.0');
define('SAM_CONNECTOR_PATH', plugin_dir_path(__FILE__));
define('SAM_CONNECTOR_URL', plugin_dir_url(__FILE__));

require_once SAM_CONNECTOR_PATH . 'includes/class-auth.php';
require_once SAM_CONNECTOR_PATH . 'includes/class-api.php';
require_once SAM_CONNECTOR_PATH . 'includes/class-site-info.php';
require_once SAM_CONNECTOR_PATH . 'includes/class-plugins.php';
require_once SAM_CONNECTOR_PATH . 'includes/class-themes.php';
require_once SAM_CONNECTOR_PATH . 'includes/class-core.php';
require_once SAM_CONNECTOR_PATH . 'includes/class-login.php';
require_once SAM_CONNECTOR_PATH . 'includes/class-admin.php';

// Initialize
add_action('rest_api_init', ['SAM_API', 'register_routes']);
add_action('admin_menu', ['SAM_Admin', 'register_menu']);

// Generate API keys on activation
register_activation_hook(__FILE__, function () {
    if (!get_option('sam_api_key')) {
        update_option('sam_api_key', wp_generate_password(32, false));
        update_option('sam_api_secret', wp_generate_password(64, false));
    }
});
```

### A.3 Authentication (HMAC-SHA256)

```php
// includes/class-auth.php

class SAM_Auth {

    /**
     * Verify incoming request from SimpleAd Manager
     */
    public static function verify_request(WP_REST_Request $request): bool {
        $api_key = $request->get_header('X-SAM-Key');
        $timestamp = $request->get_header('X-SAM-Timestamp');
        $signature = $request->get_header('X-SAM-Signature');

        // All headers required
        if (!$api_key || !$timestamp || !$signature) {
            return false;
        }

        // Verify API key matches
        if ($api_key !== get_option('sam_api_key')) {
            self::log_failed_attempt($request);
            return false;
        }

        // Timestamp must be within 5 minutes
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        // Rate limiting: max 100 requests per minute
        if (self::is_rate_limited()) {
            return false;
        }

        // Verify HMAC signature
        $secret = get_option('sam_api_secret');
        $method = $request->get_method();
        $path = $request->get_route();
        $body = $request->get_body() ?: '';

        $payload = $method . $path . $timestamp . $body;
        $expected_signature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected_signature, $signature)) {
            self::log_failed_attempt($request);
            return false;
        }

        // Track request for rate limiting
        self::track_request();

        return true;
    }

    private static function is_rate_limited(): bool {
        $transient_key = 'sam_rate_' . date('YmdHi');
        $count = (int) get_transient($transient_key);
        return $count >= 100;
    }

    private static function track_request(): void {
        $transient_key = 'sam_rate_' . date('YmdHi');
        $count = (int) get_transient($transient_key);
        set_transient($transient_key, $count + 1, 120);
    }

    private static function log_failed_attempt(WP_REST_Request $request): void {
        $fails = (int) get_transient('sam_failed_attempts');
        set_transient('sam_failed_attempts', $fails + 1, 600);

        // Block after 10 failed attempts in 10 minutes
        if ($fails >= 10) {
            set_transient('sam_blocked', true, 3600);
        }
    }

    public static function is_blocked(): bool {
        return (bool) get_transient('sam_blocked');
    }
}
```

### A.4 REST API Endpoints

```php
// includes/class-api.php

class SAM_API {

    public static function register_routes(): void {
        $namespace = 'simplead/v1';

        // Permission callback for all endpoints
        $auth = function (WP_REST_Request $request) {
            if (SAM_Auth::is_blocked()) {
                return new WP_Error('blocked', 'Too many failed attempts', ['status' => 429]);
            }
            return SAM_Auth::verify_request($request);
        };

        // Site info
        register_rest_route($namespace, '/info', [
            'methods' => 'GET',
            'callback' => ['SAM_SiteInfo', 'get_info'],
            'permission_callback' => $auth,
        ]);

        // Plugins
        register_rest_route($namespace, '/plugins', [
            'methods' => 'GET',
            'callback' => ['SAM_Plugins', 'get_list'],
            'permission_callback' => $auth,
        ]);
        register_rest_route($namespace, '/plugins/update', [
            'methods' => 'POST',
            'callback' => ['SAM_Plugins', 'update_plugins'],
            'permission_callback' => $auth,
        ]);

        // Themes
        register_rest_route($namespace, '/themes', [
            'methods' => 'GET',
            'callback' => ['SAM_Themes', 'get_list'],
            'permission_callback' => $auth,
        ]);
        register_rest_route($namespace, '/themes/update', [
            'methods' => 'POST',
            'callback' => ['SAM_Themes', 'update_themes'],
            'permission_callback' => $auth,
        ]);

        // Core update
        register_rest_route($namespace, '/core/update', [
            'methods' => 'POST',
            'callback' => ['SAM_Core', 'update_core'],
            'permission_callback' => $auth,
        ]);

        // Auto-login
        register_rest_route($namespace, '/login-url', [
            'methods' => 'POST',
            'callback' => ['SAM_Login', 'generate_login_url'],
            'permission_callback' => $auth,
        ]);

        // Health check
        register_rest_route($namespace, '/health', [
            'methods' => 'GET',
            'callback' => ['SAM_SiteInfo', 'health_check'],
            'permission_callback' => $auth,
        ]);
    }
}
```

### A.5 Site Info Endpoint

```php
// includes/class-site-info.php

class SAM_SiteInfo {

    public static function get_info(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        // Force check for updates
        wp_update_plugins();
        wp_update_themes();

        $updates_plugins = get_site_transient('update_plugins');
        $updates_themes = get_site_transient('update_themes');
        $updates_core = get_site_transient('update_core');

        return new WP_REST_Response([
            'wordpress' => [
                'version' => get_bloginfo('version'),
                'locale' => get_locale(),
                'multisite' => is_multisite(),
                'site_url' => get_site_url(),
                'home_url' => get_home_url(),
                'admin_url' => admin_url(),
                'admin_email' => get_option('admin_email'),
                'timezone' => wp_timezone_string(),
                'permalink_structure' => get_option('permalink_structure'),
                'blog_public' => (bool) get_option('blog_public'),
            ],
            'server' => [
                'php_version' => phpversion(),
                'mysql_version' => $wpdb->get_var('SELECT VERSION()'),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'max_upload_size' => wp_max_upload_size(),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'php_extensions' => get_loaded_extensions(),
            ],
            'database' => [
                'prefix' => $wpdb->prefix,
                'size_mb' => self::get_db_size(),
                'tables_count' => count($wpdb->get_results("SHOW TABLES")),
            ],
            'storage' => [
                'uploads_size_mb' => self::get_dir_size(wp_upload_dir()['basedir']),
                'total_size_mb' => self::get_dir_size(ABSPATH),
            ],
            'counts' => [
                'plugins_total' => count(get_plugins()),
                'plugins_active' => count(get_option('active_plugins', [])),
                'plugins_updates' => isset($updates_plugins->response) ? count($updates_plugins->response) : 0,
                'themes_total' => count(wp_get_themes()),
                'themes_updates' => isset($updates_themes->response) ? count($updates_themes->response) : 0,
                'core_update' => !empty($updates_core->updates) && $updates_core->updates[0]->response === 'upgrade',
                'posts' => (int) wp_count_posts()->publish,
                'pages' => (int) wp_count_posts('page')->publish,
                'users' => (int) count_users()['total_users'],
                'comments' => (int) wp_count_comments()->approved,
            ],
            'core_update_version' => !empty($updates_core->updates) ? $updates_core->updates[0]->version : null,
            'connector_version' => SAM_CONNECTOR_VERSION,
            'last_sync' => current_time('c'),
        ], 200);
    }

    public static function health_check(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'status' => 'ok',
            'version' => SAM_CONNECTOR_VERSION,
            'wp_version' => get_bloginfo('version'),
            'timestamp' => current_time('c'),
        ], 200);
    }

    private static function get_db_size(): float {
        global $wpdb;
        $result = $wpdb->get_var("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = '" . DB_NAME . "'");
        return (float) $result;
    }

    private static function get_dir_size(string $dir): float {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
        return round($size / 1024 / 1024, 2);
    }
}
```

### A.6 Plugins Endpoint

```php
// includes/class-plugins.php

class SAM_Plugins {

    public static function get_list(WP_REST_Request $request): WP_REST_Response {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $updates = get_site_transient('update_plugins');

        $plugins = [];
        foreach ($all_plugins as $file => $data) {
            $has_update = isset($updates->response[$file]);
            $update_info = $has_update ? $updates->response[$file] : null;

            $plugins[] = [
                'file' => $file,
                'name' => $data['Name'],
                'slug' => dirname($file) !== '.' ? dirname($file) : basename($file, '.php'),
                'version' => $data['Version'],
                'author' => $data['Author'],
                'author_uri' => $data['AuthorURI'] ?? null,
                'plugin_uri' => $data['PluginURI'] ?? null,
                'description' => $data['Description'],
                'is_active' => in_array($file, $active_plugins),
                'has_update' => $has_update,
                'update_version' => $update_info->new_version ?? null,
                'update_url' => $update_info->url ?? null,
                'requires_wp' => $data['RequiresWP'] ?? null,
                'requires_php' => $data['RequiresPHP'] ?? null,
                'network_active' => is_multisite() && is_plugin_active_for_network($file),
                'auto_update' => in_array($file, get_option('auto_update_plugins', [])),
            ];
        }

        return new WP_REST_Response([
            'plugins' => $plugins,
            'total' => count($plugins),
            'active' => count(array_filter($plugins, fn($p) => $p['is_active'])),
            'inactive' => count(array_filter($plugins, fn($p) => !$p['is_active'])),
            'updates_available' => count(array_filter($plugins, fn($p) => $p['has_update'])),
        ], 200);
    }

    public static function update_plugins(WP_REST_Request $request): WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugins_to_update = $request->get_param('plugins'); // array of plugin file paths
        
        if (empty($plugins_to_update)) {
            return new WP_REST_Response(['error' => 'No plugins specified'], 400);
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        $results = [];
        foreach ($plugins_to_update as $plugin_file) {
            $result = $upgrader->upgrade($plugin_file);
            
            // Get updated plugin data
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            
            $results[] = [
                'file' => $plugin_file,
                'name' => $plugin_data['Name'] ?? $plugin_file,
                'success' => $result === true || is_null($result),
                'new_version' => $plugin_data['Version'] ?? null,
                'error' => is_wp_error($result) ? $result->get_error_message() : null,
            ];
        }

        // Clear update cache
        wp_clean_plugins_cache();
        wp_update_plugins();

        return new WP_REST_Response([
            'results' => $results,
            'updated' => count(array_filter($results, fn($r) => $r['success'])),
            'failed' => count(array_filter($results, fn($r) => !$r['success'])),
        ], 200);
    }
}
```

### A.7 Themes Endpoint

```php
// includes/class-themes.php

class SAM_Themes {

    public static function get_list(WP_REST_Request $request): WP_REST_Response {
        $all_themes = wp_get_themes();
        $active_theme = wp_get_theme();
        $updates = get_site_transient('update_themes');

        $themes = [];
        foreach ($all_themes as $slug => $theme) {
            $has_update = isset($updates->response[$slug]);
            $update_info = $has_update ? $updates->response[$slug] : null;

            $screenshot = $theme->get_screenshot();

            $themes[] = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'author' => $theme->get('Author'),
                'author_uri' => $theme->get('AuthorURI'),
                'theme_uri' => $theme->get('ThemeURI'),
                'description' => $theme->get('Description'),
                'is_active' => ($slug === $active_theme->get_stylesheet()),
                'is_child_theme' => (bool) $theme->parent(),
                'parent_theme' => $theme->parent() ? $theme->parent()->get('Name') : null,
                'has_update' => $has_update,
                'update_version' => $update_info['new_version'] ?? null,
                'screenshot_url' => $screenshot ?: null,
                'requires_wp' => $theme->get('RequiresWP'),
                'requires_php' => $theme->get('RequiresPHP'),
                'auto_update' => in_array($slug, get_option('auto_update_themes', [])),
            ];
        }

        return new WP_REST_Response([
            'themes' => $themes,
            'total' => count($themes),
            'active_theme' => $active_theme->get('Name'),
            'updates_available' => count(array_filter($themes, fn($t) => $t['has_update'])),
        ], 200);
    }

    public static function update_themes(WP_REST_Request $request): WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $themes_to_update = $request->get_param('themes'); // array of theme slugs

        if (empty($themes_to_update)) {
            return new WP_REST_Response(['error' => 'No themes specified'], 400);
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);

        $results = [];
        foreach ($themes_to_update as $theme_slug) {
            $result = $upgrader->upgrade($theme_slug);
            $theme = wp_get_theme($theme_slug);

            $results[] = [
                'slug' => $theme_slug,
                'name' => $theme->get('Name'),
                'success' => $result === true || is_null($result),
                'new_version' => $theme->get('Version'),
                'error' => is_wp_error($result) ? $result->get_error_message() : null,
            ];
        }

        wp_clean_themes_cache();
        wp_update_themes();

        return new WP_REST_Response([
            'results' => $results,
            'updated' => count(array_filter($results, fn($r) => $r['success'])),
            'failed' => count(array_filter($results, fn($r) => !$r['success'])),
        ], 200);
    }
}
```

### A.8 Core Update Endpoint

```php
// includes/class-core.php

class SAM_Core {

    public static function update_core(WP_REST_Request $request): WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $updates = get_site_transient('update_core');

        if (empty($updates->updates) || $updates->updates[0]->response !== 'upgrade') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No core update available',
                'current_version' => get_bloginfo('version'),
            ], 200);
        }

        $target_version = $updates->updates[0]->version;

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);
        $result = $upgrader->upgrade($updates->updates[0]);

        return new WP_REST_Response([
            'success' => !is_wp_error($result),
            'previous_version' => get_bloginfo('version'),
            'new_version' => $target_version,
            'error' => is_wp_error($result) ? $result->get_error_message() : null,
        ], 200);
    }
}
```

### A.9 Auto-Login URL Generation

```php
// includes/class-login.php

class SAM_Login {

    public static function generate_login_url(WP_REST_Request $request): WP_REST_Response {
        $user_id = $request->get_param('user_id') ?: 1; // default to admin (ID 1)

        $user = get_user_by('ID', $user_id);
        if (!$user || !user_can($user, 'manage_options')) {
            return new WP_REST_Response(['error' => 'Invalid admin user'], 400);
        }

        // Generate one-time token
        $token = wp_generate_password(64, false);
        $expiry = time() + 60; // 60 seconds

        set_transient('sam_login_token_' . $token, [
            'user_id' => $user->ID,
            'ip' => $request->get_param('client_ip'),
            'expires' => $expiry,
        ], 120);

        $login_url = add_query_arg([
            'sam_auto_login' => $token,
        ], wp_login_url());

        return new WP_REST_Response([
            'login_url' => $login_url,
            'expires_in' => 60,
            'user' => $user->user_login,
        ], 200);
    }
}

// Hook into wp_loaded to process auto-login tokens
add_action('init', function () {
    if (empty($_GET['sam_auto_login'])) return;

    $token = sanitize_text_field($_GET['sam_auto_login']);
    $data = get_transient('sam_login_token_' . $token);

    if (!$data) {
        wp_die('Login link expired or invalid.');
    }

    // Delete token immediately (single use)
    delete_transient('sam_login_token_' . $token);

    // Check expiry
    if (time() > $data['expires']) {
        wp_die('Login link has expired.');
    }

    // Log in user
    $user = get_user_by('ID', $data['user_id']);
    if (!$user) {
        wp_die('User not found.');
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, false);
    do_action('wp_login', $user->user_login, $user);

    wp_redirect(admin_url());
    exit;
});
```

### A.10 Plugin Admin Page

```php
// includes/class-admin.php

class SAM_Admin {

    public static function register_menu(): void {
        add_options_page(
            'SimpleAd Connector',
            'SimpleAd Connector',
            'manage_options',
            'simplead-connector',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void {
        $api_key = get_option('sam_api_key');
        $api_secret = get_option('sam_api_secret');
        $dashboard_url = get_option('sam_dashboard_url', 'https://manager.simplead.ro');

        // Handle form submissions
        if (isset($_POST['sam_regenerate_keys']) && check_admin_referer('sam_settings')) {
            update_option('sam_api_key', wp_generate_password(32, false));
            update_option('sam_api_secret', wp_generate_password(64, false));
            $api_key = get_option('sam_api_key');
            $api_secret = get_option('sam_api_secret');
            echo '<div class="notice notice-success"><p>API keys regenerated.</p></div>';
        }

        if (isset($_POST['sam_save_settings']) && check_admin_referer('sam_settings')) {
            update_option('sam_dashboard_url', sanitize_url($_POST['sam_dashboard_url']));
            $dashboard_url = get_option('sam_dashboard_url');
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>SimpleAd Manager Connector</h1>

            <div class="card" style="max-width: 600px; padding: 20px;">
                <h2>Connection Status</h2>
                <p>
                    <span style="color: green; font-size: 18px;">●</span>
                    <strong>Connected</strong> — Plugin active and ready
                </p>
                <p>Connector version: <?php echo SAM_CONNECTOR_VERSION; ?></p>
                <p>WordPress version: <?php echo get_bloginfo('version'); ?></p>
            </div>

            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <h2>API Credentials</h2>
                <p>Use these credentials in SimpleAd Manager when adding this site.</p>

                <table class="form-table">
                    <tr>
                        <th>API Key</th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($api_key); ?>"
                                   readonly class="regular-text" id="sam-api-key">
                            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-key').value)">Copy</button>
                        </td>
                    </tr>
                    <tr>
                        <th>API Secret</th>
                        <td>
                            <input type="password" value="<?php echo esc_attr($api_secret); ?>"
                                   readonly class="regular-text" id="sam-api-secret">
                            <button type="button" class="button" onclick="
                                var el = document.getElementById('sam-api-secret');
                                el.type = el.type === 'password' ? 'text' : 'password';
                            ">Show/Hide</button>
                            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-secret').value)">Copy</button>
                        </td>
                    </tr>
                    <tr>
                        <th>REST API URL</th>
                        <td>
                            <input type="text" value="<?php echo esc_url(rest_url('simplead/v1/')); ?>"
                                   readonly class="regular-text" id="sam-api-url">
                            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-url').value)">Copy</button>
                        </td>
                    </tr>
                </table>

                <form method="post">
                    <?php wp_nonce_field('sam_settings'); ?>
                    <p>
                        <input type="submit" name="sam_regenerate_keys" class="button button-secondary"
                               value="Regenerate API Keys"
                               onclick="return confirm('This will invalidate the current connection. Continue?');">
                    </p>
                </form>
            </div>

            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <h2>Dashboard Settings</h2>
                <form method="post">
                    <?php wp_nonce_field('sam_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Dashboard URL</th>
                            <td>
                                <input type="url" name="sam_dashboard_url"
                                       value="<?php echo esc_attr($dashboard_url); ?>"
                                       class="regular-text">
                            </td>
                        </tr>
                    </table>
                    <p>
                        <input type="submit" name="sam_save_settings" class="button button-primary" value="Save Settings">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}
```

---

## PART B: DASHBOARD SIDE (Laravel)

### B.1 Database Schema

#### Migration: update `sites` table

Add WordPress connection fields to the existing sites table:

```php
Schema::table('sites', function (Blueprint $table) {
    $table->string('type')->default('wordpress')->after('domain'); // wordpress, custom, static
    $table->text('api_key')->nullable()->after('type'); // encrypted
    $table->text('api_secret')->nullable()->after('api_key'); // encrypted
    $table->string('api_endpoint')->nullable()->after('api_secret'); // e.g. https://site.com/wp-json/simplead/v1
    $table->boolean('is_connected')->default(false)->after('api_endpoint');
    $table->timestamp('last_synced_at')->nullable()->after('is_connected');
    
    // Cached WordPress info
    $table->string('wp_version')->nullable();
    $table->string('php_version')->nullable();
    $table->string('server_software')->nullable();
    $table->boolean('is_multisite')->default(false);
    $table->decimal('db_size_mb', 10, 2)->nullable();
    $table->decimal('uploads_size_mb', 10, 2)->nullable();
    $table->string('core_update_version')->nullable(); // null = up to date, version string = update available
});
```

#### Migration: `site_plugins`

```php
Schema::create('site_plugins', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    $table->string('file'); // plugin file path (e.g. akismet/akismet.php)
    $table->string('slug');
    $table->string('name');
    $table->string('version');
    $table->string('author')->nullable();
    $table->string('author_uri')->nullable();
    $table->string('plugin_uri')->nullable();
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(false);
    $table->boolean('has_update')->default(false);
    $table->string('update_version')->nullable();
    $table->string('requires_wp')->nullable();
    $table->string('requires_php')->nullable();
    $table->boolean('auto_update')->default(false);
    
    $table->timestamps();
    
    $table->unique(['site_id', 'file']);
    $table->index(['site_id', 'is_active']);
    $table->index(['site_id', 'has_update']);
});
```

#### Migration: `site_themes`

```php
Schema::create('site_themes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    
    $table->string('slug');
    $table->string('name');
    $table->string('version');
    $table->string('author')->nullable();
    $table->string('author_uri')->nullable();
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(false);
    $table->boolean('is_child_theme')->default(false);
    $table->string('parent_theme')->nullable();
    $table->boolean('has_update')->default(false);
    $table->string('update_version')->nullable();
    $table->string('screenshot_url')->nullable();
    $table->boolean('auto_update')->default(false);
    
    $table->timestamps();
    
    $table->unique(['site_id', 'slug']);
    $table->index(['site_id', 'is_active']);
    $table->index(['site_id', 'has_update']);
});
```

#### Migration: `update_logs`

Track all update actions (who updated what, when, result):

```php
Schema::create('update_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    
    $table->string('type'); // plugin, theme, core
    $table->string('name'); // plugin/theme name or "WordPress Core"
    $table->string('slug')->nullable();
    $table->string('from_version');
    $table->string('to_version');
    $table->boolean('success')->default(false);
    $table->text('error_message')->nullable();
    
    $table->timestamp('performed_at');
    
    $table->index(['site_id', 'performed_at']);
    $table->index(['site_id', 'type']);
});
```

### B.2 WordPress API Service

A service class on the Laravel side that communicates with the WordPress connector plugin:

```php
// app/Services/WordPressApiService.php

class WordPressApiService
{
    public function __construct(private Site $site) {}

    /**
     * Make an authenticated request to the WordPress connector plugin
     */
    public function request(string $method, string $path, array $data = []): array
    {
        $url = rtrim($this->site->api_endpoint, '/') . '/' . ltrim($path, '/');
        $timestamp = (string) time();
        $body = !empty($data) ? json_encode($data) : '';

        // Generate HMAC signature
        $payload = $method . '/simplead/v1/' . ltrim($path, '/') . $timestamp . $body;
        $signature = hash_hmac('sha256', $payload, decrypt($this->site->api_secret));

        $response = Http::withHeaders([
            'X-SAM-Key' => decrypt($this->site->api_key),
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->timeout(30)->{strtolower($method)}($url, $data);

        if ($response->failed()) {
            throw new \Exception("WordPress API error: {$response->status()} - {$response->body()}");
        }

        return $response->json();
    }

    // Convenience methods
    public function getInfo(): array { return $this->request('GET', 'info'); }
    public function getPlugins(): array { return $this->request('GET', 'plugins'); }
    public function getThemes(): array { return $this->request('GET', 'themes'); }
    
    public function updatePlugins(array $plugins): array {
        return $this->request('POST', 'plugins/update', ['plugins' => $plugins]);
    }
    
    public function updateThemes(array $themes): array {
        return $this->request('POST', 'themes/update', ['themes' => $themes]);
    }
    
    public function updateCore(): array {
        return $this->request('POST', 'core/update');
    }
    
    public function getLoginUrl(): array {
        return $this->request('POST', 'login-url', ['client_ip' => request()->ip()]);
    }
    
    public function healthCheck(): array {
        return $this->request('GET', 'health');
    }
}
```

### B.3 Sync Job

```php
// app/Jobs/SyncWordPressSite.php

class SyncWordPressSite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Site $site) {}

    public function handle(): void
    {
        if (!$this->site->is_connected || !$this->site->api_endpoint) return;

        $api = new WordPressApiService($this->site);

        try {
            // Sync site info
            $info = $api->getInfo();
            $this->site->update([
                'wp_version' => $info['wordpress']['version'] ?? null,
                'php_version' => $info['server']['php_version'] ?? null,
                'server_software' => $info['server']['server_software'] ?? null,
                'is_multisite' => $info['wordpress']['multisite'] ?? false,
                'db_size_mb' => $info['database']['size_mb'] ?? null,
                'uploads_size_mb' => $info['storage']['uploads_size_mb'] ?? null,
                'core_update_version' => $info['core_update_version'],
                'last_synced_at' => now(),
                'is_connected' => true,
            ]);

            // Sync plugins
            $pluginsData = $api->getPlugins();
            $this->syncPlugins($pluginsData['plugins'] ?? []);

            // Sync themes
            $themesData = $api->getThemes();
            $this->syncThemes($themesData['themes'] ?? []);

            // Update pending_updates_count on site
            $pendingUpdates = SitePlugin::where('site_id', $this->site->id)->where('has_update', true)->count()
                + SiteTheme::where('site_id', $this->site->id)->where('has_update', true)->count()
                + ($this->site->core_update_version ? 1 : 0);
            
            $this->site->update(['pending_updates_count' => $pendingUpdates]);

        } catch (\Exception $e) {
            $this->site->update([
                'is_connected' => false,
                'last_synced_at' => now(),
            ]);
            
            \Log::error("Failed to sync site {$this->site->name}: " . $e->getMessage());
        }
    }

    private function syncPlugins(array $plugins): void
    {
        $existingSlugs = [];

        foreach ($plugins as $plugin) {
            $existingSlugs[] = $plugin['file'];

            SitePlugin::updateOrCreate(
                ['site_id' => $this->site->id, 'file' => $plugin['file']],
                [
                    'slug' => $plugin['slug'],
                    'name' => $plugin['name'],
                    'version' => $plugin['version'],
                    'author' => strip_tags($plugin['author'] ?? ''),
                    'author_uri' => $plugin['author_uri'],
                    'plugin_uri' => $plugin['plugin_uri'],
                    'description' => $plugin['description'],
                    'is_active' => $plugin['is_active'],
                    'has_update' => $plugin['has_update'],
                    'update_version' => $plugin['update_version'],
                    'requires_wp' => $plugin['requires_wp'],
                    'requires_php' => $plugin['requires_php'],
                    'auto_update' => $plugin['auto_update'],
                ]
            );
        }

        // Remove plugins that no longer exist on the site
        SitePlugin::where('site_id', $this->site->id)
            ->whereNotIn('file', $existingSlugs)
            ->delete();
    }

    private function syncThemes(array $themes): void
    {
        $existingSlugs = [];

        foreach ($themes as $theme) {
            $existingSlugs[] = $theme['slug'];

            SiteTheme::updateOrCreate(
                ['site_id' => $this->site->id, 'slug' => $theme['slug']],
                [
                    'name' => $theme['name'],
                    'version' => $theme['version'],
                    'author' => strip_tags($theme['author'] ?? ''),
                    'author_uri' => $theme['author_uri'],
                    'description' => $theme['description'],
                    'is_active' => $theme['is_active'],
                    'is_child_theme' => $theme['is_child_theme'],
                    'parent_theme' => $theme['parent_theme'],
                    'has_update' => $theme['has_update'],
                    'update_version' => $theme['update_version'],
                    'screenshot_url' => $theme['screenshot_url'],
                    'auto_update' => $theme['auto_update'],
                ]
            );
        }

        SiteTheme::where('site_id', $this->site->id)
            ->whereNotIn('slug', $existingSlugs)
            ->delete();
    }
}
```

### B.4 Scheduler

```php
// Sync all connected WordPress sites every 6 hours
Schedule::call(function () {
    Site::where('is_connected', true)
        ->whereNotNull('api_endpoint')
        ->each(function ($site) {
            SyncWordPressSite::dispatch($site);
        });
})->everySixHours();
```

### B.5 Update Site Connection Flow

When adding or editing a site, the user provides the API key and secret from the WordPress plugin. The flow:

1. User enters API Key + Secret in site settings
2. System stores them encrypted
3. System runs a health check to verify connection
4. If successful, triggers a full sync immediately
5. Site card now shows "Connected" badge

---

## PART C: UI PAGES

### C.1 Site Settings — Connection Tab (`/sites/{site}/settings`)

Add a WordPress connection section to the existing site settings page:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Site Settings — simplead.ro                                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ WordPress Connection ──────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  Status: ● Connected  (last synced 2 hours ago)     [Sync Now]  │ │
│  │                                                                  │ │
│  │  API Endpoint                                                    │ │
│  │  [ https://simplead.ro/wp-json/simplead/v1                   ] │ │
│  │                                                                  │ │
│  │  API Key                                                         │ │
│  │  [ ●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●                       ] │ │
│  │                                                                  │ │
│  │  API Secret                                                      │ │
│  │  [ ●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●●           ] │ │
│  │                                                                  │ │
│  │  WordPress: 6.4.3  |  PHP: 8.2  |  Server: nginx/1.25          │ │
│  │  DB: 45.2 MB  |  Uploads: 1.2 GB                               │ │
│  │                                                                  │ │
│  │                         [Test Connection]  [Save Credentials]   │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Quick Actions ─────────────────────────────────────────────────┐ │
│  │                                                                  │ │
│  │  [ 🔗 Open WP Admin ]   — One-click auto-login                 │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

### C.2 Plugins & Themes Page (`/sites/{site}/plugins`)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Plugins & Themes — simplead.ro                          [Sync Now] │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  [Plugins (18)] [Themes (3)]          ← tab switch                   │
│                                                                       │
│  ┌─ Summary ───────────────────────────────────────────────────────┐ │
│  │  18 plugins (14 active, 4 inactive)  •  3 updates available    │ │
│  │                              [Update All (3)]  [Select multiple] │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  [All] [Active (14)] [Inactive (4)] [Updates (3)]   🔍 Search...   │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  [ ] Akismet Anti-spam                          v4.2.1  Active │ │
│  │      Akismet Team                                               │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  [ ] WooCommerce                  ⚡ Update 8.5 → 8.6  Active │ │
│  │      Automattic                              [Update]           │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  [ ] Yoast SEO                    ⚡ Update 22.1 → 22.3 Active │ │
│  │      Team Yoast                              [Update]           │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  [ ] Hello Dolly                                v1.7.2 Inactive│ │
│  │      Matt Mullenweg                                             │ │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ── Themes tab ──                                                    │
│                                                                       │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │  ┌──────────┐ Flavor Theme                     v2.1.0  Active │ │
│  │  │screenshot│ Flavor Studio          (Child of flavor-starter) │ │
│  │  └──────────┘                                                   │ │
│  ├─────────────────────────────────────────────────────────────────┤ │
│  │  ┌──────────┐ Twenty Twenty-Four    ⚡ 1.0 → 1.1    Inactive │ │
│  │  │screenshot│ WordPress Team                   [Update]        │ │
│  │  └──────────┘                                                   │ │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  Last synced: 2 hours ago                                            │
└─────────────────────────────────────────────────────────────────────┘
```

### C.3 Updates Page (`/sites/{site}/updates`)

A focused view showing only items with available updates + update history:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Updates — simplead.ro                                   [Sync Now] │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌─ Available Updates (4) ─────────────────────────────────────────┐ │
│  │                                                    [Update All]  │ │
│  │                                                                  │ │
│  │  🔵 WordPress Core         6.4.3 → 6.5.0              [Update] │ │
│  │  🟣 WooCommerce (plugin)   8.5.0 → 8.6.0              [Update] │ │
│  │  🟣 Yoast SEO (plugin)     22.1  → 22.3               [Update] │ │
│  │  🟢 Twenty Twenty-Four     1.0   → 1.1                [Update] │ │
│  │                                                                  │ │
│  └──────────────────────────────────────────────────────────────────┘ │
│                                                                       │
│  ┌─ Update History ────────────────────────────────────────────────┐ │
│  │  Date            │ Type    │ Name          │ Version    │ Status│ │
│  │ ─────────────────────────────────────────────────────────────── │ │
│  │  Feb 1, 15:30    │ Plugin  │ Akismet       │ 4.2→4.2.1 │ ✅   │ │
│  │  Feb 1, 15:30    │ Plugin  │ WPForms       │ 1.8→1.8.5 │ ✅   │ │
│  │  Jan 28, 10:00   │ Core    │ WordPress     │ 6.4→6.4.3 │ ✅   │ │
│  │  Jan 28, 10:00   │ Theme   │ flavor-starter│ 2.0→2.1   │ ✅   │ │
│  │  Jan 15, 09:00   │ Plugin  │ WooCommerce   │ 8.4→8.5   │ ❌ error │
│  └──────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────┘
```

### C.4 Update Site Overview

Add WordPress-specific cards to the site overview page:

```blade
{{-- WordPress info card --}}
<x-ui.card>
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm font-medium text-gray-500">WordPress</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $site->wp_version ?? 'N/A' }}</p>
        </div>
        @if($site->core_update_version)
            <x-ui.badge variant="yellow">Update to {{ $site->core_update_version }}</x-ui.badge>
        @else
            <x-ui.badge variant="green">Up to date</x-ui.badge>
        @endif
    </div>
    <p class="mt-2 text-xs text-gray-500">PHP {{ $site->php_version }} • {{ $site->server_software }}</p>
</x-ui.card>

{{-- Updates summary card --}}
<x-ui.card>
    <div class="flex items-center justify-between">
        <div>
            <p class="text-sm font-medium text-gray-500">Pending Updates</p>
            <p class="mt-1 text-lg font-semibold {{ $site->pending_updates_count > 0 ? 'text-yellow-600' : 'text-green-600' }}">
                {{ $site->pending_updates_count ?? 0 }}
            </p>
        </div>
        @if($site->pending_updates_count > 0)
            <a href="{{ route('sites.updates', $site) }}" class="text-sm text-purple-600 hover:text-purple-700">
                View updates →
            </a>
        @endif
    </div>
</x-ui.card>

{{-- Storage card --}}
<x-ui.card>
    <div>
        <p class="text-sm font-medium text-gray-500">Storage</p>
        <div class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-600">Database</span>
                <span class="font-medium">{{ $site->db_size_mb ?? '—' }} MB</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-600">Uploads</span>
                <span class="font-medium">{{ $site->uploads_size_mb ? number_format($site->uploads_size_mb / 1024, 1) . ' GB' : '—' }}</span>
            </div>
        </div>
    </div>
</x-ui.card>
```

### C.5 Update Site Card

Add connection status indicator to the site card:

```blade
{{-- Connection status on site card --}}
@if($site->type === 'wordpress')
    <div class="flex items-center gap-1" title="{{ $site->is_connected ? 'Connected' : 'Not connected' }}">
        <span class="h-2 w-2 rounded-full {{ $site->is_connected ? 'bg-blue-500' : 'bg-gray-300' }}"></span>
        <span class="text-xs text-gray-500">WP</span>
    </div>
@endif
```

---

## PART D: LIVEWIRE COMPONENTS

```
app/Livewire/
├── Sites/Detail/
│   ├── SitePlugins.php            # Plugins & Themes tabbed page
│   ├── SiteUpdates.php            # Updates page with update actions
│   └── SiteSettings.php           # Updated with WordPress connection section
│
├── Components/
│   ├── PluginRow.php              # Single plugin row with update button
│   ├── ThemeRow.php               # Single theme row
│   └── UpdateLogTable.php         # Update history table
│
└── Actions/
    ├── UpdatePlugin.php           # Livewire action: update single plugin
    ├── UpdateTheme.php            # Livewire action: update single theme
    ├── UpdateCore.php             # Livewire action: update WP core
    ├── BulkUpdate.php             # Livewire action: update all selected
    └── SyncSite.php               # Livewire action: trigger manual sync
```

---

## PART E: IMPORTANT — BUILD THE PLUGIN AS A DOWNLOADABLE ZIP

The WordPress connector plugin should be built as an actual installable WordPress plugin.

Create the plugin files in `/home/claude/simplead-connector/` and then zip it so it can be downloaded and installed on WordPress sites:

```bash
cd /home/claude
zip -r simplead-connector.zip simplead-connector/
cp simplead-connector.zip /mnt/user-data/outputs/
```

This zip file will be manually installed on each WordPress site via Plugins > Add New > Upload Plugin.

---

## PART F: IMPLEMENTATION CHECKLIST

### WordPress Connector Plugin
- [ ] Create plugin main file with header and bootstrap
- [ ] Create SAM_Auth class (HMAC-SHA256 verification, rate limiting, blocking)
- [ ] Create SAM_API class (register all REST routes)
- [ ] Create SAM_SiteInfo class (site info + health check endpoints)
- [ ] Create SAM_Plugins class (list + update plugins)
- [ ] Create SAM_Themes class (list + update themes)
- [ ] Create SAM_Core class (core update)
- [ ] Create SAM_Login class (auto-login URL generation + init hook)
- [ ] Create SAM_Admin class (settings page with API keys, copy buttons, test connection)
- [ ] Create plugin activation hook (generate API keys)
- [ ] Package as installable zip file

### Dashboard — Database & Models
- [ ] Create migration: update sites table with WordPress fields
- [ ] Create migration: site_plugins
- [ ] Create migration: site_themes
- [ ] Create migration: update_logs
- [ ] Create model: SitePlugin
- [ ] Create model: SiteTheme
- [ ] Create model: UpdateLog
- [ ] Update Site model with new relationships and casts

### Dashboard — Services & Jobs
- [ ] Create WordPressApiService (HMAC signing, all API methods)
- [ ] Create SyncWordPressSite job (sync info, plugins, themes)
- [ ] Add scheduler entry (every 6 hours)
- [ ] Handle connection errors gracefully

### Dashboard — UI Pages
- [ ] Update SiteSettings page with WordPress connection section (API key/secret input, test connection, save)
- [ ] Build SitePlugins page (plugins list, themes list, tabs, filters, search, update buttons)
- [ ] Build SiteUpdates page (available updates with update actions, update history table)
- [ ] Add "Sync Now" button functionality
- [ ] Add "Open WP Admin" button (auto-login)
- [ ] Add "Update" button per plugin/theme (with loading state and success/error feedback)
- [ ] Add "Update All" bulk action
- [ ] Update site overview with WordPress info cards (WP version, updates count, storage)
- [ ] Update site card with connection status indicator
- [ ] Log all update actions to update_logs table

### Integration
- [ ] Encrypt API key and secret at rest (Laravel encryption)
- [ ] Test connection flow: enter credentials → test → save → sync
- [ ] Sync triggers first time after successful connection
- [ ] Update pending_updates_count on site after each sync
- [ ] Wire notifications for failed updates (optional, via existing channels)
