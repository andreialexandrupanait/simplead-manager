<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database + diagnostic endpoints for simplead-backup/v1.
 *
 *   POST simplead-backup/v1/database/dump   — run a consistent logical dump into a session
 *                                             dir, return the segment manifest.
 *   GET  simplead-backup/v1/diagnostic      — plugin health, temp status, recent log lines.
 *
 * This is the P2 seam the Laravel orchestrator will drive. Chunked file inventory, exclusions,
 * multipart upload and manifest/_COMPLETE are later P2 pieces (see TODO in the endpoint).
 */
final class SAM_Backup_Database_Endpoint extends SAM_Backup_REST_Controller {

    public function register_routes(): void {
        register_rest_route($this->namespace(), '/database/dump', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'dump'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    'session_id'    => array('type' => 'string', 'required' => false),
                    'time_budget'   => array('type' => 'number', 'required' => false),
                    'segment_bytes' => array('type' => 'integer', 'required' => false),
                    'exclude_tables'=> array('type' => 'array', 'required' => false),
                ),
            ),
        ));

        register_rest_route($this->namespace(), '/diagnostic', array(
            array(
                'methods'             => array('GET', 'POST'),
                'callback'            => array($this, 'diagnostic'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));
    }

    public function dump(WP_REST_Request $request): WP_REST_Response {
        $session_id = (string) ($request->get_param('session_id') ?: ('sess_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false)));
        $output_dir = SAM_Backup_Temp::session_dir($session_id) . '/database';

        $overrides = array(
            'time_budget'    => (float) ($request->get_param('time_budget') ?: SAM_Backup_Options::get('time_budget', 90)),
            'segment_bytes'  => (int) ($request->get_param('segment_bytes') ?: SAM_Backup_Options::get('segment_bytes', 8388608)),
            'exclude_tables' => (array) ($request->get_param('exclude_tables') ?: array()),
        );

        SAM_Backup_Logger::info('database/dump start', array('session' => $session_id));
        try {
            $dumper   = SAM_Backup_Consistent_Dumper::from_wp_constants($overrides);
            $manifest = $dumper->dump($output_dir);
        } catch (\Throwable $e) {
            SAM_Backup_Logger::error('database/dump failed', array('session' => $session_id, 'error' => $e->getMessage()));
            return new WP_REST_Response(array('ok' => false, 'error' => $e->getMessage()), 500);
        }

        $manifest['session_id'] = $session_id;
        $manifest['output_dir'] = $output_dir;
        SAM_Backup_Logger::info('database/dump done', array(
            'session'  => $session_id,
            'done'     => $manifest['done'],
            'tables'   => $manifest['table_count'],
            'rows'     => $manifest['total_rows'],
            'segments' => $manifest['segment_count'],
        ));

        return new WP_REST_Response($manifest, $manifest['done'] ? 200 : 202);
    }

    public function diagnostic(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(array(
            'ok'             => true,
            'plugin'         => 'simplead-backup',
            'version'        => SAM_BACKUP_VERSION,
            'temp_root'      => SAM_Backup_Temp::root(),
            'temp_exists'    => is_dir(SAM_Backup_Temp::root()),
            'temp_free_bytes'=> SAM_Backup_Temp::free_bytes(),
            'api_key_set'    => SAM_Backup_Options::api_key() !== '',
            'log_tail'       => SAM_Backup_Logger::tail(50),
            'time'           => gmdate('c'),
        ), 200);
    }
}
