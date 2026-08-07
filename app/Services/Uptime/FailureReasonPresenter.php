<?php

declare(strict_types=1);

namespace App\Services\Uptime;

use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the terse `failure_reason` strings CheckUptime stores into the two
 * things a person reading an alert at 3am actually needs: the clause that
 * follows "…went down on <date> and ", and a short list of what to check.
 *
 * The raw string is never discarded: anything this class does not recognise is
 * quoted verbatim, so a novel failure still reaches the reader intact rather
 * than being flattened into "an unknown error".
 */
class FailureReasonPresenter
{
    /**
     * Matches the " (confirmed from FRA)" suffix CheckUptime::handleFailure()
     * appends when the second location corroborated the failure.
     */
    private const CONFIRMATION_PATTERN = '/\s*\(confirmed from ([^)]+)\)\s*$/i';

    /**
     * The clause that follows "and", e.g. "is returning HTTP 502 Bad Gateway".
     */
    public static function clause(?string $cause, ?int $timeoutSeconds = null): string
    {
        [$kind, $detail] = self::classify($cause);

        return match ($kind) {
            'empty' => __('is not responding'),
            'http' => self::httpClause((int) $detail),
            'keyword_missing' => __('loaded without the keyword we monitor'),
            'keyword_unwanted' => __('is showing the keyword we watch out for'),
            'tcp_port' => __('is refusing connections on port :port', ['port' => $detail]),
            'timeout' => $timeoutSeconds !== null && $timeoutSeconds > 0
                ? __('did not respond within :seconds seconds', ['seconds' => $timeoutSeconds])
                : __('did not respond in time'),
            'dns' => __('could not be resolved in DNS'),
            'refused' => __('is refusing connections'),
            'reset' => __('is resetting the connection'),
            'tls_certificate' => __('is presenting an invalid TLS certificate'),
            'tls' => __('is failing the TLS handshake'),
            default => __('is returning ":error"', ['error' => $detail]),
        };
    }

    /**
     * What to check, worst-first, tailored to the failure. Two or three items —
     * a longer list reads as boilerplate and gets skipped, which defeats the
     * point of putting it in the alert at all.
     *
     * @return list<string>
     */
    public static function remedies(?string $cause, ?int $timeoutSeconds = null): array
    {
        [$kind, $detail] = self::classify($cause);

        return match ($kind) {
            'http' => self::httpRemedies((int) $detail),
            'keyword_missing', 'keyword_unwanted' => [
                __('The server answered normally, so this is a content change rather than an outage — open the page and look for the text the monitor watches.'),
                __('If the page was edited on purpose, update the keyword in the monitor settings so it stops alerting.'),
            ],
            'tcp_port', 'refused' => [
                __('The web server is not accepting connections at all — usually nginx or Apache has stopped. Ask your host to restart it.'),
                __('If the server is running, a firewall rule is dropping the connection. Check whether the port was closed recently.'),
            ],
            'timeout' => [
                $timeoutSeconds !== null && $timeoutSeconds > 0
                    ? __('The site is answering too slowly to finish within :seconds seconds — look for a slow database query, a stuck cron job, or a spike in traffic.', ['seconds' => $timeoutSeconds])
                    : __('The site is answering too slowly — look for a slow database query, a stuck cron job, or a spike in traffic.'),
                __('Check the server load and PHP worker count with your host; a saturated pool times out long before it errors.'),
            ],
            'dns' => [
                __('Check that the domain has not expired and that its nameservers still point where they should.'),
                __('If you changed DNS recently, the record may not have propagated yet — this usually clears on its own within a few hours.'),
            ],
            'reset' => [
                __('The connection was cut mid-response, which usually means the server ran out of memory or the process was killed.'),
                __('Check the PHP error log for a fatal error, and ask your host whether the process hit a memory or CPU limit.'),
            ],
            'tls_certificate', 'tls' => [
                __('Renew the TLS certificate. If it is issued by Let\'s Encrypt, the automatic renewal has most likely failed.'),
                __('Browsers will be showing a security warning to your visitors until this is fixed, so treat it as urgent.'),
            ],
            default => [
                __('Open the site in a private browser window — if it loads for you, the problem may be intermittent or limited to one region.'),
                __('Check your hosting control panel for a resource limit or an ongoing incident.'),
                __('If you changed something in the last hour, undo that change first.'),
            ],
        };
    }

