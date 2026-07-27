<?php
/**
 * Plugin Name: Simplead Backup
 * Plugin URI: https://simplead.io
 * Description: Snapshot-parity backup engine for SimpleAd Manager. Consistent logical DB dumps + incremental file backups, uploaded to S3 in resumable chunks. Independent REST namespace, version and release cycle from the connector.
 * Version: 0.4.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: SimpleAd
 * Author URI: https://simplead.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simplead-backup
 *
 * This plugin never uses shell_exec/exec/system/proc_open. All work is done with
 * PHP + mysqli/PDO + ZipArchive/gzopen so it runs on locked-down shared hosting.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Keep the header Version: and this constant in lock-step.
define('SAM_BACKUP_VERSION', '0.4.0');
define('SAM_BACKUP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAM_BACKUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAM_BACKUP_PLUGIN_FILE', __FILE__);
define('SAM_BACKUP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// OWN REST namespace — deliberately NOT simplead/v1 (that belongs to the connector).
define('SAM_BACKUP_REST_NAMESPACE', 'simplead-backup/v1');

// Option prefix and temp-storage root are the plugin's own — the connector is never touched.
define('SAM_BACKUP_OPTION_PREFIX', 'sam_backup_');
define('SAM_BACKUP_TEXT_DOMAIN', 'simplead-backup');

/**
 * Explicit require-based loader. Kept simple and PHP 7.4-safe (no PSR-4 tooling
 * dependency). Order matters: support first, then db, then endpoints, then bootstrap.
 */
$sam_backup_files = array(
    'includes/support/class-temp.php',
    'includes/support/class-options.php',
    'includes/support/class-logger.php',
    'includes/support/class-auth.php',
    'includes/db/class-consistent-dumper.php',
    'includes/files/class-exclusions.php',
    'includes/files/class-inventory.php',
    'includes/files/class-file-chunker.php',
    'includes/files/class-file-diff.php',
    'includes/restore/class-restore-engine.php',
    'includes/endpoints/class-rest-controller.php',
    'includes/endpoints/class-capabilities-endpoint.php',
    'includes/endpoints/class-database-endpoint.php',
    'includes/endpoints/class-files-endpoint.php',
    'includes/endpoints/class-restore-endpoint.php',
    'includes/admin/class-admin-page.php',
    'includes/class-backup-plugin.php',
);
foreach ($sam_backup_files as $sam_backup_rel) {
    require_once SAM_BACKUP_PLUGIN_DIR . $sam_backup_rel;
}
unset($sam_backup_files, $sam_backup_rel);

// Clean activation/deactivation — creates only the plugin's own temp dir + default options.
register_activation_hook(__FILE__, array('SAM_Backup_Plugin', 'on_activate'));
register_deactivation_hook(__FILE__, array('SAM_Backup_Plugin', 'on_deactivate'));

// Boot.
SAM_Backup_Plugin::instance()->boot();
