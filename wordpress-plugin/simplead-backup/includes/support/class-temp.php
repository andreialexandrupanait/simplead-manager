<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Owns the plugin's private temporary storage. Everything the engine writes on
 * disk lives under sys_get_temp_dir()/sam_backup/ — never inside the connector's
 * space and never inside wp-content (which would then get backed up recursively).
 */
final class SAM_Backup_Temp {

    /**
     * Root temp directory for this plugin, e.g. /tmp/sam_backup.
     */
    public static function root(): string {
        return rtrim(sys_get_temp_dir(), '/') . '/sam_backup';
    }

    /**
     * Ensure the root (and an optional sub-path under it) exists and return it.
     */
    public static function ensure(string $sub = ''): string {
        $path = self::root();
        if ($sub !== '') {
            $path .= '/' . ltrim($sub, '/');
        }
        if (!is_dir($path)) {
            // 0700 — private to the web-server user.
            @mkdir($path, 0700, true);
        }
        return $path;
    }

    /**
     * A fresh, unique working directory for one backup session's DB export.
     */
    public static function session_dir(string $session_id): string {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $session_id);
        return self::ensure('sessions/' . $safe);
    }

    /**
     * Free bytes on the temp filesystem (used by capability discovery / disk guard).
     */
    public static function free_bytes(): ?int {
        $free = @disk_free_space(self::root());
        if ($free === false || $free === null) {
            $free = @disk_free_space(sys_get_temp_dir());
        }
        return ($free === false || $free === null) ? null : (int) $free;
    }

    /**
     * Recursively remove a directory that must live under our temp root (guard against
     * accidental deletion of anything else).
     */
    public static function remove_dir(string $path): void {
        $root = self::root();
        $real = realpath($path);
        if ($real === false || strpos($real, $root) !== 0) {
            return; // refuse to touch anything outside our temp root
        }
        $items = @scandir($real);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $real . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                self::remove_dir($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($real);
    }
}
