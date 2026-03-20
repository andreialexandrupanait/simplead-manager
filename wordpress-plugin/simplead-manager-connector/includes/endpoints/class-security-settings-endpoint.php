<?php
/**
 * REST endpoint for pushing security settings from Laravel.
 *
 * POST /simplead/v1/security-settings  — Apply hardening/htaccess/login/captcha settings
 * GET  /simplead/v1/security-settings  — Get current applied state
 * GET  /simplead/v1/security-state     — Full state sync (all settings + actual state)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Security_Settings_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/security-settings', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'apply_settings'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/security-state', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_full_state'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Apply security settings pushed from Laravel.
     */
    public function apply_settings(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $results = [];

        // Hardening settings (stored as options, enforced at runtime)
        if (isset($params['hardening'])) {
            try {
                $hardening_settings = [];
                foreach ($params['hardening'] as $key => $enabled) {
                    $hardening_settings[$key] = (bool) $enabled;
                }
                $cleanup_diag = SAM_Security_Hardening::update_settings($hardening_settings);
                $results['hardening'] = [
                    'success' => true,
                    'applied' => array_keys(array_filter($hardening_settings)),
                ];
                if (!empty($cleanup_diag)) {
                    $results['hardening']['server_changes'] = $cleanup_diag;
                }
            } catch (\Throwable $e) {
                $results['hardening'] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // Htaccess settings (written to .htaccess file)
        if (isset($params['htaccess'])) {
            try {
                $htaccess = new SAM_Security_Htaccess();
                $htaccess_results = $htaccess->apply_settings($params['htaccess']);
                $results['htaccess'] = $htaccess_results;
            } catch (\Throwable $e) {
                $results['htaccess'] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // Login settings
        if (isset($params['login'])) {
            try {
                SAM_Security_Login::update_settings($params['login']);
                $results['login'] = ['success' => true];
            } catch (\Throwable $e) {
                $results['login'] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // Captcha settings
        if (isset($params['captcha'])) {
            try {
                update_option('sam_security_captcha', $params['captcha']);
                $results['captcha'] = ['success' => true];
            } catch (\Throwable $e) {
                $results['captcha'] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // IP management settings
        if (isset($params['ip_management'])) {
            try {
                update_option('sam_security_ip_management', $params['ip_management']);
                $results['ip_management'] = ['success' => true];
            } catch (\Throwable $e) {
                $results['ip_management'] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        SAM_Audit_Logger::log('security_settings_applied', 'security', 'settings', 'Security settings pushed from dashboard');

        return $this->success([
            'results' => $results,
            'banned_ips' => SAM_Security_Login::get_banned_ips(),
            'timestamp' => gmdate('c'),
        ]);
    }

    /**
     * Get current security settings.
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        return $this->success([
            'hardening' => get_option('sam_security_settings', []),
            'login' => get_option('sam_security_login', []),
            'captcha' => get_option('sam_security_captcha', []),
            'ip_management' => get_option('sam_security_ip_management', []),
        ]);
    }

    /**
     * Full state sync — returns configured settings AND actual enforced state.
     */
    public function get_full_state(WP_REST_Request $request): WP_REST_Response {
        $htaccess = new SAM_Security_Htaccess();

        return $this->success([
            'hardening' => [
                'settings' => get_option('sam_security_settings', []),
                'state' => SAM_Security_Hardening::get_verified_state(),
            ],
            'htaccess' => [
                'active_sections' => $htaccess->get_active_sections(),
            ],
            'login' => [
                'settings' => SAM_Security_Login::get_settings(),
                'banned_ips' => SAM_Security_Login::get_banned_ips(),
            ],
            'captcha' => get_option('sam_security_captcha', []),
            'ip_management' => get_option('sam_security_ip_management', []),
            'timestamp' => gmdate('c'),
        ]);
    }
}
