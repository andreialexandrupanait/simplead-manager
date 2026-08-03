<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Plugin;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Every SAM_Backup_Logger::x() call in the plugin must name a method that exists.
 *
 * One did not. `SAM_Backup_Logger::warning(...)` — the logger defines warn() — sat on the single
 * branch that tells the manager the host cannot detach the apply and it must run synchronously.
 * On a `final` class with no __callStatic that is a fatal, so instead of the reply the manager
 * needed, the site returned a bare 500, and the restore had no way forward.
 *
 * A misspelled static call is invisible until the line runs, and the lines that log are the lines
 * that only run when something has already gone wrong — which is the worst possible time to find
 * out. Cheaper to read the source.
 */
#[Group('backup-v2')]
class BackupLoggerCallSitesTest extends TestCase
{
    public function test_every_logger_call_in_the_plugin_names_a_real_method(): void
    {
        $base = base_path('wordpress-plugin/simplead-backup');

        // Read the logger rather than load it: every plugin file exits when ABSPATH is undefined,
        // which is correct for a WordPress plugin and fatal for a test process.
        preg_match_all(
            '/public\s+static\s+function\s+([a-zA-Z_]\w*)\s*\(/',
            (string) file_get_contents($base.'/includes/support/class-logger.php'),
            $found
        );
        $available = $found[1];

        $this->assertContains('warn', $available, 'the logger itself must still be the shape we think it is');

        $bad = [];

        foreach ($this->phpFiles($base) as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all('/SAM_Backup_Logger::([a-zA-Z_]\w*)\s*\(/', $source, $matches)) {
                foreach ($matches[1] as $method) {
                    if (! in_array($method, $available, true)) {
                        $bad[] = str_replace($base.'/', '', $file)." → SAM_Backup_Logger::{$method}()";
                    }
                }
            }
        }

        $this->assertSame([], $bad, "these calls would be fatal the moment they run:\n".implode("\n", $bad));
    }

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
