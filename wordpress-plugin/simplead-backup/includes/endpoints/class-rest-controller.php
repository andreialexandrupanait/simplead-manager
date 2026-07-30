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
}
