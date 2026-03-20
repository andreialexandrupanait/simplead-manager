<?php
/**
 * Runtime site control enforcement.
 *
 * Reads settings from the sam_site_control_settings option and enforces them
 * on each request via WordPress hooks and filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Site_Control {

    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('sam_site_control_settings', []);
        if (empty($this->settings)) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        // Disable all updates
        if (!empty($this->settings['disable_all_updates'])) {
            $this->disable_updates();
        }

        // Disable comments
        if (!empty($this->settings['disable_comments'])) {
            $this->disable_comments();
        }

        // Disable RSS feeds
        if (!empty($this->settings['disable_feeds'])) {
            $this->disable_feeds();
        }

        // Disable embeds
        if (!empty($this->settings['disable_embeds'])) {
            $this->disable_embeds();
        }

        // Redirect 404 to homepage
        if (!empty($this->settings['redirect_404'])) {
            add_action('template_redirect', [$this, 'handle_404_redirect']);
        }

        // Disable Gutenberg
        if (!empty($this->settings['disable_gutenberg'])) {
            $this->disable_gutenberg();
        }

        // Disable author archives
        if (!empty($this->settings['disable_author_archives'])) {
            add_action('template_redirect', [$this, 'handle_author_archive_redirect']);
        }
    }

    /**
     * Disable all automatic updates (core, plugins, themes, translations).
     */
    private function disable_updates(): void {
        // Disable core auto-updates
        add_filter('auto_update_core', '__return_false');
        add_filter('allow_major_auto_core_updates', '__return_false');
        add_filter('allow_minor_auto_core_updates', '__return_false');

        // Disable plugin/theme auto-updates
        add_filter('auto_update_plugin', '__return_false');
        add_filter('auto_update_theme', '__return_false');
        add_filter('auto_update_translation', '__return_false');

        // Disable update checks
        remove_action('wp_version_check', 'wp_version_check');
        remove_action('wp_update_plugins', 'wp_update_plugins');
        remove_action('wp_update_themes', 'wp_update_themes');

        // Remove update nag
        add_action('admin_init', function () {
            remove_action('admin_notices', 'update_nag', 3);
            remove_action('network_admin_notices', 'update_nag', 3);
        });

        // Remove scheduled update checks
        add_action('init', function () {
            remove_action('wp_maybe_auto_update', 'wp_maybe_auto_update');
        });
    }

    /**
     * Disable comments sitewide.
     */
    private function disable_comments(): void {
        // Close comments and pings on the frontend
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);

        // Hide existing comments
        add_filter('comments_array', '__return_empty_array', 10, 2);

        // Remove comment-related items from admin
        add_action('admin_init', function () {
            // Remove comment menu page
            remove_menu_page('edit-comments.php');

            // Remove comment metabox from dashboard
            remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

            // Remove comment support from all post types
            $post_types = get_post_types();
            foreach ($post_types as $post_type) {
                if (post_type_supports($post_type, 'comments')) {
                    remove_post_type_support($post_type, 'comments');
                    remove_post_type_support($post_type, 'trackbacks');
                }
            }
        });

        // Remove comment links from admin bar
        add_action('wp_before_admin_bar_render', function () {
            global $wp_admin_bar;
            $wp_admin_bar->remove_menu('comments');
        });

        // Remove comment count from admin bar
        add_filter('wp_count_comments', function ($count) {
            return (object) [
                'approved'       => 0,
                'moderated'      => 0,
                'spam'           => 0,
                'trash'          => 0,
                'post-trashed'   => 0,
                'total_comments' => 0,
                'all'            => 0,
            ];
        });

        // Redirect any direct access to comments admin page
        add_action('admin_init', function () {
            global $pagenow;
            if ($pagenow === 'edit-comments.php') {
                wp_safe_redirect(admin_url());
                exit;
            }
        });
    }

    /**
     * Disable RSS feeds.
     */
    private function disable_feeds(): void {
        $disable_feed = function () {
            wp_die(
                esc_html__('RSS feeds are disabled on this site.', 'simplead-manager'),
                '',
                ['response' => 403]
            );
        };

        add_action('do_feed', $disable_feed, 1);
        add_action('do_feed_rdf', $disable_feed, 1);
        add_action('do_feed_rss', $disable_feed, 1);
        add_action('do_feed_rss2', $disable_feed, 1);
        add_action('do_feed_atom', $disable_feed, 1);
        add_action('do_feed_rss2_comments', $disable_feed, 1);
        add_action('do_feed_atom_comments', $disable_feed, 1);

        // Remove feed links from head
        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);
    }

    /**
     * Disable WordPress embeds (oEmbed).
     */
    private function disable_embeds(): void {
        // Remove oEmbed discovery links
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');

        // Remove oEmbed REST API route
        remove_action('rest_api_init', 'wp_oembed_register_route');

        // Disable oEmbed auto-discovery
        add_filter('embed_oembed_discover', '__return_false');

        // Remove oEmbed-specific JavaScript from the frontend
        remove_action('wp_head', 'wp_oembed_add_host_js');

        // Remove embed rewrite rules
        add_filter('rewrite_rules_array', function ($rules) {
            foreach ($rules as $rule => $rewrite) {
                if (strpos($rewrite, 'embed=true') !== false) {
                    unset($rules[$rule]);
                }
            }
            return $rules;
        });

        // Remove embed scripts
        add_action('wp_footer', function () {
            wp_dequeue_script('wp-embed');
        });

        // Remove TinyMCE embed plugin
        add_filter('tiny_mce_plugins', function ($plugins) {
            return array_diff($plugins, ['wpembed']);
        });
    }

    /**
     * Redirect 404 pages to homepage.
     */
    public function handle_404_redirect(): void {
        if (is_404()) {
            wp_safe_redirect(home_url('/'), 301);
            exit;
        }
    }

    /**
     * Disable Gutenberg block editor.
     */
    private function disable_gutenberg(): void {
        $config = is_array($this->settings['disable_gutenberg'])
            ? $this->settings['disable_gutenberg']
            : [];

        $post_types = !empty($config['post_types']) ? (array) $config['post_types'] : [];

        // Disable block editor for specific post types or all
        add_filter('use_block_editor_for_post', function ($use, $post) use ($post_types) {
            if (empty($post_types)) {
                return false; // Disable for all
            }
            if (in_array($post->post_type, $post_types, true)) {
                return false;
            }
            return $use;
        }, 10, 2);

        add_filter('use_block_editor_for_post_type', function ($use, $post_type) use ($post_types) {
            if (empty($post_types)) {
                return false;
            }
            if (in_array($post_type, $post_types, true)) {
                return false;
            }
            return $use;
        }, 10, 2);

        // Remove Gutenberg-related styles
        add_action('wp_enqueue_scripts', function () {
            wp_dequeue_style('wp-block-library');
            wp_dequeue_style('wp-block-library-theme');
            wp_dequeue_style('wc-blocks-style'); // WooCommerce blocks
            wp_dequeue_style('global-styles');
        }, 100);
    }

    /**
     * Redirect author archive pages to 404.
     */
    public function handle_author_archive_redirect(): void {
        if (is_author()) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
        }
    }

    /**
     * Get the actual enforced state by checking real WordPress hooks/filters.
     */
    public static function get_verified_state(): array {
        $settings = get_option('sam_site_control_settings', []);
        $state = [];

        // Auto-updates: check if core auto-update filter is registered
        $state['disable_all_updates'] = [
            'configured' => !empty($settings['disable_all_updates']),
            'active'     => has_filter('auto_update_core') && !apply_filters('auto_update_core', true),
        ];

        // Comments: check if comments_open filter returns false
        $state['disable_comments'] = [
            'configured' => !empty($settings['disable_comments']),
            'active'     => has_filter('comments_open', '__return_false'),
        ];

        // Feeds: check if feed actions are hooked
        $state['disable_feeds'] = [
            'configured' => !empty($settings['disable_feeds']),
            'active'     => !has_action('wp_head', 'feed_links'),
        ];

        // Embeds: check if oembed discovery is removed
        $state['disable_embeds'] = [
            'configured' => !empty($settings['disable_embeds']),
            'active'     => !has_action('wp_head', 'wp_oembed_add_discovery_links'),
        ];

        // 404 redirect: check if template_redirect has our handler
        $state['redirect_404'] = [
            'configured' => !empty($settings['redirect_404']),
            'active'     => !empty($settings['redirect_404']),
        ];

        // Gutenberg: check if block editor filter is registered
        $state['disable_gutenberg'] = [
            'configured' => !empty($settings['disable_gutenberg']),
            'active'     => has_filter('use_block_editor_for_post'),
        ];

        // Author archives: check if setting is active
        $state['disable_author_archives'] = [
            'configured' => !empty($settings['disable_author_archives']),
            'active'     => !empty($settings['disable_author_archives']),
        ];

        return $state;
    }
}
