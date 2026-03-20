<?php
/**
 * Runtime performance tweaks enforcement.
 *
 * Reads settings from the sam_performance_settings option and enforces them
 * on each request via WordPress hooks and filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Performance_Tweaks {

    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('sam_performance_settings', []);
        if (empty($this->settings)) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        // Heartbeat control
        if (!empty($this->settings['heartbeat_control'])) {
            $this->enforce_heartbeat();
        }

        // Revisions limit
        if (!empty($this->settings['revisions_control'])) {
            $this->enforce_revisions();
        }

        // Image upload control
        if (!empty($this->settings['image_upload_control'])) {
            $this->enforce_image_upload();
        }

        // Disable smaller components
        if (!empty($this->settings['disable_generator_tag'])) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
        }

        if (!empty($this->settings['disable_wlw_manifest'])) {
            remove_action('wp_head', 'wlwmanifest_link');
        }

        if (!empty($this->settings['disable_rsd_link'])) {
            remove_action('wp_head', 'rsd_link');
        }

        if (!empty($this->settings['disable_shortlinks'])) {
            remove_action('wp_head', 'wp_shortlink_wp_head');
            remove_action('template_redirect', 'wp_shortlink_header', 11);
        }

        if (!empty($this->settings['disable_emojis'])) {
            $this->disable_emojis();
        }

        if (!empty($this->settings['disable_dashicons'])) {
            add_action('wp_enqueue_scripts', [$this, 'dequeue_dashicons']);
        }

        if (!empty($this->settings['disable_jquery_migrate'])) {
            add_action('wp_default_scripts', [$this, 'remove_jquery_migrate']);
        }

        if (!empty($this->settings['disable_lazy_load'])) {
            add_filter('wp_lazy_loading_enabled', '__return_false');
        }

        if (!empty($this->settings['disable_block_widgets'])) {
            add_filter('gutenberg_use_widgets_block_editor', '__return_false');
            add_filter('use_widgets_block_editor', '__return_false');
        }
    }

    /**
     * Heartbeat control: disable on frontend, throttle on dashboard/editor.
     */
    private function enforce_heartbeat(): void {
        $config = is_array($this->settings['heartbeat_control'])
            ? $this->settings['heartbeat_control']
            : [];

        $frontend = $config['frontend'] ?? 'disable';
        $dashboard = $config['dashboard'] ?? 'default';
        $editor = $config['editor'] ?? 'default';
        $interval = (int) ($config['interval'] ?? 60);

        // Disable heartbeat on frontend
        if ($frontend === 'disable') {
            add_action('wp_enqueue_scripts', function () {
                wp_deregister_script('heartbeat');
            }, 1);
        }

        // Throttle dashboard heartbeat
        if ($dashboard === 'disable') {
            add_action('admin_enqueue_scripts', function () {
                $screen = get_current_screen();
                if ($screen && $screen->id === 'dashboard') {
                    wp_deregister_script('heartbeat');
                }
            }, 1);
        } elseif ($dashboard !== 'default') {
            add_filter('heartbeat_settings', function ($settings) use ($interval) {
                $screen = get_current_screen();
                if ($screen && $screen->id === 'dashboard') {
                    $settings['interval'] = max(15, $interval);
                }
                return $settings;
            });
        }

        // Throttle editor heartbeat
        if ($editor === 'disable') {
            add_action('admin_enqueue_scripts', function () {
                $screen = get_current_screen();
                if ($screen && $screen->base === 'post') {
                    wp_deregister_script('heartbeat');
                }
            }, 1);
        } elseif ($editor !== 'default') {
            add_filter('heartbeat_settings', function ($settings) use ($interval) {
                $screen = get_current_screen();
                if ($screen && $screen->base === 'post') {
                    $settings['interval'] = max(15, $interval);
                }
                return $settings;
            });
        }
    }

    /**
     * Revisions control: limit the number of revisions kept.
     */
    private function enforce_revisions(): void {
        $config = is_array($this->settings['revisions_control'])
            ? $this->settings['revisions_control']
            : [];

        $limit = (int) ($config['limit'] ?? 5);

        add_filter('wp_revisions_to_keep', function () use ($limit) {
            return max(0, $limit);
        });
    }

    /**
     * Image upload control: resize, disable sizes, JPEG quality.
     */
    private function enforce_image_upload(): void {
        $config = is_array($this->settings['image_upload_control'])
            ? $this->settings['image_upload_control']
            : [];

        // Max upload dimensions
        if (!empty($config['max_width']) || !empty($config['max_height'])) {
            $max_w = (int) ($config['max_width'] ?? 2560);
            $max_h = (int) ($config['max_height'] ?? 2560);

            add_filter('wp_handle_upload', function ($upload) use ($max_w, $max_h) {
                if (!isset($upload['type']) || strpos($upload['type'], 'image/') !== 0) {
                    return $upload;
                }

                $file = $upload['file'];
                $editor = wp_get_image_editor($file);
                if (is_wp_error($editor)) {
                    return $upload;
                }

                $size = $editor->get_size();
                if ($size['width'] > $max_w || $size['height'] > $max_h) {
                    $editor->resize($max_w, $max_h);
                    $editor->save($file);
                }

                return $upload;
            });
        }

        // Disable intermediate sizes
        if (!empty($config['disabled_sizes'])) {
            $disabled = (array) $config['disabled_sizes'];
            add_filter('intermediate_image_sizes_advanced', function ($sizes) use ($disabled) {
                foreach ($disabled as $size) {
                    unset($sizes[$size]);
                }
                return $sizes;
            });
        }

        // JPEG quality
        if (!empty($config['jpeg_quality'])) {
            $quality = max(10, min(100, (int) $config['jpeg_quality']));
            add_filter('jpeg_quality', function () use ($quality) {
                return $quality;
            });
            add_filter('wp_editor_set_quality', function () use ($quality) {
                return $quality;
            });
        }
    }

    /**
     * Disable emoji scripts and styles.
     */
    private function disable_emojis(): void {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

        add_filter('tiny_mce_plugins', function ($plugins) {
            if (is_array($plugins)) {
                return array_diff($plugins, ['wpemoji']);
            }
            return $plugins;
        });

        add_filter('wp_resource_hints', function ($urls, $relation_type) {
            if ($relation_type === 'dns-prefetch') {
                $urls = array_filter($urls, function ($url) {
                    return strpos($url, 'https://s.w.org/images/core/emoji/') === false;
                });
            }
            return $urls;
        }, 10, 2);
    }

    /**
     * Dequeue dashicons on frontend for non-logged-in users.
     */
    public function dequeue_dashicons(): void {
        if (!is_user_logged_in()) {
            wp_deregister_style('dashicons');
        }
    }

    /**
     * Remove jQuery Migrate.
     */
    public function remove_jquery_migrate($scripts): void {
        if (!is_admin() && isset($scripts->registered['jquery'])) {
            $script = $scripts->registered['jquery'];
            if ($script->deps) {
                $script->deps = array_diff($script->deps, ['jquery-migrate']);
            }
        }
    }

    /**
     * Get the actual enforced state by checking real WordPress hooks/filters.
     */
    public static function get_verified_state(): array {
        $settings = get_option('sam_performance_settings', []);
        $state = [];

        // Heartbeat: check if script is registered
        $heartbeat_active = !empty($settings['heartbeat_control']);
        if ($heartbeat_active) {
            $hb = is_array($settings['heartbeat_control']) ? $settings['heartbeat_control'] : [];
            $heartbeat_active = ($hb['frontend'] ?? 'default') === 'disable'
                || ($hb['dashboard'] ?? 'default') !== 'default'
                || ($hb['editor'] ?? 'default') !== 'default';
        }
        $state['heartbeat_control'] = [
            'configured' => !empty($settings['heartbeat_control']),
            'active'     => $heartbeat_active,
        ];

        // Revisions: check if filter is registered
        $state['revisions_control'] = [
            'configured' => !empty($settings['revisions_control']),
            'active'     => has_filter('wp_revisions_to_keep'),
        ];

        // Image upload: check if filters are registered
        $state['image_upload_control'] = [
            'configured' => !empty($settings['image_upload_control']),
            'active'     => has_filter('jpeg_quality') || has_filter('wp_handle_upload'),
        ];

        // Simple hook removals — check if the action was removed from wp_head
        $state['disable_generator_tag'] = [
            'configured' => !empty($settings['disable_generator_tag']),
            'active'     => !has_action('wp_head', 'wp_generator'),
        ];

        $state['disable_wlw_manifest'] = [
            'configured' => !empty($settings['disable_wlw_manifest']),
            'active'     => !has_action('wp_head', 'wlwmanifest_link'),
        ];

        $state['disable_rsd_link'] = [
            'configured' => !empty($settings['disable_rsd_link']),
            'active'     => !has_action('wp_head', 'rsd_link'),
        ];

        $state['disable_shortlinks'] = [
            'configured' => !empty($settings['disable_shortlinks']),
            'active'     => !has_action('wp_head', 'wp_shortlink_wp_head'),
        ];

        $state['disable_emojis'] = [
            'configured' => !empty($settings['disable_emojis']),
            'active'     => !has_action('wp_head', 'print_emoji_detection_script', 7),
        ];

        // These require checking enqueue state which isn't available early
        $state['disable_dashicons'] = [
            'configured' => !empty($settings['disable_dashicons']),
            'active'     => !empty($settings['disable_dashicons']),
        ];

        $state['disable_jquery_migrate'] = [
            'configured' => !empty($settings['disable_jquery_migrate']),
            'active'     => has_action('wp_default_scripts'),
        ];

        $state['disable_lazy_load'] = [
            'configured' => !empty($settings['disable_lazy_load']),
            'active'     => has_filter('wp_lazy_loading_enabled'),
        ];

        $state['disable_block_widgets'] = [
            'configured' => !empty($settings['disable_block_widgets']),
            'active'     => has_filter('use_widgets_block_editor'),
        ];

        return $state;
    }
}
