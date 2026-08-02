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

        register_rest_route($this->namespace(), '/restore/apply-execute', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'apply_execute'),
                // Same HMAC as everything else: the loopback signs with the site's own credentials,
                // so this is not a back door — it is the plugin calling itself with the same proof
                // of identity the manager uses.
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    'token' => array('type' => 'string', 'required' => true),
                ),
            ),
        ));

        register_rest_route($this->namespace(), '/restore/apply', array(
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'apply'),
                'permission_callback' => array($this, 'check_permission'),
                'args'                => array(
                    'token' => array('type' => 'string', 'required' => true),
                    'async' => array('type' => 'boolean', 'required' => false, 'default' => false),
                ),
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

        // Collect what earlier restores left behind BEFORE staging a new one, so the space is
        // reclaimed exactly when it is about to be needed.
        //
        // There is an hourly cron for this, and on this host it is worth nothing: DISABLE_WP_CRON
        // is set, so WordPress only runs scheduled work when the hosting's own cron remembers to
        // ask it to. Tying the cleanup of a client's disk to a hook we cannot see, on a schedule we
        // do not control, is not a guarantee. This is: whatever else is true, a restore tidies up
        // the last one before it adds to the pile.
        $this->sweep_leftovers();

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

    /**
     * Apply the staged restore — in the background when the host allows it.
     *
     * Applying a real site takes minutes. Held open as one HTTP request it outlives the client's
     * patience long before it outlives the work: the manager times out, concludes the restore
     * failed, and calls rollback — while this process carries on and completes the swap. The site
     * ends up correctly restored and recorded as failed, with the old copy left on disk because
     * nobody ever reached commit. That is not a hypothetical; it is what the first real attempt did.
     *
     * So with `async` the work is detached — loopback first, wp-cron second — and the manager polls
     * restore/status instead of holding a socket. If neither is available the response says so
     * (`async: false`) and the manager falls back to the synchronous path, which is still the right
     * answer for a small site.
     */
    public function apply(WP_REST_Request $request): WP_REST_Response {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $token = (string) $request->get_param('token');

        if ((bool) $request->get_param('async')) {
            return $this->apply_async($token);
        }

        try {
            $result = $this->engine($token)->apply();
        } catch (\Throwable $e) {
            SAM_Backup_Logger::error('restore/apply failed', array('token' => $token, 'error' => $e->getMessage()));
            return $this->error('APPLY_FAILED', $e->getMessage(), 500);
        }
        SAM_Backup_Logger::info('restore/apply done', array('token' => $token));
        return new WP_REST_Response($result, 200);
    }

    /**
     * The detached half of apply(): kick the work off and return immediately.
     */
    private function apply_async(string $token): WP_REST_Response {
        $engine = $this->engine($token);

        // Never start a second one. apply() refuses re-entry too, but answering here means the
        // manager gets a clear reply instead of a 500 from deep inside the engine — and, crucially,
        // that mark_apply_queued() below does not overwrite a state that already means something.
        //
        // Both branches matter for a redelivered job: it may arrive while the first apply is still
        // running, or after it finished. Saying `async: true` in either case sends the manager to
        // restore/status, which is where the real answer is.
        $state = (string) ($engine->status()['state'] ?? '');

        if ($state === 'applying') {
            return new WP_REST_Response(
                array('ok' => true, 'async' => true, 'token' => $token, 'already_running' => true),
                200
            );
        }

        if ($state === 'applied') {
            return new WP_REST_Response(
                array('ok' => true, 'async' => true, 'token' => $token, 'already_applied' => true),
                200
            );
        }

        // Claimed BEFORE dispatching, so a poll that lands between the kick and the worker starting
        // sees `applying` rather than the previous state — which the manager would read as "nothing
        // happened" and retry.
        $engine->mark_apply_queued();

        if ($this->dispatch_apply_loopback($token)) {
            SAM_Backup_Logger::info('restore/apply dispatched', array('token' => $token, 'method' => 'loopback'));
            return new WP_REST_Response(
                array('ok' => true, 'async' => true, 'token' => $token, 'method' => 'loopback'),
                200
            );
        }

        $hook = 'sam_backup_restore_apply';
        if (!wp_next_scheduled($hook, array($token))) {
            wp_schedule_single_event(time(), $hook, array($token));
            spawn_cron();
        }
        if (wp_next_scheduled($hook, array($token))) {
            SAM_Backup_Logger::info('restore/apply dispatched', array('token' => $token, 'method' => 'cron'));
            return new WP_REST_Response(
                array('ok' => true, 'async' => true, 'token' => $token, 'method' => 'cron'),
                200
            );
        }

        // Neither route works on this host. Put the state back so the synchronous retry is not
        // refused by apply()'s own re-entry guard, and say plainly that async is unavailable.
        $engine->clear_apply_queued();
        SAM_Backup_Logger::warning('restore/apply cannot detach on this host', array('token' => $token));

        return new WP_REST_Response(
            array('ok' => true, 'async' => false, 'token' => $token, 'reason' => 'no loopback or cron available'),
            200
        );
    }

    /**
     * Internal: run the apply this request was detached into. Not called by the manager.
     */
    public function apply_execute(WP_REST_Request $request): WP_REST_Response {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $token = (string) $request->get_param('token');

        try {
            $result = $this->engine($token)->apply(true); // this IS the detached request
        } catch (\Throwable $e) {
            // The status file already carries `failed` with the reason; nobody is waiting on this
            // response, so it exists only for the log.
            SAM_Backup_Logger::error('restore/apply-execute failed', array('token' => $token, 'error' => $e->getMessage()));
            return $this->error('APPLY_FAILED', $e->getMessage(), 500);
        }

        SAM_Backup_Logger::info('restore/apply-execute done', array('token' => $token));
        return new WP_REST_Response($result, 200);
    }

    /**
     * Fire-and-forget POST back to ourselves, signed the way every other call is.
     */
    private function dispatch_apply_loopback(string $token): bool {
        $body = wp_json_encode(array('token' => $token));
        $timestamp = (string) time();
        $nonce = wp_generate_password(32, false);
        $route = '/' . $this->namespace() . '/restore/apply-execute';
        $secret = (string) get_option('sam_api_secret', '');
        $signature = hash_hmac('sha256', implode('|', array('POST', $route, $timestamp, $nonce, $body)), $secret);

        $result = wp_remote_post(rest_url($this->namespace() . '/restore/apply-execute'), array(
            'method'    => 'POST',
            'timeout'   => 1,
            'blocking'  => false,
            'headers'   => array(
                'Content-Type'            => 'application/json',
                'X-SAM-Backup-Key'        => (string) get_option('sam_api_key', ''),
                'X-SAM-Backup-Timestamp'  => $timestamp,
                'X-SAM-Backup-Nonce'      => $nonce,
                'X-SAM-Backup-Signature'  => $signature,
            ),
            'body'      => $body,
            'sslverify' => false,
        ));

        return !is_wp_error($result);
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

    /**
     * Best effort, and deliberately so: a restore must not be refused because tidying up failed.
     * The sweep only takes directories older than an hour, so a restore running in parallel — which
     * would be unusual, but is not impossible — is never touched.
     */
    private function sweep_leftovers(): void {
        try {
            SAM_Backup_Plugin::instance()->sweep_restore_leftovers();
        } catch (\Throwable $e) {
            SAM_Backup_Logger::warn('leftover sweep failed', array('error' => $e->getMessage()));
        }
    }

    private function error(string $code, string $message, int $status): WP_REST_Response {
        return new WP_REST_Response(array('ok' => false, 'error' => array('code' => $code, 'message' => $message)), $status);
    }
}
