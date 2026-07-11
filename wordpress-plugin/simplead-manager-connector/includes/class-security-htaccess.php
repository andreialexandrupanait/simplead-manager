<?php
/**
 * .htaccess security rules management.
 *
 * Uses tagged sections for safe insertion and removal of rules.
 * Includes self-check: after writing, verifies the site isn't returning 500.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Security_Htaccess {

    private const TAG_PREFIX = 'SimpleAd Security';

    /** @var string */
    private $htaccess_path;

    /**
     * Whether a multi-setting batch apply is in progress.
     *
     * During a batch, the pristine .htaccess is snapshotted exactly once up
     * front, so the per-helper backups (add_section / remove_section) are
     * suppressed — otherwise they would overwrite the pristine snapshot with
     * already-modified running content and a failed batch could only roll back
     * to a partial state (P0-12).
     *
     * @var bool
     */
    private $in_batch = false;

    public function __construct() {
        $this->htaccess_path = ABSPATH . '.htaccess';
    }

    /**
     * Apply multiple htaccess settings at once.
     *
     * @param array $settings Key => bool (enabled/disabled)
     * @return array Results per setting
     */
    public function apply_settings(array $settings): array {
        $results = [];
        $rules_map = $this->get_rules_map();

        // Snapshot the pristine .htaccess exactly ONCE, before ANY modification.
        // A push sends all htaccess keys in a single batch, so we must be able to
        // roll back to the true pre-batch original — never to a partially-modified
        // intermediate produced mid-loop (P0-12).
        $snapshot_taken = $this->snapshot_original();

        // Suppress the per-helper backups for the duration of the batch so they
        // cannot clobber the pristine snapshot with already-modified content.
        $this->in_batch = true;

        try {
            foreach ($settings as $key => $enabled) {
                if (!isset($rules_map[$key])) {
                    $results[$key] = ['success' => false, 'message' => 'Unknown setting.'];
                    continue;
                }

                if ($enabled) {
                    $success = $this->add_section($key, $rules_map[$key]);
                } else {
                    $success = $this->remove_section($key);
                }

                $results[$key] = [
                    'success' => $success,
                    'message' => $success
                        ? ($enabled ? 'Rule added.' : 'Rule removed.')
                        : 'Failed to modify .htaccess.',
                ];
            }

            // Self-check: verify site isn't broken
            if (!$this->self_check()) {
                // Roll back to the pristine pre-batch snapshot (not a partial state).
                if ($snapshot_taken) {
                    $this->restore_backup();
                }
                foreach ($results as $key => &$result) {
                    if ($result['success']) {
                        $result['success'] = false;
                        $result['message'] = 'Rolled back: site returned error after changes.';
                    }
                }
                unset($result);
            } elseif ($snapshot_taken) {
                // Batch verified healthy — the pristine snapshot is no longer needed.
                $this->delete_backup();
            }
        } finally {
            $this->in_batch = false;
        }

        return $results;
    }

    /**
     * Add a tagged section to .htaccess.
     */
    public function add_section(string $tag, string $rules): bool {
        if (!is_writable($this->htaccess_path)) {
            return false;
        }

        $contents = file_get_contents($this->htaccess_path);
        if ($contents === false) {
            return false;
        }

        // Create backup (skipped during a batch apply, which snapshots once up front)
        if (!$this->in_batch) {
            $this->create_backup($contents);
        }

        // Remove existing section if present
        $contents = $this->strip_section($contents, $tag);

        // Add new section before WordPress rules
        $begin_tag = '# BEGIN ' . self::TAG_PREFIX . ' - ' . $tag;
        $end_tag = '# END ' . self::TAG_PREFIX . ' - ' . $tag;
        $section = $begin_tag . "\n" . $rules . "\n" . $end_tag . "\n\n";

        // Insert before # BEGIN WordPress if it exists, otherwise prepend
        if (strpos($contents, '# BEGIN WordPress') !== false) {
            $contents = str_replace('# BEGIN WordPress', $section . '# BEGIN WordPress', $contents);
        } else {
            $contents = $section . $contents;
        }

        return $this->atomic_write($contents);
    }

    /**
     * Remove a tagged section from .htaccess.
     */
    public function remove_section(string $tag): bool {
        if (!file_exists($this->htaccess_path)) {
            return true;
        }

        if (!is_writable($this->htaccess_path)) {
            return false;
        }

        $contents = file_get_contents($this->htaccess_path);
        if ($contents === false) {
            return false;
        }

        // Skipped during a batch apply, which snapshots the pristine file once up front.
        if (!$this->in_batch) {
            $this->create_backup($contents);
        }
        $new_contents = $this->strip_section($contents, $tag);

        if ($new_contents === $contents) {
            return true; // Section didn't exist
        }

        return $this->atomic_write($new_contents);
    }

    /**
     * Remove all SimpleAd security sections from .htaccess.
     */
    public function cleanup(): void {
        if (!file_exists($this->htaccess_path) || !is_writable($this->htaccess_path)) {
            return;
        }

        $contents = file_get_contents($this->htaccess_path);
        if ($contents === false) {
            return;
        }

        $this->create_backup($contents);

        $rules_map = $this->get_rules_map();
        foreach (array_keys($rules_map) as $tag) {
            $contents = $this->strip_section($contents, $tag);
        }

        $this->atomic_write($contents);
    }

    /**
     * Check which sections are currently present.
     */
    public function get_active_sections(): array {
        if (!file_exists($this->htaccess_path)) {
            return [];
        }

        $contents = file_get_contents($this->htaccess_path);
        if ($contents === false) {
            return [];
        }

        $active = [];
        $rules_map = $this->get_rules_map();
        foreach (array_keys($rules_map) as $tag) {
            $begin_tag = '# BEGIN ' . self::TAG_PREFIX . ' - ' . $tag;
            $active[$tag] = strpos($contents, $begin_tag) !== false;
        }

        return $active;
    }

    /**
     * Strip a tagged section from content.
     */
    private function strip_section(string $contents, string $tag): string {
        $begin_tag = preg_quote('# BEGIN ' . self::TAG_PREFIX . ' - ' . $tag, '/');
        $end_tag = preg_quote('# END ' . self::TAG_PREFIX . ' - ' . $tag, '/');
        $pattern = '/' . $begin_tag . '.*?' . $end_tag . '\s*/s';
        return preg_replace($pattern, '', $contents);
    }

    /**
     * Atomic file write with temp file + rename.
     */
    private function atomic_write(string $contents): bool {
        $tmp_path = $this->htaccess_path . '.sam-tmp';

        $written = file_put_contents($tmp_path, $contents);
        if ($written === false) {
            @unlink($tmp_path);
            return false;
        }

        if (!rename($tmp_path, $this->htaccess_path)) {
            @unlink($tmp_path);
            return false;
        }

        return true;
    }

    /**
     * Snapshot the current on-disk .htaccess into the backup file, once.
     *
     * Reads the pristine file straight from disk before any modification, so the
     * batch rollback target is the true original. Returns false when there is no
     * file to snapshot (nothing to roll back to).
     */
    private function snapshot_original(): bool {
        if (!file_exists($this->htaccess_path)) {
            return false;
        }

        $contents = file_get_contents($this->htaccess_path);
        if ($contents === false) {
            return false;
        }

        $this->create_backup($contents);
        return true;
    }

    /**
     * Create a backup of the current .htaccess.
     */
    private function create_backup(string $contents): void {
        file_put_contents($this->htaccess_path . '.sam-bak', $contents);
    }

    /**
     * Restore from backup.
     */
    private function restore_backup(): bool {
        $bak_path = $this->htaccess_path . '.sam-bak';
        if (file_exists($bak_path)) {
            return rename($bak_path, $this->htaccess_path);
        }
        return false;
    }

    /**
     * Delete the backup file (called once a batch apply verifies healthy).
     */
    private function delete_backup(): void {
        $bak_path = $this->htaccess_path . '.sam-bak';
        if (file_exists($bak_path)) {
            @unlink($bak_path);
        }
    }

    /**
     * Self-check: GET the site URL and verify we don't get a 500 error.
     */
    private function self_check(): bool {
        $response = wp_remote_get(home_url('/'), [
            'timeout' => 10,
            'sslverify' => false,
            'redirection' => 0,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        // 500, 502, 503 indicate broken .htaccess
        return $status < 500;
    }

    /**
     * Map of setting keys to their .htaccess rules.
     */
    private function get_rules_map(): array {
        return [
            'block_default_files' => '<FilesMatch "^(wp-config\.php|install\.php|wp-settings\.php|wp-load\.php|\.htaccess|\.htpasswd)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>',

            'block_readme_access' => '<FilesMatch "^(readme\.html|readme\.txt|license\.txt)$">
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</FilesMatch>',

            'block_debug_log' => '<Files debug.log>
  <IfModule mod_authz_core.c>
    Require all denied
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
  </IfModule>
</Files>',

            'disable_directory_listing' => 'Options -Indexes',

            'firewall_enabled' => '<IfModule mod_rewrite.c>
  RewriteEngine On
  # Block common SQL injection patterns
  RewriteCond %{QUERY_STRING} (union.*select|concat.*\(|information_schema) [NC,OR]
  # Block common XSS patterns
  RewriteCond %{QUERY_STRING} (<script|javascript:|vbscript:|onclick) [NC,OR]
  # Block file inclusion attempts
  RewriteCond %{QUERY_STRING} (\.\.\/|\.\.\\\\|boot\.ini|etc\/passwd) [NC,OR]
  # Block common exploit patterns
  RewriteCond %{QUERY_STRING} (base64_encode|eval\(|GLOBALS\[|REQUEST\[) [NC]
  RewriteRule .* - [F,L]
</IfModule>',

            'block_author_scans' => '<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_URI} !^/wp-admin/ [NC]
  RewriteCond %{QUERY_STRING} (^|&)author=\d+ [NC]
  RewriteRule .* /? [R=301,L]
</IfModule>',
        ];
    }

    /**
     * Get a single rule by key.
     */
    public function get_rule(string $key): ?string {
        return $this->get_rules_map()[$key] ?? null;
    }
}
