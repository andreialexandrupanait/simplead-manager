<?php

declare(strict_types=1);

namespace App\Backup\V2\Orchestration;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Exceptions\SiteUnderStrain;

/**
 * Stop the backup when the site starts struggling under it.
 *
 * Preflight answers "can this host start", and nothing answered "is this host
 * still coping". A backup is minutes of sustained work against a live site
 * shared with real visitors, and the point where it begins to hurt is not a
 * point any fixed threshold knows in advance: a fast VPS answering in 200 ms and
 * a shared host answering in three seconds are both healthy, and both become
 * unhealthy at very different numbers.
 *
 * So the baseline is measured rather than configured. The first few chunk
 * round-trips establish what this host answers like *while being backed up*, and
 * a sustained multiple of that is the signal — not one slow response, which is
 * ordinary, but several in a row, which is a host that has stopped keeping up.
 *
 * Tripping is not a failure. The session parks with its checkpoint intact and
 * resumes later from the object it reached, so the cost of being wrong is a
 * delay rather than a lost backup — which is the right way round for a decision
 * made about somebody else's server.
 */
class SiteImpactMonitor
{
    /** Round-trips used to learn what "normal" is for this host, under load. */
    private const BASELINE_SAMPLES = 3;

    /** Sustained multiple of the baseline that counts as strain. */
    private const SLOWDOWN_FACTOR = 3.0;

    /** Consecutive slow round-trips before tripping. One slow answer is noise. */
    private const CONSECUTIVE_SLOW = 3;

    /** Consecutive hard failures before tripping. */
    private const CONSECUTIVE_ERRORS = 3;

    /** @var list<float> */
    private array $samples = [];

    private ?float $baseline = null;

    private int $consecutiveSlow = 0;

    private int $consecutiveErrors = 0;

    /**
     * Record a completed round-trip to the host.
     *
     * @throws SiteUnderStrain
     */
    public function observe(float $seconds): void
    {
        $this->consecutiveErrors = 0;

        if (count($this->samples) < self::BASELINE_SAMPLES) {
            $this->samples[] = $seconds;

            if (count($this->samples) === self::BASELINE_SAMPLES) {
                $this->baseline = $this->median($this->samples);
            }

            return;
        }

        // A host answering in milliseconds makes any multiple trivial to exceed
        // on scheduler jitter alone, so the baseline has a floor.
        $baseline = max((float) $this->baseline, 0.5);

        if ($seconds > $baseline * self::SLOWDOWN_FACTOR) {
            $this->consecutiveSlow++;

            if ($this->consecutiveSlow >= self::CONSECUTIVE_SLOW) {
                throw new SiteUnderStrain(
                    BackupErrorCode::HostTimeout,
                    sprintf(
                        'The site slowed to %.1fs per chunk against a %.1fs baseline for %d requests running. '
                        .'Backup paused so it stops competing with the site; it resumes from the last '
                        .'confirmed object.',
                        $seconds,
                        $baseline,
                        $this->consecutiveSlow,
                    ),
                );
            }

            return;
        }

        $this->consecutiveSlow = 0;
    }

    /**
     * Record a round-trip that failed outright.
     *
     * @throws SiteUnderStrain
     */
    public function observeFailure(string $reason): void
    {
        $this->consecutiveSlow = 0;
        $this->consecutiveErrors++;

        if ($this->consecutiveErrors >= self::CONSECUTIVE_ERRORS) {
            throw new SiteUnderStrain(
                BackupErrorCode::HostTimeout,
                sprintf(
                    'The site failed %d requests running (%s). Backup paused rather than kept hammering it; '
                    .'it resumes from the last confirmed object.',
                    $this->consecutiveErrors,
                    $reason,
                ),
            );
        }
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
