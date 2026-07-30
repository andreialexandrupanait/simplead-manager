<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Structured line logger writing to the plugin's own temp root. Deliberately separate
 * from the connector's audit log so a backup storm cannot flood connector diagnostics.
 */
final class SAM_Backup_Logger {

    private static function file(): string {
        return SAM_Backup_Temp::ensure() . '/backup.log';
    }

    public static function log(string $level, string $message, array $context = array()): void {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            empty($context) ? '' : ' ' . wp_json_encode($context)
        );
        @file_put_contents(self::file(), $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = array()): void {
        self::log('info', $message, $context);
    }

    public static function warn(string $message, array $context = array()): void {
        self::log('warn', $message, $context);
    }

    public static function error(string $message, array $context = array()): void {
        self::log('error', $message, $context);
    }

    /**
     * Return the tail of the log for the diagnostic endpoint.
     */
    public static function tail(int $lines = 100): array {
        $file = self::file();
        if (!is_file($file)) {
            return array();
        }
        $all = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($all === false) {
            return array();
        }
        return array_slice($all, -$lines);
    }
}
