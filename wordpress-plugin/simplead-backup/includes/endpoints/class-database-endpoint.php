<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database + diagnostic endpoints for simplead-backup/v1.
 *
 *   POST simplead-backup/v1/database/dump           — run a consistent logical dump into a session
 *                                                     dir, return the segment manifest.
 *   POST simplead-backup/v1/database/chunk-download  — stream one dumped segment (chunk_N.sql.gz);
 *                                                     `delete=true` frees it after (pull-and-free →
 *                                                     manager temp/WP temp both stay bounded).
 *   GET  simplead-backup/v1/diagnostic              — plugin health, temp status, recent log lines.
 *
 * This is the P2 seam the Laravel orchestrator drives: database/dump → for each segment
 * { database/chunk-download delete=1 } → upload to S3. Symmetric with the files half.
 */
final class SAM_Backup_Database_Endpoint extends SAM_Backup_REST_Controller {

    public function register_routes(): void {
        register_rest_route($this->namespace(), '/database/dump', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'dump'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    'run_id'        => array('type' => 'string', 'required' => false),
                    'session_id'    => array('type' => 'string', 'required' => false),
                    'time_budget'   => array('type' => 'number', 'required' => false),
                    'segment_bytes' => array('type' => 'integer', 'required' => false),
                    'batch_rows'    => array('type' => 'integer', 'required' => false),
                    'exclude_tables'=> array('type' => 'array', 'required' => false),
                ),
            ),
        ));

        register_rest_route($this->namespace(), '/database/chunk-download', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'chunk_download'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    // One of run_id / session_id must be present; enforced in the handler
                    // because either key satisfies it (schema "required" cannot say OR).
                    'run_id'      => array('type' => 'string',  'required' => false),
                    'session_id'  => array('type' => 'string',  'required' => false),
                    'chunk_index' => array('type' => 'integer', 'required' => true),
                    'delete'      => array('type' => 'boolean', 'required' => false),
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
        // The first call of every backup, and therefore the one moment we can count on happening
        // on every site, every night. Restore leftovers are collected here so a host whose cron
        // never runs — DISABLE_WP_CRON is common, and the sweep is scheduled work — still gets its
        // disk back, and gets it back before this backup starts writing to the same directory.
        try {
            SAM_Backup_Plugin::instance()->sweep_restore_leftovers();
        } catch (\Throwable $e) {
            SAM_Backup_Logger::warn('leftover sweep failed', array('error' => $e->getMessage()));
        }

        $session_id = ($this->session_id_from($request) ?: ('sess_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false)));
        $output_dir = SAM_Backup_Temp::session_dir($session_id) . '/database';

        // `??`, not `?:`. With the old falsy-coalesce an explicit 0 or an empty
        // string fell silently through to the default, so a caller could not
        // express "no budget" and — worse — could not tell that its value had
        // been ignored. batch_rows is newly exposed: the dumper has always
        // accepted it, and it is the real lever on the host's peak memory,
        // because it decides how many rows a single SELECT pulls into PHP.
        $overrides = array(
            'time_budget'    => (float) ($request->get_param('time_budget') ?? SAM_Backup_Options::get('time_budget', 90)),
            'segment_bytes'  => (int) ($request->get_param('segment_bytes') ?? SAM_Backup_Options::get('segment_bytes', 8388608)),
            // A ceiling on the page size, not a fixed one — the dumper sizes each page from the
            // memory actually free. Read from the options like its neighbours; the literal here
            // meant the one knob that matters on a memory-poor host could not be set per site.
            'batch_rows'     => $this->positive_int($request->get_param('batch_rows'), (int) SAM_Backup_Options::get('batch_rows', 1000), 1000),
            'exclude_tables' => (array) ($request->get_param('exclude_tables') ?? array()),
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

    /**
     * A positive integer from the request, the option, or the built-in default.
     *
     * Zero is not a meaningful page size — as a request parameter it used to reach
     * the dumper as "one SELECT per row" — so it falls through to the next source
     * rather than being honoured.
     *
     * @param mixed $param
     */
    private function positive_int($param, int $option, int $fallback): int {
        $value = (int) $param;
        if ($value > 0) {
            return $value;
        }

        return $option > 0 ? $option : $fallback;
    }

    /**
     * Stream one dumped DB segment (chunk_N.sql.gz) to the manager; delete=true frees
     * it afterwards so WP temp stays bounded (pull-and-free), symmetric with files.
     */
    public function chunk_download(WP_REST_Request $request) {
        $session_id   = $this->session_id_from($request);
        $chunk_index  = (int) $request->get_param('chunk_index');
        $delete_after = (bool) $request->get_param('delete');

        if ($session_id === '') {
            return new WP_REST_Response(array('ok' => false, 'error' => array('code' => 'NOT_FOUND', 'message' => 'Missing run_id.')), 404);
        }

        $db_dir  = SAM_Backup_Temp::session_dir($session_id) . '/database';
        $segment = $db_dir . '/chunk_' . $chunk_index . '.sql.gz';
        $real    = realpath($segment);

        // Path guard: the resolved file must stay inside this session's database dir.
        if ($real === false || strpos($real, realpath($db_dir) . '/') !== 0 || !is_file($real)) {
            return new WP_REST_Response(array('ok' => false, 'error' => array('code' => 'NOT_FOUND', 'message' => "Segment {$chunk_index} not found.")), 404);
        }

        $size = filesize($real);

        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/gzip');
        header('Content-Length: ' . $size);
        header('X-SAM-Chunk-Index: ' . $chunk_index);
        header('X-SAM-Chunk-Sha256: ' . (string) hash_file('sha256', $real));

        $fh = fopen($real, 'rb');
        if ($fh !== false) {
            while (!feof($fh)) {
                $buf = fread($fh, 524288);
                if ($buf === false) {
                    break;
                }
                echo $buf;
            }
            fclose($fh);
        }

        if ($delete_after) {
            @unlink($real); // free WP session temp; manifest fragment already returned by dump
        }
        exit;
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
