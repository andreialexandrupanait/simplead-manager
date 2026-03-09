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
