<?php
/**
 * Two-factor authentication (email code) for interactive WordPress logins.
 *
 * Reads settings from sam_security_login option, key two_factor_auth:
 *   enabled   bool
 *   roles     string[]  roles that must verify (default administrator+editor)
 *   fail_mode 'open'|'closed'  what to do when the code email cannot be sent
 *             (default open — a broken SMTP must not lock clients out)
 *
 * Flow: password auth succeeds (authenticate prio 50, after brute-force at 30)
 * → 6-digit code emailed, hashed copy stored in user meta (10 min, 5 attempts)
 * → login interrupted with a redirect to wp-login.php?action=sam-2fa
 * → correct code sets the auth cookie; optional 30-day trusted-device cookie.
 *
 * Application Password / REST / XML-RPC authentications are exempt — 2FA
 * covers interactive logins only.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Security_Two_Factor {

    private const CODE_TTL = 600;        // seconds a code stays valid
    private const MAX_ATTEMPTS = 5;      // wrong codes before the challenge is void
    private const MAX_RESENDS = 3;
    private const TRUST_TTL = 30 * DAY_IN_SECONDS;

    /** @var array */
    private $settings;

    public function __construct() {
        $login_settings = get_option('sam_security_login', []);
        $this->settings = $login_settings['two_factor_auth'] ?? [];

        if (empty($this->settings['enabled'])) {
            return;
        }

        add_filter('authenticate', [$this, 'maybe_require_code'], 50, 1);
        add_action('login_form_sam-2fa', [$this, 'handle_challenge_page']);
    }

    /**
     * After a successful password check, interrupt the login for targeted
     * roles and send the verification code.
     *
     * @param WP_User|WP_Error|null $user
     * @return WP_User|WP_Error|null
     */
    public function maybe_require_code($user) {
        if (!($user instanceof WP_User)) {
            return $user;
        }

        // Non-interactive authentication is out of scope.
        if ((defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())) {
            return $user;
        }

        $roles = $this->settings['roles'] ?? ['administrator', 'editor'];
        if (empty(array_intersect($user->roles, (array) $roles))) {
            return $user;
        }

        if ($this->is_trusted_device($user)) {
            return $user;
        }

        if (!$this->send_code($user)) {
            $fail_mode = $this->settings['fail_mode'] ?? 'open';
            SAM_Audit_Logger::log('2fa_mail_failure', 'security', $user->user_login,
                'Verification email failed — fail-' . $fail_mode);

            if ($fail_mode === 'closed') {
                return new WP_Error('sam_2fa_mail_failed',
                    __('Could not send the verification code. Please contact the site administrator.'));
            }

            return $user; // fail-open: broken SMTP must not lock clients out
        }

        $session = $this->start_session($user);
        SAM_Audit_Logger::log('2fa_challenge_sent', 'security', $user->user_login, 'Verification code emailed');

        $args = [
            'action' => 'sam-2fa',
            'uid' => $user->ID,
            'token' => $session,
            'redirect_to' => rawurlencode($_REQUEST['redirect_to'] ?? admin_url()),
        ];
        if (!empty($_POST['rememberme'])) {
            $args['rememberme'] = '1';
        }

        wp_safe_redirect(add_query_arg($args, wp_login_url()));
        exit;
    }

    /**
     * Renders and processes the code form (runs inside wp-login.php, so
     * login_header()/login_footer() are available).
     */
    public function handle_challenge_page(): void {
        $uid = (int) ($_REQUEST['uid'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_REQUEST['token'] ?? ''));
        $user = $uid ? get_user_by('id', $uid) : false;

        if (!$user || !$this->session_valid($user, $token)) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $error = '';

        if (!empty($_REQUEST['resend'])) {
            $error = $this->handle_resend($user)
                ? __('A new code has been sent.')
                : __('Could not resend the code — please restart the login.');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sam_2fa_code'])) {
            $result = $this->verify_code($user, sanitize_text_field(wp_unslash($_POST['sam_2fa_code'])));

            if ($result === true) {
                $this->clear_challenge($user);

                if (!empty($_POST['sam_2fa_trust'])) {
                    $this->trust_device($user);
                }

                wp_set_auth_cookie($user->ID, !empty($_REQUEST['rememberme']));
                SAM_Audit_Logger::log('2fa_verified', 'security', $user->user_login, 'Login verified via email code');

                $redirect = wp_validate_redirect(rawurldecode($_REQUEST['redirect_to'] ?? ''), admin_url());
                wp_safe_redirect($redirect);
                exit;
            }

            if ($result === 'locked') {
                $this->clear_challenge($user);
                SAM_Audit_Logger::log('2fa_locked', 'security', $user->user_login, 'Too many wrong codes');
                wp_safe_redirect(add_query_arg('sam_2fa', 'locked', wp_login_url()));
                exit;
            }

            $error = __('Incorrect or expired code. Please try again.');
            SAM_Audit_Logger::log('2fa_failed', 'security', $user->user_login, 'Wrong verification code');
        }

        $this->render_form($user, $token, $error);
        exit;
    }

    // ── Challenge internals ─────────────────────────────────────────────────

    private function send_code(WP_User $user): bool {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        update_user_meta($user->ID, '_sam_2fa_code', [
            'hash' => wp_hash($code),
            'expires' => time() + self::CODE_TTL,
            'attempts' => 0,
        ]);

        $blog = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

        return wp_mail(
            $user->user_email,
            sprintf(__('[%s] Your login verification code'), $blog),
            sprintf(
                __("Your verification code is: %1\$s\n\nIt expires in 10 minutes. If you did not try to log in to %2\$s, please change your password."),
                $code,
                home_url()
            )
        );
    }

    /** @return true|false|'locked' */
    private function verify_code(WP_User $user, string $submitted) {
        $challenge = get_user_meta($user->ID, '_sam_2fa_code', true);

        if (!is_array($challenge) || time() > ($challenge['expires'] ?? 0)) {
            return false;
        }

        $challenge['attempts'] = ($challenge['attempts'] ?? 0) + 1;
        update_user_meta($user->ID, '_sam_2fa_code', $challenge);

        if ($challenge['attempts'] > self::MAX_ATTEMPTS) {
            return 'locked';
        }

        return hash_equals($challenge['hash'], wp_hash($submitted));
    }

    private function start_session(WP_User $user): string {
        $token = wp_generate_password(32, false);

        update_user_meta($user->ID, '_sam_2fa_session', [
            'hash' => wp_hash($token),
            'expires' => time() + self::CODE_TTL,
            'resends' => 0,
        ]);

        return $token;
    }

    private function session_valid(WP_User $user, string $token): bool {
        $session = get_user_meta($user->ID, '_sam_2fa_session', true);

        return is_array($session)
            && time() <= ($session['expires'] ?? 0)
            && hash_equals($session['hash'] ?? '', wp_hash($token));
    }

    private function handle_resend(WP_User $user): bool {
        $session = get_user_meta($user->ID, '_sam_2fa_session', true);
        if (!is_array($session) || ($session['resends'] ?? 0) >= self::MAX_RESENDS) {
            return false;
        }

        $session['resends'] = ($session['resends'] ?? 0) + 1;
        update_user_meta($user->ID, '_sam_2fa_session', $session);

        return $this->send_code($user);
    }

    private function clear_challenge(WP_User $user): void {
        delete_user_meta($user->ID, '_sam_2fa_code');
        delete_user_meta($user->ID, '_sam_2fa_session');
    }

    // ── Trusted devices ─────────────────────────────────────────────────────

    private function cookie_name(WP_User $user): string {
        return 'sam_2fa_trust_' . COOKIEHASH . '_' . $user->ID;
    }

    private function is_trusted_device(WP_User $user): bool {
        $cookie = $_COOKIE[$this->cookie_name($user)] ?? '';
        if ($cookie === '') {
            return false;
        }

        $trusted = get_user_meta($user->ID, '_sam_2fa_trusted', true);
        if (!is_array($trusted)) {
            return false;
        }

        $hash = wp_hash($cookie);
        $expires = $trusted[$hash] ?? 0;

        return is_numeric($expires) && time() <= (int) $expires;
    }

    private function trust_device(WP_User $user): void {
        $token = wp_generate_password(32, false);
        $expires = time() + self::TRUST_TTL;

        $trusted = get_user_meta($user->ID, '_sam_2fa_trusted', true);
        $trusted = is_array($trusted) ? $trusted : [];

        // Prune expired entries while we're here.
        $trusted = array_filter($trusted, fn ($exp) => is_numeric($exp) && time() <= (int) $exp);
        $trusted[wp_hash($token)] = $expires;

        update_user_meta($user->ID, '_sam_2fa_trusted', $trusted);

        setcookie($this->cookie_name($user), $token, [
            'expires' => $expires,
            'path' => COOKIEPATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // ── Form rendering ──────────────────────────────────────────────────────

    private function render_form(WP_User $user, string $token, string $notice): void {
        login_header(__('Verification required'), '', null);

        $action_url = esc_url(add_query_arg([
            'action' => 'sam-2fa',
            'uid' => $user->ID,
            'token' => $token,
            'redirect_to' => rawurlencode(rawurldecode($_REQUEST['redirect_to'] ?? '')),
            'rememberme' => !empty($_REQUEST['rememberme']) ? '1' : '',
        ], wp_login_url()));

        $resend_url = esc_url(add_query_arg('resend', '1', $action_url));
        ?>
        <?php if ($notice !== '') : ?>
            <div id="login_error"><?php echo esc_html($notice); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo $action_url; ?>">
            <p style="margin-bottom:12px;">
                <?php echo esc_html(sprintf(__('A 6-digit code was sent to the email address of %s. Enter it below to finish logging in.'), $user->user_login)); ?>
            </p>
            <p>
                <label for="sam_2fa_code"><?php esc_html_e('Verification code'); ?></label>
                <input type="text" name="sam_2fa_code" id="sam_2fa_code" class="input" inputmode="numeric"
                       autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus />
            </p>
            <p style="margin:12px 0;">
                <label>
                    <input type="checkbox" name="sam_2fa_trust" value="1" />
                    <?php esc_html_e('Trust this device for 30 days'); ?>
                </label>
            </p>
            <p class="submit">
                <input type="submit" class="button button-primary button-large" value="<?php esc_attr_e('Verify'); ?>" />
            </p>
            <p style="margin-top:12px;">
                <a href="<?php echo $resend_url; ?>"><?php esc_html_e('Resend code'); ?></a>
            </p>
        </form>
        <?php
        login_footer('sam_2fa_code');
    }
}
