<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MigrationCollisionTest extends TestCase
{
    /**
     * Pre-existing collisions are grandfathered in. Renaming them now requires also
     * updating the `migrations` table in every environment, which is risky outside
     * a coordinated deploy. New collisions MUST NOT be added.
     */
    private const KNOWN_COLLISIONS = [
        '2026_02_05_600001',
        '2026_02_07_100001',
        '2026_02_07_100002',
        '2026_02_20_000001',
        '2026_02_22_000001',
        '2026_04_14_100001',
    ];

    public function test_no_new_migrations_share_the_same_timestamp_prefix(): void
    {
        $migrationsDir = dirname(__DIR__, 2).'/database/migrations';
        $files = glob($migrationsDir.'/*.php');

        $byPrefix = [];
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            if (! preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $basename, $m)) {
                continue;
            }
            $byPrefix[$m[1]][] = $basename;
        }

        $collisions = array_filter($byPrefix, fn ($group) => count($group) > 1);
        $newCollisions = array_diff_key($collisions, array_flip(self::KNOWN_COLLISIONS));

        $message = "New migration timestamp collisions found (rename one of each pair to a unique timestamp):\n".
            implode("\n", array_map(
                fn ($prefix, $group) => "  $prefix:\n    - ".implode("\n    - ", $group),
                array_keys($newCollisions),
                $newCollisions
            ));

        $this->assertEmpty($newCollisions, $message);
    }
}
