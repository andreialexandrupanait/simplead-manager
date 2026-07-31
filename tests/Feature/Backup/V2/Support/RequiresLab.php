<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

/**
 * One rule for what an absent lab means.
 *
 * The lab-backed tests (MinIO with real S3 semantics, a real WordPress running
 * the simplead-backup plugin, MySQL 8 and MariaDB 11) are the only proof the
 * backup engine works at all. Everything else mocks the two things most likely
 * to be wrong: object storage and the host.
 *
 * Until this existed, every one of them called markTestSkipped when the lab was
 * missing, and the suite reported "OK — 8 skipped". A green build that proved
 * nothing is worse than a red one, because nobody investigates green. So
 * bin/test now starts the lab and pins SAM_LAB=required, which turns those skips
 * into failures; the explicit `bin/test --fast` developer loop is the only place
 * a skip is still honest.
 */
trait RequiresLab
{
    protected function labUnavailable(string $why): void
    {
        if (getenv('SAM_LAB') === 'required') {
            $this->fail(
                $why.' — the lab is required. Start it with '
                .'`docker compose -f spike/docker-compose.spike.yml up -d` '
                .'(bin/test does this for you), or run `bin/test --fast` to skip lab-backed tests.'
            );
        }

        $this->markTestSkipped($why);
    }
}
