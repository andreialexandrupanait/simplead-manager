<?php

declare(strict_types=1);

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

/**
 * SAM_Backup_Loopback
 * ===================
 * Can this host detach work to itself, and can wp-cron actually run?
 *
 * Both questions used to be answered with a constant `true` in the capabilities
 * endpoint, and with return values that cannot fail in the dispatcher: a
 * non-blocking POST reports success for a request the site rejects, and
 * wp_next_scheduled() reports an event that nothing will ever run. A restore was
 * therefore reported as queued on hosts where it never started, and — because the
 * claim it left behind blocked both the synchronous retry and the rollback — the
 * session could only be freed by deleting it.
 *
 * The probe here is a real signed request to our own REST namespace: it proves
 * the route resolves AND that our credentials are accepted, which is the pair
 * that actually has to hold for the detached apply to run.
 */
final class SAM_Backup_Loopback {

    const CACHE_KEY = 'sam_backup_loopback_ok';
    const CACHE_TTL = 21600; // 6h — long enough that prepare() does not pay for it

    /**
     * Header the probe request carries so the endpoint answering it knows not to
     * probe in turn. The probe targets /capabilities, and /capabilities reports
     * whether the loopback works — without this marker each probe would start
     * another one, forever.
     */
    const PROBE_HEADER = 'X-SAM-Backup-Probe';

    /** Whether THIS request is the probe. */
    public static function is_probe_request(): bool {
        return !empty($_SERVER['HTTP_X_SAM_BACKUP_PROBE']);
    }

    /**
     * Whether a signed request to ourselves comes back answered.
     */
    public static function available(): bool {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached === 'yes') {
            return true;
        }
        if ($cached === 'no') {
            return false;
        }

        $ok = self::probe();
        set_transient(self::CACHE_KEY, $ok ? 'yes' : 'no', self::CACHE_TTL);

        return $ok;
    }

    /**
     * Drop the cached verdict, so the next caller probes again. Called when a
     * dispatch fails despite the probe having said yes.
     */
    public static function forget(): void {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Whether wp-cron is in a position to run anything at all.
     *
     * DISABLE_WP_CRON means WordPress only runs scheduled work when the hosting's
     * own cron asks it to. We cannot see that from here, so this answers the half
     * we can see — and answering it is still the difference between "queued" and
     * an honest "this host cannot detach".
     */
    public static function cron_runs(): bool {
        return !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
    }

    private static function probe(): bool {
        $route = '/' . SAM_BACKUP_REST_NAMESPACE . '/capabilities';
        $timestamp = (string) time();
        $nonce = wp_generate_password(32, false);

        // GET, so the signed body is the empty string, exactly as SAM_Backup_Auth
        // reconstructs it on the other side.
        $signature = hash_hmac(
            'sha256',
            implode('|', array('GET', $route, $timestamp, $nonce, '')),
            SAM_Backup_Options::api_secret()
        );

        $response = wp_remote_get(rest_url(SAM_BACKUP_REST_NAMESPACE . '/capabilities'), array(
            'timeout'   => 5,
            'blocking'  => true, // the whole point: a verdict, not a hope
            'sslverify' => false,
            'headers'   => array(
                self::PROBE_HEADER       => '1',
                'X-SAM-Backup-Key'       => SAM_Backup_Options::api_key(),
                'X-SAM-Backup-Timestamp' => $timestamp,
                'X-SAM-Backup-Nonce'     => $nonce,
                'X-SAM-Backup-Signature' => $signature,
            ),
        ));

        if (is_wp_error($response)) {
            SAM_Backup_Logger::warn('loopback probe failed', array('error' => $response->get_error_message()));
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            SAM_Backup_Logger::warn('loopback probe rejected', array('status' => $code));
            return false;
        }

        return true;
    }
}
