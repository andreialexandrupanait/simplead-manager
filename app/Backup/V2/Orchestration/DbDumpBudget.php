<?php

declare(strict_types=1);

namespace App\Backup\V2\Orchestration;

/**
 * How long one dump call may hold the database snapshot open.
 *
 * The whole dump has to fit inside a single request, because the consistency guarantee comes from
 * one transaction on one connection: `START TRANSACTION WITH CONSISTENT SNAPSHOT`, held until the
 * last row is written. Stop half way and resuming means reading a different point in time, so the
 * runner refuses a partial dump rather than pass it off as a backup.
 *
 * Which makes the budget a hard edge rather than a tuning knob. The `low_impact` profile allows 20
 * seconds — chosen to be gentle on a small site, and enough for one. A WooCommerce database does
 * not finish in twenty seconds, and the result is not a slow backup: it is a backup that fails at
 * the same point every single night, with an error about consistency that reads like corruption.
 *
 * So the budget escalates. A dump that ran out of time is not a fault — nothing was written wrong,
 * the site simply needs longer — and the honest response is to give it longer and try again, up to
 * what the transport can hold. What finally worked is remembered, so tomorrow starts there instead
 * of rediscovering it at the site's expense.
 */
final class DbDumpBudget
{
    /**
     * Head-room between the budget and the HTTP timeout.
     *
     * The plugin stops when the budget is spent, then still has to compress and finish the last
     * segment. A timeout equal to the budget cuts it off during exactly that, turning a dump that
     * worked into a transport error.
     */
    private const REQUEST_MARGIN_SECONDS = 30;

    /** Multiplier per rung. Three, so a big database gets there in two steps, not five. */
    private const FACTOR = 3;

    /**
     * The rungs to try, in order, starting from what the profile (or memory) says.
     *
     * @return list<int>
     */
    public static function ladder(int $start, ?int $ceiling = null): array
    {
        $ceiling = $ceiling ?? self::ceiling();
        $start = max(1, min($start, $ceiling));

        $rungs = [$start];
        $next = $start;

        while ($next < $ceiling && count($rungs) < 3) {
            $next = min($next * self::FACTOR, $ceiling);
            if ($next > end($rungs)) {
                $rungs[] = $next;
            }
        }

        return $rungs;
    }

    /**
     * The most a dump may take.
     *
     * Our own HTTP timeout follows the budget, so we are not the limit — what is, is whatever sits
     * between us and the site. Cloudflare, which most of these sites are behind, gives up on an
     * origin response after 100 seconds and answers 524; the site would still be dumping, and the
     * error would say nothing about why. Ninety leaves room to finish and reply.
     *
     * A site that is not behind such a proxy can be given more, per site, when a database genuinely
     * needs it.
     */
    public static function ceiling(): int
    {
        return max(30, (int) config('backup_v2.plugin.db_time_budget_max', 90));
    }

    /**
     * How long to wait on a dump call that was given this budget.
     */
    public static function requestTimeoutFor(int $budget): int
    {
        return $budget + self::REQUEST_MARGIN_SECONDS;
    }
}