    /**
     * The second-observation-point sentence, or null when only one location saw
     * the failure. Two independent networks agreeing is what makes an incident
     * defensible, so it earns its own sentence instead of a parenthesis.
     */
    public static function confirmation(?string $cause): ?string
    {
        if ($cause === null || preg_match(self::CONFIRMATION_PATTERN, $cause, $m) !== 1) {
            return null;
        }

        return __('We confirmed this independently from our :location probe.', ['location' => trim($m[1])]);
    }

    /**
     * The reason with the confirmation suffix removed.
     */
    public static function stripConfirmation(?string $cause): string
    {
        return trim((string) preg_replace(self::CONFIRMATION_PATTERN, '', (string) $cause));
    }

    /**
     * @return array{0: string, 1: string} the failure kind and its detail — an
     *                                     HTTP code, a port, or the raw reason
     */
    private static function classify(?string $cause): array
    {
        $reason = self::stripConfirmation($cause);

        if ($reason === '') {
            return ['empty', ''];
        }

        if (preg_match('/^HTTP (\d{3})$/', $reason, $m) === 1) {
            return ['http', $m[1]];
        }

        if ($reason === 'Keyword not found') {
            return ['keyword_missing', $reason];
        }

        if ($reason === 'Unwanted keyword found') {
            return ['keyword_unwanted', $reason];
        }

        if (preg_match('/^TCP connect to \S+:(\d+) failed/i', $reason, $m) === 1) {
            return ['tcp_port', $m[1]];
        }

        $lower = strtolower($reason);

        return match (true) {
            str_contains($lower, 'timed out'), str_contains($lower, 'timeout'), str_contains($lower, 'timed-out') => ['timeout', $reason],
            str_contains($lower, 'could not resolve host'), str_contains($lower, 'name or service not known') => ['dns', $reason],
            str_contains($lower, 'connection refused'), str_contains($lower, 'failed to connect') => ['refused', $reason],
            str_contains($lower, 'connection reset'), str_contains($lower, 'econnreset') => ['reset', $reason],
            str_contains($lower, 'ssl certificate problem'), str_contains($lower, 'certificate verify failed') => ['tls_certificate', $reason],
            str_contains($lower, 'ssl'), str_contains($lower, 'tls handshake') => ['tls', $reason],
            default => ['unknown', $reason],
        };
    }

    private static function httpClause(int $code): string
    {
        $text = Response::$statusTexts[$code] ?? null;

        return $text !== null
            ? __('is returning HTTP :code :text', ['code' => $code, 'text' => $text])
            : __('is returning HTTP :code', ['code' => $code]);
    }

    /**
     * @return list<string>
     */
    private static function httpRemedies(int $code): array
    {
        return match (true) {
            $code === 500 => [
                __('A 500 is almost always a PHP fatal error — the hosting error log will name the file and line.'),
                __('If you installed or updated a plugin or theme in the last hour, disable it and reload the site.'),
                __('If the log points nowhere obvious, restore the most recent backup and work forward from there.'),
            ],
            $code === 502 || $code === 504 => [
                __('The web server is up but the PHP process behind it is not answering — ask your host to restart PHP-FPM.'),
                __('This often follows a query or a cron job that never finishes; check for a long-running process.'),
            ],
            $code === 503 => [
                __('Check whether a maintenance-mode plugin or an interrupted update left the site in maintenance.'),
                __('If not, the server is shedding load — check the traffic and the resource limits on your plan.'),
            ],
            $code === 401 || $code === 403 => [
                __('Something is blocking our checks rather than serving the page — a firewall, a security plugin, or a WAF rule.'),
                __('If you added HTTP authentication or an IP allowlist, our probe is being turned away by it.'),
            ],
            $code === 404 => [
                __('The monitored address no longer exists. Open the monitor settings and confirm the URL is still right.'),
                __('If you changed permalinks or moved the homepage, point the monitor at the new address.'),
            ],
            $code === 429 => [
                __('A rate limiter is counting our checks as abuse — allowlist the monitor, or lengthen the check interval.'),
            ],
            $code >= 500 => [
                __('The server is failing to build the page — start with the hosting error log.'),
                __('Undo the most recent change to the site, then reload.'),
            ],
            default => [
                __('The server answered, but refused to serve the page — check your redirects, firewall rules, and security plugins.'),
            ],
        };
    }
}
