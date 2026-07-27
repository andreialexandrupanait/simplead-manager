<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Staged, atomic RESTORE endpoints for simplead-backup/v1 (the read side of the engine).
 *
 *   POST simplead-backup/v1/restore/prepare       — open a restore session (token) + staging.
 *   POST simplead-backup/v1/restore/stage-chunk    — receive ONE materialised chunk (raw body =
 *                                                     zip / sql.gz; token,kind,seq,sha256 in query).
 *                                                     Idempotent, resumable; nothing touches live.
 *   POST simplead-backup/v1/restore/apply          — CRITICAL window (maintenance ON only here):
 *                                                     DB import→atomic RENAME swap + journaled file
 *                                                     swap, keeping rollback data.
 *   POST simplead-backup/v1/restore/commit         — restore confirmed good → drop old + delete trash.
 *   POST simplead-backup/v1/restore/rollback       — return the site to its pre-apply state.
 *   POST simplead-backup/v1/restore/status         — progress / state.
 *
 * Pull model: the MANAGER downloads objects from S3 (materialising latest-wins + tombstones) and
 * PUSHES each chunk here. The plugin never reaches out to S3. Every route is HMAC+nonce authed by
 * the shared SAM_Backup_REST_Controller permission callback.
 */
final class SAM_Backup_Restore_Endpoint extends SAM_Backup_REST_Controller {

    public function register_routes(): void {
        register_rest_route($this->namespace(), '/restore/prepare', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'prepare'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    'token'        => array('type' => 'string', 'required' => true),
                    'mode'         => array('type' => 'string', 'required' => false),
                    'scope'        => array('type' => 'object', 'required' => false),
                    'mirror_roots' => array('type' => 'array',  'required' => false),
                    'keep_paths'   => array('type' => 'array',  'required' => false),
                    'db_tables'    => array('type' => 'array',  'required' => false),
                    'tombstones'   => array('type' => 'array',  'required' => false),
                ),
            ),
        ));

        register_rest_route($this->namespace(), '/restore/stage-chunk', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'stage_chunk'),
                'permission_callback' => array($this, 'check_permission'),
                // token/kind/seq/sha256 travel as QUERY params; the raw body is the chunk bytes
                // (so the HMAC signs the chunk itself: METHOD|ROUTE|TS|NONCE|BODY).
            ),
        ));

        register_rest_route($this->namespace(), '/restore/apply', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'apply'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array('token' => array('type' => 'string', 'required' => true)),
            ),
        ));

        register_rest_route($this->namespace(), '/restore/commit', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'commit'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array('token' => array('type' => 'string', 'required' => true)),
            ),
        ));

        register_rest_route($this->namespace(), '/restore/rollback', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rollback'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array('token' => array('type' => 'string', 'required' => true)),
            ),
        ));

        register_rest_route($this->namespace(), '/restore/status', array(
            array(
                'methods'             => array('GET', 'POST'),
                'callback'            => array($this, 'status'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array('token' => array('type' => 'string', 'required' => true)),
            ),
        ));
    }

    public function prepare(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(120);
        $token = (string) $request->get_param('token');
        try {
            $engine = $this->engine($token);
            $result = $engine->prepare(array(
                'mode'         => (string) ($request->get_param('mode') ?: SAM_Backup_Restore_Engine::MODE_SAFE_MERGE),
                'scope'        => (array) ($request->get_param('scope') ?: array()),
                'mirror_roots' => (array) ($request->get_param('mirror_roots') ?: array()),
                'keep_paths'   => (array) ($request->get_param('keep_paths') ?: array()),
                'db_tables'    => (array) ($request->get_param('db_tables') ?: array()),
                'tombstones'   => (array) ($request->get_param('tombstones') ?: array()),
            ));
        } catch (\Throwable $e) {
            return $this->error('PREPARE_FAILED', $e->getMessage(), 500);
        }
        SAM_Backup_Logger::info('restore/prepare', array('token' => $token, 'mode' => $result['mode']));
        return new WP_REST_Response($result, 200);
    }

    public function stage_chunk(WP_REST_Request $request): WP_REST_Response {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $token  = (string) $request->get_param('token');
        $kind   = (string) $request->get_param('kind');
        $seq    = (int) $request->get_param('seq');
        $sha256 = (string) $request->get_param('sha256');

        if ($token === '' || ($kind !== 'files' && $kind !== 'database') || $sha256 === '') {
            return $this->error('BAD_REQUEST', 'token, kind (files|database) and sha256 are required.', 400);
        }

        $body = $request->get_body();
        if ($body === '' || $body === null) {
            return $this->error('EMPTY_CHUNK', 'No chunk body received.', 400);
        }

        $tmp = SAM_Backup_Temp::ensure('sessions/restore_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $token))
            . '/incoming_' . $kind . '_' . $seq . '.part';
        if (@file_put_contents($tmp, $body) === false) {
            return $this->error('WRITE_FAILED', 'Failed to buffer incoming chunk.', 500);
        }

        try {
            $engine = $this->engine($token);
            $result = ($kind === 'files')
                ? $engine->stage_files_chunk($seq, $tmp, $sha256)
                : $engine->stage_db_chunk($seq, $tmp, $sha256);
        } catch (\Throwable $e) {
            @unlink($tmp);
            return $this->error('STAGE_FAILED', $e->getMessage(), 422);
        }
        @unlink($tmp);

        return new WP_REST_Response($result, 200);
    }

    public function apply(WP_REST_Request $request): WP_REST_Response {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $token = (string) $request->get_param('token');
        try {
            $result = $this->engine($token)->apply();
        } catch (\Throwable $e) {
            SAM_Backup_Logger::error('restore/apply failed', array('token' => $token, 'error' => $e->getMessage()));
            return $this->error('APPLY_FAILED', $e->getMessage(), 500);
        }
        SAM_Backup_Logger::info('restore/apply done', array('token' => $token));
        return new WP_REST_Response($result, 200);
    }

    public function commit(WP_REST_Request $request): WP_REST_Response {
        $token = (string) $request->get_param('token');
        try {
            $result = $this->engine($token)->commit();
        } catch (\Throwable $e) {
            return $this->error('COMMIT_FAILED', $e->getMessage(), 500);
        }
        return new WP_REST_Response($result, 200);
    }

    public function rollback(WP_REST_Request $request): WP_REST_Response {
        ignore_user_abort(true);
        @set_time_limit(0);
        $token = (string) $request->get_param('token');
        try {
            $result = $this->engine($token)->rollback();
        } catch (\Throwable $e) {
            SAM_Backup_Logger::error('restore/rollback failed', array('token' => $token, 'error' => $e->getMessage()));
            return $this->error('ROLLBACK_FAILED', $e->getMessage(), 500);
        }
        SAM_Backup_Logger::info('restore/rollback done', array('token' => $token));
        return new WP_REST_Response($result, 200);
    }

    public function status(WP_REST_Request $request): WP_REST_Response {
        $token = (string) $request->get_param('token');
        return new WP_REST_Response($this->engine($token)->status(), 200);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function engine(string $token): SAM_Backup_Restore_Engine {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $token);
        $work_dir = SAM_Backup_Temp::session_dir('restore_' . $safe);
        return new SAM_Backup_Restore_Engine($token, rtrim(ABSPATH, '/'), $work_dir, null);
    }

    private function error(string $code, string $message, int $status): WP_REST_Response {
        return new WP_REST_Response(array('ok' => false, 'error' => array('code' => $code, 'message' => $message)), $status);
    }
}
