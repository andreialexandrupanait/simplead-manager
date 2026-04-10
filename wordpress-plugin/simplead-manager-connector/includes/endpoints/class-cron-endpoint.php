<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron management endpoints.
 */
class SAM_Cron_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/cron-list', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_crons'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/cron-run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run_cron'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/cron-disable', [
            'methods'             => 'POST',
            'callback'            => [$this, 'disable_cron'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/cron-enable', [
            'methods'             => 'POST',
            'callback'            => [$this, 'enable_cron'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Get all hooks currently in the WP cron schedule.
     */
    private function get_scheduled_hooks(): array {
        $crons = _get_cron_array();
        if (empty($crons)) {
            return [];
        }

        $valid = [];
        foreach ($crons as $hooks) {
            $valid = array_merge($valid, array_keys($hooks));
        }

        return array_unique($valid);
    }

    public function list_crons(WP_REST_Request $request): WP_REST_Response {
        $crons = _get_cron_array();
        if (empty($crons)) {
            return $this->success(['crons' => []]);
        }

        $schedules = wp_get_schedules();
        $disabled = get_option('sam_disabled_crons', []);

        $cron_list = [];
        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                foreach ($events as $key => $event) {
                    $schedule_name = $event['schedule'] ?? 'once';
                    $interval = $event['interval'] ?? null;

                    if (!$interval && isset($schedules[$schedule_name])) {
                        $interval = $schedules[$schedule_name]['interval'];
                    }

                    $cron_list[] = [
                        'hook'          => $hook,
                        'args'          => $event['args'] ?? [],
                        'args_hash'     => $key,
                        'next_run'      => (int) $timestamp,
                        'next_run_human'=> human_time_diff($timestamp, time()),
                        'schedule'      => $schedule_name,
                        'schedule_label'=> $schedules[$schedule_name]['display'] ?? $schedule_name,
                        'interval'      => $interval,
                        'disabled'      => in_array($hook, $disabled, true),
                        'plugin'        => $this->detect_cron_plugin($hook),
                    ];
                }
            }
        }

        // Sort by next run
        usort($cron_list, fn($a, $b) => $a['next_run'] <=> $b['next_run']);

        return $this->success([
            'crons'     => $cron_list,
            'schedules' => $schedules,
        ]);
    }

    public function run_cron(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $hook = sanitize_text_field($params['hook'] ?? '');
        $args = $params['args'] ?? [];

        if (empty($hook)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'MISSING_HOOK', 'message' => 'Cron hook name is required.'],
            ], 400);
        }

        // Only allow hooks that are actually in the WP cron schedule
        $scheduled_hooks = $this->get_scheduled_hooks();
        if (!in_array($hook, $scheduled_hooks, true)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'HOOK_NOT_SCHEDULED', 'message' => 'Hook is not in the WP cron schedule. Only scheduled hooks can be executed.'],
            ], 403);
        }

        @set_time_limit(120);

        // Execute the hook
        do_action_ref_array($hook, $args);

        SAM_Audit_Logger::log('cron_executed', 'cron', $hook, 'Manually executed via SimpleAd Manager');

        return $this->success([
            'hook'    => $hook,
            'message' => "Cron hook '{$hook}' executed successfully.",
        ]);
    }

    public function disable_cron(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $hook = sanitize_text_field($params['hook'] ?? '');
        $args = $params['args'] ?? null;

        if (empty($hook)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'MISSING_HOOK', 'message' => 'Cron hook name is required.'],
            ], 400);
        }

        // Unschedule the event
        $crons = _get_cron_array();
        $unscheduled = false;

        foreach ($crons as $timestamp => $hooks) {
            if (isset($hooks[$hook])) {
                foreach ($hooks[$hook] as $key => $event) {
                    if ($args === null || $event['args'] === $args) {
                        wp_unschedule_event($timestamp, $hook, $event['args']);
                        $unscheduled = true;
                    }
                }
            }
        }

        // Track disabled crons
        $disabled = get_option('sam_disabled_crons', []);
        if (!in_array($hook, $disabled, true)) {
            $disabled[] = $hook;
            update_option('sam_disabled_crons', $disabled);
        }

        SAM_Audit_Logger::log('cron_disabled', 'cron', $hook, 'Disabled via SimpleAd Manager');

        return $this->success([
            'hook'         => $hook,
            'unscheduled'  => $unscheduled,
            'message'      => "Cron hook '{$hook}' has been disabled.",
        ]);
    }

    /**
     * Detect which plugin registered a cron hook.
     *
     * @return array{name: string, status: string}|null
     */
    private function detect_cron_plugin(string $hook): ?array {
        // WordPress core cron hooks
        $wp_core_hooks = [
            'wp_update_plugins', 'wp_update_themes', 'wp_version_check',
            'wp_scheduled_delete', 'wp_scheduled_auto_draft_delete',
            'delete_expired_transients', 'wp_privacy_delete_old_export_files',
            'wp_site_health_scheduled_check', 'recovery_mode_clean_expired_keys',
            'wp_https_detection', 'wp_update_comment_type_batch',
        ];

        if (in_array($hook, $wp_core_hooks, true)) {
            return ['name' => 'WordPress Core', 'status' => 'active'];
        }

        // Known plugin hook prefixes
        $known = [
            'woocommerce'      => ['woocommerce/woocommerce.php', 'WooCommerce'],
            'wc_'              => ['woocommerce/woocommerce.php', 'WooCommerce'],
            'yoast'            => ['wordpress-seo/wp-seo.php', 'Yoast SEO'],
            'wpseo'            => ['wordpress-seo/wp-seo.php', 'Yoast SEO'],
            'aioseo'           => ['all-in-one-seo-pack/all_in_one_seo_pack.php', 'All in One SEO'],
            'rank_math'        => ['seo-by-rank-math/rank-math.php', 'Rank Math'],
            'elementor'        => ['elementor/elementor.php', 'Elementor'],
            'jetpack'          => ['jetpack/jetpack.php', 'Jetpack'],
            'wordfence'        => ['wordfence/wordfence.php', 'Wordfence'],
            'litespeed'        => ['litespeed-cache/litespeed-cache.php', 'LiteSpeed Cache'],
            'updraft'          => ['updraftplus/updraftplus.php', 'UpdraftPlus'],
            'duplicator'       => ['duplicator/duplicator.php', 'Duplicator'],
            'mailchimp'        => ['mailchimp-for-wp/mailchimp-for-wp.php', 'MC4WP'],
            'wpforms'          => ['wpforms-lite/wpforms.php', 'WPForms'],
            'gravityforms'     => ['gravityforms/gravityforms.php', 'Gravity Forms'],
            'gf_'              => ['gravityforms/gravityforms.php', 'Gravity Forms'],
            'nf_'              => ['ninja-forms/ninja-forms.php', 'Ninja Forms'],
            'action_scheduler' => ['woocommerce/woocommerce.php', 'Action Scheduler'],
            'as_'              => ['woocommerce/woocommerce.php', 'Action Scheduler'],
            'ewwwio'           => ['ewww-image-optimizer/ewww-image-optimizer.php', 'EWWW Image Optimizer'],
            'smush'            => ['developer_smush/developer_smush.php', 'Smush'],
            'bp_'              => ['buddypress/bp-loader.php', 'BuddyPress'],
            'bbp_'             => ['bbpress/bbpress.php', 'bbPress'],
            'icl_'             => ['sitepress-multilingual-cms/sitepress.php', 'WPML'],
            'wpml_'            => ['sitepress-multilingual-cms/sitepress.php', 'WPML'],
            'sam_'             => ['simplead-manager-connector/simplead-manager-connector.php', 'SAM Connector'],
            'redirection'      => ['redirection/redirection.php', 'Redirection'],
        ];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active_plugins = get_option('active_plugins', []);

        foreach ($known as $prefix => $info) {
            if (strpos($hook, $prefix) === 0 || strpos($hook, $prefix) !== false) {
                $status = 'not-installed';
                $all_plugins = get_plugins();
                if (isset($all_plugins[$info[0]])) {
                    $status = in_array($info[0], $active_plugins, true) ? 'active' : 'inactive';
                }
                return ['name' => $info[1], 'status' => $status];
            }
        }

        return null;
    }

    public function enable_cron(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $hook = sanitize_text_field($params['hook'] ?? '');
        $schedule = sanitize_text_field($params['schedule'] ?? 'daily');
        $args = $params['args'] ?? [];

        if (empty($hook)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'MISSING_HOOK', 'message' => 'Cron hook name is required.'],
            ], 400);
        }

        // Only allow re-enabling hooks that were previously disabled by us
        $disabled = get_option('sam_disabled_crons', []);
        if (!in_array($hook, $disabled, true)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'HOOK_NOT_DISABLED', 'message' => 'Only hooks previously disabled by SimpleAd Manager can be re-enabled.'],
            ], 403);
        }

        // Validate schedule
        $schedules = wp_get_schedules();
        if (!isset($schedules[$schedule])) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'INVALID_SCHEDULE', 'message' => "Unknown schedule: {$schedule}"],
            ], 400);
        }

        // Schedule the event
        $result = wp_schedule_event(time(), $schedule, $hook, $args);

        // Remove from disabled list
        $disabled = array_values(array_diff($disabled, [$hook]));
        update_option('sam_disabled_crons', $disabled);

        SAM_Audit_Logger::log('cron_enabled', 'cron', $hook, "Re-enabled with schedule: {$schedule} via SimpleAd Manager");

        return $this->success([
            'hook'     => $hook,
            'schedule' => $schedule,
            'message'  => "Cron hook '{$hook}' has been enabled with schedule '{$schedule}'.",
        ]);
    }
}
