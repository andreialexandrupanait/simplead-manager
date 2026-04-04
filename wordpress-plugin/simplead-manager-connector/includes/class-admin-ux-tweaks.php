<?php
/**
 * Runtime admin UX tweaks enforcement.
 *
 * Reads settings from the sam_admin_ux_settings option and enforces them
 * on each request via WordPress hooks and filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Admin_UX_Tweaks {

    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('sam_admin_ux_settings', []);
        if (empty($this->settings)) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        // Clean admin bar
        if (!empty($this->settings['clean_admin_bar'])) {
            add_action('admin_bar_menu', [$this, 'clean_admin_bar'], 999);
        }

        // Hide admin notices
        if (!empty($this->settings['hide_admin_notices'])) {
            add_action('admin_notices', function () { ob_start(); }, 0);
            add_action('admin_notices', function () { ob_end_clean(); }, PHP_INT_MAX);
            add_action('all_admin_notices', function () { ob_start(); }, 0);
            add_action('all_admin_notices', function () { ob_end_clean(); }, PHP_INT_MAX);
        }

        // Disable dashboard widgets
        if (!empty($this->settings['disable_dashboard_widgets'])) {
            add_action('wp_dashboard_setup', [$this, 'remove_dashboard_widgets'], 999);
        }

        // Custom admin CSS
        if (!empty($this->settings['custom_admin_css'])) {
            add_action('admin_head', [$this, 'inject_admin_css']);
        }

        // Custom frontend CSS
        if (!empty($this->settings['custom_frontend_css'])) {
            add_action('wp_head', [$this, 'inject_frontend_css']);
        }

        // Hide admin bar
        if (!empty($this->settings['hide_admin_bar'])) {
            $this->enforce_hide_admin_bar();
        }

        // Admin menu organizer
        if (!empty($this->settings['admin_menu_organizer'])) {
            add_action('admin_menu', [$this, 'organize_admin_menu'], 999);
        }

        // Custom admin footer
        if (!empty($this->settings['custom_admin_footer'])) {
            add_filter('admin_footer_text', [$this, 'custom_footer_text']);
        }

        // Wider admin menu
        if (!empty($this->settings['wider_admin_menu'])) {
            add_action('admin_head', [$this, 'wider_admin_menu_css']);
        }
    }

    /**
     * Clean the admin bar by removing selected nodes.
     */
    public function clean_admin_bar($wp_admin_bar): void {
        $config = is_array($this->settings['clean_admin_bar'])
            ? $this->settings['clean_admin_bar']
            : [];

        if (!empty($config['remove_wp_logo'])) {
            $wp_admin_bar->remove_node('wp-logo');
        }
        if (!empty($config['remove_comments'])) {
            $wp_admin_bar->remove_node('comments');
        }
        if (!empty($config['remove_new_content'])) {
            $wp_admin_bar->remove_node('new-content');
        }
        if (!empty($config['remove_customize'])) {
            $wp_admin_bar->remove_node('customize');
        }
    }

    /**
     * Remove selected dashboard widgets.
     */
    public function remove_dashboard_widgets(): void {
        $config = is_array($this->settings['disable_dashboard_widgets'])
            ? $this->settings['disable_dashboard_widgets']
            : [];

        if (!empty($config['remove_welcome'])) {
            remove_action('welcome_panel', 'wp_welcome_panel');
        }
        if (!empty($config['remove_quick_press'])) {
            remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        }
        if (!empty($config['remove_activity'])) {
            remove_meta_box('dashboard_activity', 'dashboard', 'normal');
        }
        if (!empty($config['remove_primary'])) {
            remove_meta_box('dashboard_primary', 'dashboard', 'side');
        }
        if (!empty($config['remove_events'])) {
            remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
        }
    }

    /**
     * Inject custom CSS into admin head.
     */
    public function inject_admin_css(): void {
        $config = is_array($this->settings['custom_admin_css'])
            ? $this->settings['custom_admin_css']
            : [];

        $css = $config['css'] ?? '';
        if (empty($css)) {
            return;
        }

        // Sanitize: strip tags to prevent script injection
        $css = wp_strip_all_tags($css);
        echo '<style id="sam-admin-css">' . $css . '</style>';
    }

    /**
     * Inject custom CSS into frontend head.
     */
    public function inject_frontend_css(): void {
        $config = is_array($this->settings['custom_frontend_css'])
            ? $this->settings['custom_frontend_css']
            : [];

        $css = $config['css'] ?? '';
        if (empty($css)) {
            return;
        }

        $css = wp_strip_all_tags($css);
        echo '<style id="sam-frontend-css">' . $css . '</style>';
    }

    /**
     * Hide admin bar for specific roles.
     */
    private function enforce_hide_admin_bar(): void {
        $config = is_array($this->settings['hide_admin_bar'])
            ? $this->settings['hide_admin_bar']
            : [];

        $hide_for = $config['hide_for'] ?? 'non_admins';

        add_filter('show_admin_bar', function ($show) use ($hide_for) {
            if ($hide_for === 'all') {
                return false;
            }

            if ($hide_for === 'non_admins' && !current_user_can('manage_options')) {
                return false;
            }

            if ($hide_for === 'non_editors' && !current_user_can('edit_others_posts')) {
                return false;
            }

            return $show;
        });
    }

    /**
     * Hide selected admin menu items.
     */
    public function organize_admin_menu(): void {
        $config = is_array($this->settings['admin_menu_organizer'])
            ? $this->settings['admin_menu_organizer']
            : [];

        $hidden = $config['hidden_items'] ?? [];
        if (empty($hidden)) {
            return;
        }

        // Don't hide menu items for super admins
        if (current_user_can('manage_options')) {
            return;
        }

        foreach ($hidden as $menu_slug) {
            remove_menu_page(sanitize_text_field($menu_slug));
        }
    }

    /**
     * Replace admin footer text.
     */
    public function custom_footer_text(): string {
        $config = is_array($this->settings['custom_admin_footer'])
            ? $this->settings['custom_admin_footer']
            : [];

        return esc_html($config['text'] ?? '');
    }

    /**
     * CSS for wider admin menu.
     */
    public function wider_admin_menu_css(): void {
        echo '<style id="sam-wider-menu">
            @media screen and (min-width: 783px) {
                #adminmenuback, #adminmenuwrap, #adminmenu { width: 200px; }
                #wpcontent, #wpfooter { margin-left: 200px; }
                #adminmenu .wp-submenu { left: 200px; }
            }
        </style>';
    }

    /**
     * Get the actual enforced state.
     */
    public static function get_verified_state(): array {
        $settings = get_option('sam_admin_ux_settings', []);
        $state = [];

        $keys = [
            'clean_admin_bar', 'hide_admin_notices', 'disable_dashboard_widgets',
            'custom_admin_css', 'custom_frontend_css', 'hide_admin_bar',
            'admin_menu_organizer', 'custom_admin_footer', 'wider_admin_menu',
        ];

        foreach ($keys as $key) {
            $state[$key] = [
                'configured' => !empty($settings[$key]),
                'active'     => !empty($settings[$key]),
            ];
        }

        return $state;
    }
}
