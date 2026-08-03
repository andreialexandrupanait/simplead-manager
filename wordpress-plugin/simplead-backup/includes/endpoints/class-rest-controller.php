<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base controller for simplead-backup/v1 endpoints. Centralises the mandatory-nonce HMAC
 * permission callback so every endpoint is authenticated identically.
 */
abstract class SAM_Backup_REST_Controller {

    abstract public function register_routes(): void;

    /**
     * Permission callback: full HMAC + mandatory-nonce validation. Nothing runs without it.
     *
     * @return true|WP_Error
     */
    public function check_permission(WP_REST_Request $request) {
        return SAM_Backup_Auth::validate($request);
    }

    protected function namespace(): string {
        return SAM_BACKUP_REST_NAMESPACE;
    }

    /**
     * The backup session id, under whichever key the manager sent it.
     *
     * `run_id` is the wire name since 0.8.2: at least one host's mod_security rejects any
     * request body carrying a parameter literally named `session_id` (anti-session-fixation
     * rule — the value does not matter, the key alone draws a 403 before WordPress boots).
     * `session_id` stays accepted so managers older than the rename keep working.
     */
    protected function session_id_from(WP_REST_Request $request): string {
        return (string) ($request->get_param('run_id') ?: $request->get_param('session_id') ?: '');
    }
}
