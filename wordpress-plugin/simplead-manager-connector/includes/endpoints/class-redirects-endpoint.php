<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirect management. The manager stores a set of source→target rules here;
 * the plugin performs them on template_redirect (see the handler in the main
 * plugin file). Rules are exact-path matches so nothing unexpected is caught.
 */
class SAM_Redirects_Endpoint extends SAM_Endpoint_Base {

    const OPTION = 'sam_redirects';

    const MAX_RULES = 1000;

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/redirects', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_redirects'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/redirects', [
            'methods'             => 'POST',
            'callback'            => [$this, 'set_redirects'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function list_redirects(WP_REST_Request $request): WP_REST_Response {
        return $this->success(['redirects' => array_values(get_option(self::OPTION, []))]);
    }

    /**
     * Replace the full redirect set. Payload: { redirects: [{source, target, code}] }.
     */
    public function set_redirects(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $incoming = is_array($params['redirects'] ?? null) ? $params['redirects'] : [];

        $clean = [];
        foreach ($incoming as $rule) {
            $source = isset($rule['source']) ? $this->normalize_path((string) $rule['source']) : '';
            $target = isset($rule['target']) ? esc_url_raw((string) $rule['target']) : '';
            $code   = isset($rule['code']) ? (int) $rule['code'] : 301;

            if ($source === '' || $target === '' || ! in_array($code, [301, 302], true)) {
                continue;
            }

            $clean[$source] = ['source' => $source, 'target' => $target, 'code' => $code];

            if (count($clean) >= self::MAX_RULES) {
                break;
            }
        }

        update_option(self::OPTION, $clean, false);

        return $this->success(['count' => count($clean)]);
    }

    /**
     * Normalise a path for exact matching: leading slash, no query, no trailing
     * slash (except root).
     */
    public static function normalize_path(string $path): string {
        $path = (string) parse_url($path, PHP_URL_PATH);
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
