<?php

declare(strict_types=1);

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Zips one of the WordPress plugins this repo ships, so it can be handed to a site.
 *
 * It exists because two places needed the same zip and each built its own: the download controller
 * serves it, and the push job hashes it so the site can prove it got what we sent. Two copies of
 * "walk the directory, add every file" that must agree byte for byte or the site refuses the
 * package with HASH_MISMATCH — which is exactly what happened once already, from a different cause.
 * One builder, used by both, cannot disagree with itself.
 */
final class PluginPackage
{
    private function __construct(
        public readonly string $slug,
        public readonly string $sourceDir,
    ) {}

    public static function connector(): self
    {
        return new self('simplead-manager-connector', base_path('wordpress-plugin/simplead-manager-connector'));
    }

    /** The V2 backup engine — a separate plugin from the connector, on purpose. */
    public static function backupEngine(): self
    {
        return new self('simplead-backup', base_path('wordpress-plugin/simplead-backup'));
    }

    public function exists(): bool
    {
        return is_dir($this->sourceDir);
    }

    /**
     * The plugin's main file, as WordPress names it: `slug/slug.php`.
     */
    public function pluginFile(): string
    {
        return $this->slug.'/'.$this->slug.'.php';
    }

    /**
     * The `Version:` header of the main file — the same string the site reports back after an
     * install, so the two can be compared.
     */
    public function version(): string
    {
        $main = $this->sourceDir.'/'.$this->slug.'.php';
        if (! is_file($main)) {
            return '0.0.0';
        }

        $head = (string) file_get_contents($main, false, null, 0, 8192);
        if (preg_match('/^[ \t\/*#@]*Version:\s*(.+?)$/mi', $head, $m) === 1) {
            return trim($m[1]);
        }

        return '0.0.0';
    }

    /**
     * Build the zip into a temp file and hand back its path. The caller owns the file.
     *
     * Entries are sorted, so two builds of an unchanged working tree produce the same archive and
     * therefore the same hash. Left to the filesystem's iteration order they need not.
     */
    public function buildZip(): string
    {
        if (! $this->exists()) {
            throw new RuntimeException("Plugin source not found: {$this->sourceDir}");
        }

        $path = (string) tempnam(sys_get_temp_dir(), $this->slug.'-');

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$path} for writing.");
        }

        foreach ($this->files() as $relative => $absolute) {
            $zip->addFile($absolute, $this->slug.'/'.$relative);
        }

        $zip->close();

        return $path;
    }

    /**
     * The sha256 of the zip we would serve, for the site to check what it downloaded against.
     */
    public function hash(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $path = $this->buildZip();

        try {
            $hash = hash_file('sha256', $path);

            return $hash === false ? null : $hash;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, string> relative path => absolute path, sorted by relative path
     */
    private function files(): array
    {
        $files = [];
        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($walker as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $real = (string) $file->getRealPath();
            $files[substr($real, strlen($this->sourceDir) + 1)] = $real;
        }

        ksort($files);

        return $files;
    }
}
