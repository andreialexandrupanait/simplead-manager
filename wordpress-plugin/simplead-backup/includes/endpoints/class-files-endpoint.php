<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File inventory + chunking endpoints for simplead-backup/v1 (the files half of a backup).
 *
 *   POST simplead-backup/v1/files/inventory      — walk ABSPATH, apply exclusions, return totals
 *                                                   + exclusion_policy_hash + chunk plan (no reads).
 *                                                   `preview=true` → counts/estimate only, no session.
 *   POST simplead-backup/v1/files/chunk-exec      — materialise ONE chunk (by chunk_index) into the
 *                                                   session temp; idempotent; empty-chunk skipped.
 *   POST simplead-backup/v1/files/chunk-download  — stream a built chunk zip; `delete=true` frees
 *                                                   temp after (pull-and-free → temp stays bounded).
 *
 * The manager drives: inventory → for each non-empty chunk { chunk-exec ; chunk-download delete=1 }.
 * Each chunk-exec's `files[]` fragment ({path_rel,size,sha256,chunk_index}) feeds the global manifest.
 */
final class SAM_Backup_Files_Endpoint extends SAM_Backup_REST_Controller {

    public function register_routes(): void {
        register_rest_route($this->namespace(), '/files/inventory', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'inventory'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    'session_id'       => array('type' => 'string',  'required' => false),
                    'rules'            => array('type' => 'array',   'required' => false),
                    'include_defaults' => array('type' => 'boolean', 'required' => false),
                    'threshold'        => array('type' => 'integer', 'required' => false),
                    'compression'      => array('type' => 'string',  'required' => false),
                    'preview'          => array('type' => 'boolean', 'required' => false),
                ),
            ),
        ));

        register_rest_route($this->namespace(), '/files/chunk-exec', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'chunk_exec'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    'session_id'  => array('type' => 'string',  'required' => true),
                    'chunk_index' => array('type' => 'integer', 'required' => true),
                ),
            ),
        ));

        register_rest_route($this->namespace(), '/files/chunk-download', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'chunk_download'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    'session_id'  => array('type' => 'string',  'required' => true),
                    'chunk_index' => array('type' => 'integer', 'required' => true),
                    'delete'      => array('type' => 'boolean', 'required' => false),
                ),
            ),
        ));
    }

    // ── inventory ────────────────────────────────────────────────────────

    public function inventory(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $root        = rtrim(ABSPATH, '/');
        $rules       = (array) ($request->get_param('rules') ?: array());
        $with_def    = $request->get_param('include_defaults');
        $with_def    = ($with_def === null) ? true : (bool) $with_def;
        $threshold   = (int) ($request->get_param('threshold') ?: SAM_Backup_File_Chunker::DEFAULT_THRESHOLD);
        $compression = (string) ($request->get_param('compression') ?: SAM_Backup_Options::get('files_compression', 'store'));
        $preview     = (bool) $request->get_param('preview');

        $exclusions = SAM_Backup_Exclusions::from_rules($rules, $with_def);
        $inventory  = new SAM_Backup_Inventory($root, $exclusions);

        if ($preview) {
            $p = $inventory->preview();
            $p['ok'] = true;
            $p['preview'] = true;
            return new WP_REST_Response($p, 200);
        }

        $inv     = $inventory->build();
        $chunker = new SAM_Backup_File_Chunker($root, $threshold, $compression);
        $plan    = $chunker->plan($inv['files']);

        $session_id = (string) ($request->get_param('session_id') ?: ('sess_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false)));
        $files_dir  = SAM_Backup_Temp::session_dir($session_id) . '/files';
        if (!is_dir($files_dir)) {
            @mkdir($files_dir, 0700, true);
        }

        // Persist the plan so chunk-exec/-download need only session_id + chunk_index.
        $plan_doc = array(
            'session_id'            => $session_id,
            'root'                  => $root,
            'threshold'             => $threshold,
            'compression'           => $chunker->compression(),
            'exclusion_policy_hash' => $inv['exclusion_policy_hash'],
            'total_files'           => $inv['total_files'],
            'total_bytes'           => $inv['total_bytes'],
            'excluded_files'        => $inv['excluded_files'],
            'excluded_bytes'        => $inv['excluded_bytes'],
            'chunk_count'           => count($plan),
            'chunks'                => $plan,
        );
        file_put_contents($files_dir . '/plan.json', wp_json_encode_maybe($plan_doc));

        // Response omits per-chunk file lists (kept server-side) to stay light.
        $chunks_summary = array();
        $largest_chunk  = 0;
        foreach ($plan as $c) {
            $chunks_summary[] = array(
                'index'      => $c['index'],
                'file_count' => $c['file_count'],
                'size'       => $c['size'],
                'oversize'   => $c['oversize'],
            );
            if ($c['size'] > $largest_chunk) {
                $largest_chunk = $c['size'];
            }
        }

        SAM_Backup_Logger::info('files/inventory', array(
            'session' => $session_id,
            'files'   => $inv['total_files'],
            'bytes'   => $inv['total_bytes'],
            'excluded'=> $inv['excluded_files'],
            'chunks'  => count($plan),
        ));

        return new WP_REST_Response(array(
            'ok'                    => true,
            'session_id'            => $session_id,
            'root'                  => $root,
            'threshold'             => $threshold,
            'compression'           => $chunker->compression(),
            'exclusion_policy_hash' => $inv['exclusion_policy_hash'],
            'total_files'           => $inv['total_files'],
            'total_bytes'           => $inv['total_bytes'],
            'excluded_files'        => $inv['excluded_files'],
            'excluded_bytes'        => $inv['excluded_bytes'],
            'chunk_count'           => count($plan),
            // temp is bounded by the single largest chunk (pull-and-free), not the total.
            'expected_temp_peak'    => $largest_chunk,
            'chunks'                => $chunks_summary,
        ), 200);
    }

    // ── chunk-exec ───────────────────────────────────────────────────────

    public function chunk_exec(WP_REST_Request $request): WP_REST_Response {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $session_id  = (string) $request->get_param('session_id');
        $chunk_index = (int) $request->get_param('chunk_index');

        $doc = $this->load_plan($session_id);
        if ($doc === null) {
            return new WP_REST_Response(array('ok' => false, 'error' => array('code' => 'NOT_FOUND', 'message' => 'Plan not found or expired.')), 404);
        }
        if ($chunk_index < 0 || $chunk_index >= $doc['chunk_count']) {
            return new WP_REST_Response(array('ok' => false, 'error' => array('code' => 'INVALID_CHUNK', 'message' => 'chunk_index out of range.')), 400);
        }

        $files_dir = SAM_Backup_Temp::session_dir($session_id) . '/files';
        $chunker   = new SAM_Backup_File_Chunker($doc['root'], (int) $doc['threshold'], (string) $doc['compression']);

        try {
            $result = $chunker->exec_chunk($files_dir, $chunk_index, $doc['chunks'][$chunk_index]);
        } catch (\Throwable $e) {
            SAM_Backup_Logger::error('files/chunk-exec failed', array('session' => $session_id, 'chunk' => $chunk_index, 'error' => $e->getMessage()));
            return new WP_REST_Response(array('ok' => false, 'error' => array('code' => 'CHUNK_FAILED', 'message' => $e->getMessage())), 500);
        }

        return new WP_REST_Response(array(
            'ok'          => true,
            'session_id'  => $session_id,
            'chunk_index' => $chunk_index,
            'empty'       => $result['empty'],
            'skipped'     => $result['skipped'],
            'chunk_size'  => $result['size'],
            'sha256'      => $result['sha256'],
            'file_count'  => $result['file_count'],
            'files'       => $result['files'], // manifest fragment {p,s,sha256,chunk_index}
        ), 200);
    }

    // ── chunk-download (pull-and-free) ───────────────────────────────────

    public function chunk_download(WP_REST_Request $request) {
        $session_id   = (string) $request->get_param('session_id');
        $chunk_index  = (int) $request->get_param('chunk_index');
        $delete_after = (bool) $request->get_param('delete');

        $doc = $this->load_plan($session_id);
        if ($doc === null) {
            return new WP_REST_Response(array('ok' => false, 'error' => array('code' => 'NOT_FOUND', 'message' => 'Plan not found.')), 404);
        }

        $files_dir   = SAM_Backup_Temp::session_dir($session_id) . '/files';
        $done_marker = $files_dir . '/chunk_' . $chunk_index . '.done';
        $zip_file    = $files_dir . '/chunk_' . $chunk_index . '.zip';

        if (!file_exists($done_marker)) {
            return new WP_REST_Response(array('ok' => false, 'error' => array('code' => 'NOT_READY', 'message' => "Chunk {$chunk_index} not executed.")), 400);
        }
        // Empty chunk → nothing to pull (contract: never stored, never manifested).
        if (!file_exists($zip_file)) {
            return new WP_REST_Response(array('ok' => false, 'error' => array('code' => 'EMPTY_CHUNK', 'message' => "Chunk {$chunk_index} is empty; nothing to download.")), 409);
        }

        $size = filesize($zip_file);

        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Length: ' . $size);
        header('X-SAM-Chunk-Index: ' . $chunk_index);
        header('X-SAM-Chunk-Sha256: ' . (string) hash_file('sha256', $zip_file));

        $fh = fopen($zip_file, 'rb');
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
            @unlink($zip_file); // free session temp; keep the tiny manifest fragment + .done
        }
        exit;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function load_plan(string $session_id): ?array {
        if ($session_id === '') {
            return null;
        }
        $plan_file = SAM_Backup_Temp::session_dir($session_id) . '/files/plan.json';
        if (!file_exists($plan_file)) {
            return null;
        }
        $doc = json_decode((string) file_get_contents($plan_file), true);
        return is_array($doc) ? $doc : null;
    }
}
