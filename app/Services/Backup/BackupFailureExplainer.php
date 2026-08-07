<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Turns what the engine threw into what the operator should read.
 *
 * The engine's own words are precise and useless at a glance: "The database did not finish dumping
 * in 270 seconds, the most one request may hold the snap…" is not only truncated mid-word by the
 * column it lives in, it never says what to do about it. An error in an interface owes the reader
 * two things — what happened, and what they do now — and the raw exception reliably supplies
 * neither.
 *
 * Anything this class does not recognise falls through unchanged rather than being replaced by a
 * vague catch-all: a message we cannot improve is still better than "Something went wrong".
 */
final class BackupFailureExplainer
{
    /**
     * @return array{message: string, action: string|null}
     */
    public function explain(?string $raw): array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return ['message' => __('The last run failed without saying why.'), 'action' => null];
        }

        // The dump ran past the window a single request may hold a consistent snapshot open for.
        // On this fleet it always means the database has grown past what the host can stream in
        // time — the budget is the symptom, the bloat is the cause.
        if (preg_match('/did not finish dumping in (\d+) seconds/i', $raw, $m) === 1) {
            return [
                'message' => __('The database dump did not finish within :seconds seconds.', ['seconds' => $m[1]]),
                'action' => __('Raise this site\'s database time budget, or clean up the tables that have outgrown it.'),
            ];
        }

        if (preg_match('/returned HTTP (\d{3})/i', $raw, $m) === 1) {
            return [
                'message' => __('The backup engine on this site answered HTTP :code.', ['code' => $m[1]]),
                'action' => __('Open the site and check that the backup engine is installed and answering.'),
            ];
        }

        if (str_contains($raw, 'HeadObject') || str_contains($raw, 'GetObject') || str_contains($raw, 'PutObject')) {
            return [
                'message' => __('Storage did not answer while the backup was being checked.'),
                'action' => __('Nothing was lost. The next run will retry.'),
            ];
        }

        if (stripos($raw, 'memory') !== false || stripos($raw, 'allowed memory size') !== false) {
            return [
                'message' => __('The site ran out of memory during the backup.'),
                'action' => __('Lower the resource profile for this site, or raise its PHP memory limit.'),
            ];
        }

        if (stripos($raw, 'timeout') !== false || stripos($raw, 'timed out') !== false || stripos($raw, 'cURL error 28') !== false) {
            return [
                'message' => __('The site stopped answering during the backup.'),
                'action' => __('Check that the site is up and not rate-limiting this manager.'),
            ];
        }

        if (stripos($raw, '401') !== false || stripos($raw, 'unauthorized') !== false || stripos($raw, 'signature') !== false) {
            return [
                'message' => __('The site rejected this manager\'s credentials.'),
                'action' => __('Reconnect the site so it gets a fresh key pair.'),
            ];
        }

        if (stripos($raw, 'disk') !== false || stripos($raw, 'no space') !== false) {
            return [
                'message' => __('The site ran out of disk space during the backup.'),
                'action' => __('Free space on the host, then run the backup again.'),
            ];
        }

        // Unrecognised: hand back the engine's own words, trimmed to something a row can hold.
        return [
            'message' => \Illuminate\Support\Str::limit($raw, 140),
            'action' => null,
        ];
    }
}
