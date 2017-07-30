<?php
/**
 * Manage Must-Use Plugins via WP-CLI.
 *
 * @package musty
 * @version 0.2.1-1
 *
 * @wordpress-plugin
 * Plugin Name: Musty
 * Version: 0.2.1-1
 * Plugin URI: https://github.com/Blobfolio/musty
 * Info URI: https://raw.githubusercontent.com/Blobfolio/musty/master/release/musty.json
 * Description: Manage Must-Use Plugins via WP-CLI.
 * Author: Blobfolio, LLC
 * Author URI: https://blobfolio.com/
 * Text Domain: musty
 * Domain Path: /languages/
 * License: WTFPL
 * License URI: http://www.wtfpl.net/
 */

// This is for WP-CLI only.
if (!defined('WP_CLI') || !WP_CLI) {
	return;
}

// Where are we?
define('MUSTY_ROOT', dirname(__FILE__) . '/');
define('MUSTY_INDEX', MUSTY_ROOT . 'index.php');

// The bootstrap.
@require dirname(__FILE__) . '/lib/autoload.php';

use \blobfolio\wp\musty\files;
use \blobfolio\wp\musty\vendor\common;

// Add the main command.
WP_CLI::add_command(
	'musty',
	'\\blobfolio\\wp\\musty\\cli',
	array(
		'before_invoke'=>function() {
			if (is_multisite()) {
				WP_CLI::error(
					__('This plugin is not multisite compatible.', 'musty')
				);
			}

			// We need MU Plugins.
			if (!defined('WPMU_PLUGIN_DIR')) {
				WP_CLI::error(
					__('Must-Use is not configured.', 'musty')
				);
			}

			// Some helpful requirements.
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(trailingslashit(ABSPATH) . 'wp-admin/includes/plugin.php');
			require_once(trailingslashit(ABSPATH) . 'wp-admin/includes/plugin-install.php');

			// Make sure CHMOD is set.
			if (!defined('FS_CHMOD_DIR')) {
				define('FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
			}
			if (!defined('FS_CHMOD_FILE')) {
				define('FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
			}
		},
	)
);

// Remove Musty temporary directory at shutdown.
register_shutdown_function(function() {
	files::clean_tmp_dir(false);
});

