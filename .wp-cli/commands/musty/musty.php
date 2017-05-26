<?php
/**
 * Manage MU Plugins via WP-CLI
 *
 * @package musty
 * @version 0.1.0-1
 *
 * @wordpress-plugin
 * Plugin Name: Musty
 * Version: 0.1.0-1
 * Plugin URI: https://blobfolio.com
 * Description: Manage MU Plugins via WP-CLI
 * Author: Blobfolio, LLC
 * Author URI: https://blobfolio.com/
 * Text Domain: musty
 * Domain Path: /languages/
 * License: WTFPL
 * License URI: http://www.wtfpl.net/
 */

namespace blobfolio\wp\cli;

use WP_CLI;
use WP_CLI_COMMAND;
use WP_CLI\Formatter;
use WP_CLI\Utils;

// Add the main command.
WP_CLI::add_command(
	'musty',
	'\\blobfolio\\wp\\cli\\musty',
	array(
		'before_invoke'=>function() {
			if (is_multisite()) {
				WP_CLI::error(__('This plugin is not multisite compatible.'));
			}

			global $wp_filesystem;
			WP_Filesystem();
		},
	)
);

class musty extends \WP_CLI_Command {

	const API_FIELDS = array(
		'active_installs'=>false,
		'added'=>false,
		'banners'=>false,
		'compatibility'=>false,
		'contributors'=>false,
		'description'=>false,
		'donate_link'=>false,
		'downloaded'=>false,
		'downloadlink'=>true,
		'group'=>false,
		'homepage'=>false,
		'icons'=>false,
		'last_updated'=>false,
		'rating'=>false,
		'ratings'=>false,
		'requires'=>false,
		'reviews'=>false,
		'screenshots'=>false,
		'sections'=>false,
		'short_description'=>false,
		'tags'=>false,
		'tested'=>false,
		'versions'=>false,
	);
	private static $mu_plugins;

	/**
	 * Find MU Plugin Paths
	 *
	 * Find main execution files within MU subfolders,
	 * expected in cases where "regular" plugins were
	 * just dumped into th mu-plugins path.
	 *
	 * @see get_plugins()
	 *
	 * @param string $subdir Directory.
	 * @param bool $refresh Refresh.
	 * @return bool True/false.
	 */
	private static function load_mu_plugins($subdir='', $refresh=false) {
		global $wp_filesystem;

		try {
			// Always cause for failure.
			if (!defined('WPMU_PLUGIN_DIR')) {
				WP_CLI::error(__('Must-Use is not configured.', 'musty'));
				static::$mu_plugins = array();
				return false;
			}

			if (!$wp_filesystem->exists(WPMU_PLUGIN_DIR)) {
				if (!$wp_filesystem->mkdir(WPMU_PLUGIN_DIR, FS_CHMOD_DIR)) {
					WP_CLI::error(__('The Must-Use plugin directory does not exist and could not be created.', 'musty'));
					static::$mu_plugins = array();
					return false;
				}
			}

			$base = trailingslashit(WPMU_PLUGIN_DIR);

			// Figure out subdir. This can only be one level in.
			if ($subdir) {
				$subdir = (string) $subdir;
				$subdir = rtrim(ltrim($subdir, '/'), '/');
				if (
					(false !== strpos($subdir, '/')) ||
					('.' === substr($subdir, 0, 1)) ||
					!$wp_filesystem->is_dir("{$base}{$subdir}")
				) {
					return false;
				}

				$subdir = trailingslashit($subdir);
			}
			else {
				$subdir = '';
			}

			// Are we doing anything?
			if (
				is_array(static::$mu_plugins) &&
				!$refresh &&
				!$subdir
			) {
				return true;
			}
			elseif (!is_array(static::$mu_plugins)) {
				static::$mu_plugins = array();
			}

			if ($dir = @opendir("{$base}{$subdir}")) {
				while (false !== ($file = @readdir($dir))) {
					if (
						('.' === $file) ||
						('..' === $file) ||
						('.' === substr($file, 0, 1)) ||
						is_link("{$base}{$subdir}{$file}")
					) {
						continue;
					}

					// Files.
					if ($wp_filesystem->is_file("{$base}{$subdir}{$file}")) {
						if ('.php' === strtolower(substr($file, -4))) {
							// WordPress never codified naming conventions for a
							// plugin's "main" file. The only way to figure that
							// out is to load PHP files and see if they have
							// metadata.
							$plugin_data = get_plugin_data("{$base}{$subdir}{$file}", false, false );

							// If name is good, this must be a main plugin file.
							if (!empty($plugin_data['Name'])) {

								// We want to provide a flexible way for self-hosted
								// plugins to be updated via Musty. InfoURI should
								// point to a JSON file with the same data as the
								// main WP repo API. Although for our purposes,
								// only "version" and "download_link" are required.
								// This can either be supplied in the main plugin
								// file's header as "Info URI: xxx", or via the
								// filter "musty_info_uri_$pluginfile".
								$extra = get_file_data(
									"{$base}{$subdir}{$file}",
									array('InfoURI'=>'Info URI'),
									'plugin'
								);
								$plugin_data['InfoURI'] = apply_filters("musty_info_uri_{$subdir}{$file}", $extra['InfoURI']);

								// We'll fetch these later.
								$plugin_data['DownloadURI'] = '';
								$plugin_data['DownloadVersion'] = '';
								$plugin_data['Upgrade'] = false;

								static::$mu_plugins["{$subdir}{$file}"] = $plugin_data;
								if ($subdir) {
									return true;
								}
							}
						}
						continue;
					}
					// Directories.
					elseif (!$subdir && $wp_filesystem->is_dir("{$base}{$file}")) {
						static::load_mu_plugins($file);
					}
				}
				@closedir("{$base}{$subdir}");
			}

			uasort(static::$mu_plugins, '_sort_uname_callback');

			static::load_mu_updates();

			return true;
		} catch (\Throwable $e) {
			print_r($e);
			return false;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Find MU Updates
	 *
	 * For whatever reason, MU Plugins are removed from
	 * the usual update process, even if they're hosted
	 * by WP. This will run API calls to try to sort it
	 * out.
	 *
	 * @return bool True/false.
	 */
	private static function load_mu_updates() {
		if (!is_array(static::$mu_plugins) || !count(static::$mu_plugins)) {
			return true;
		}

		// Make sure the APIs are present.
		require_once(trailingslashit(ABSPATH) . 'wp-admin/includes/plugin.php');
		require_once(trailingslashit(ABSPATH) . 'wp-admin/includes/plugin-install.php');

		foreach (static::$mu_plugins as $k=>$v) {
			// The plugin will try to check for updates itself, but plugins
			// can also provide their own methods and pass the results via
			// filters.
			static::$mu_plugins[$k]['DownloadVersion'] = apply_filters("musty_download_version_$k", $v['DownloadVersion']);
			static::$mu_plugins[$k]['DownloadURI'] = apply_filters("musty_download_uri_$k", $v['DownloadURI']);

			// Already found?
			if (static::$mu_plugins[$k]['DownloadVersion']) {
				// Just in case this wasn't set correctly before.
				static::$mu_plugins[$k]['Upgrade'] = (
					version_compare(
						static::$mu_plugins[$k]['Version'],
						static::$mu_plugins[$k]['DownloadVersion']
					) < 0
				);

				continue;
			}

			// Self-hosted?
			if (static::$mu_plugins[$k]['InfoURI']) {
				$response = wp_remote_get(static::$mu_plugins[$k]['InfoURI']);
				if (200 === wp_remote_retrieve_response_code($response)) {
					$response = wp_remote_retrieve_body($response);
					$response = json_decode($response, true);
					if (
						is_array($response) &&
						isset($response['version']) &&
						isset($response['download_link'])
					) {
						static::$mu_plugins[$k]['DownloadVersion'] = $response['version'];
						static::$mu_plugins[$k]['DownloadURI'] = $response['download_link'];
					}
				}
			}
			else {
				$slug = Utils\get_plugin_name($k);
				if ($slug) {
					$response = plugins_api(
						'plugin_information',
						array('slug'=>$slug, 'fields'=>static::API_FIELDS)
					);
					if (
						!is_wp_error($response) &&
						is_a($response, 'stdClass') &&
						isset($response->version) &&
						isset($response->download_link)
					) {
						static::$mu_plugins[$k]['DownloadVersion'] = $response->version;
						static::$mu_plugins[$k]['DownloadURI'] = $response->download_link;
					}
				}
			}

			static::$mu_plugins[$k]['Upgrade'] = (
				version_compare(
					static::$mu_plugins[$k]['Version'],
					static::$mu_plugins[$k]['DownloadVersion']
				) < 0
			);
		}
	}

	/**
	 * Newest Plugin
	 *
	 * This is a hacky way to find out what `wp install` achieved
	 * since the function doesn't provide feedback in a parseable
	 * way.
	 *
	 * @return mixed Plugin or false.
	 */
	private static function latest_plugin() {
		global $wp_filesystem;

		$newest_time = 0;
		$newest_file = false;

		$base = trailingslashit(WP_PLUGIN_DIR);

		if ($dir = @opendir($base)) {
			while (false !== ($file = @readdir($dir))) {
				if (
					('.' === $file) ||
					('..' === $file) ||
					('index.php' === $file) ||
					('.' === substr($file, 0, 1))
				) {
					continue;
				}

				$tmp = $wp_filesystem->mtime("{$base}{$file}");
				if ($tmp > $newest_time) {
					$newest_time = $tmp;
					$newest_file = $file;
				}
			}
			@closedir($base);
		}

		return $newest_file;
	}

	/**
	 * Link Plugins
	 *
	 * WordPress requires a PHP script in the main MU plugins
	 * folder, which is different behavior than for normal plugins,
	 * which get stuffed into folders. This will generate symlinks
	 * to the main plugin files so they can be loaded properly.
	 *
	 * @return void Nothing.
	 */
	public function autoloader() {
		global $wp_filesystem;
		static::load_mu_plugins();

		$base = trailingslashit(WPMU_PLUGIN_DIR);

		$changed = 0;

		// First pass, generate symlinks we're expecting.
		$links = array();
		foreach (static::$mu_plugins as $k=>$v) {
			// We only care about directories.
			if (false === strpos($k, '/')) {
				continue;
			}

			$slug = Utils\get_plugin_name($k) . '.php';
			$links[$slug] = "{$base}{$k}";

			// Remove if it exists.
			if (@is_link("{$base}{$slug}")) {
				// Skip it?
				if ("{$base}{$k}" === @readlink("{$base}{$slug}")) {
					continue;
				}

				@unlink("{$base}{$slug}");
			}

			// Try to create it.
			if (!@symlink("{$base}{$k}", "{$base}{$slug}")) {
				WP_CLI::warning(__('Could not create symlink to') . " {$k}.");
			}

			$changed++;
		}

		// Now remove other symlinks.
		if ($dir = @opendir($base)) {
			while (false !== ($file = @readdir($dir))) {
				if (
					('.' === $file) ||
					('..' === $file) ||
					!@is_link($file)
				) {
					continue;
				}

				if (!array_key_exists($file, $links)) {
					if (!@unlink("{$base}{$file}")) {
						WP_CLI::warning(__('Could not remove old symlink ') . " {$base}{$file}.");
					}
				}
			}
			@closedir($base);
		}

		if ($changed) {
			WP_CLI::success('The symlinks have been regenerated.');
		}
	}

	/**
	 * List Must-Use Plugins
	 *
	 * @return bool True/false.
	 */
	public function list() {
		static::load_mu_plugins();

		// Nothing?
		if (!is_array(static::$mu_plugins) || !count(static::$mu_plugins)) {
			WP_CLI::warning(__('No Must-Use plugins were found.', 'musty'));
			return false;
		}

		// Pull relevant data.
		$data = array();
		foreach (static::$mu_plugins as $k=>$v) {
			$data[] = array(
				'slug'=>Utils\get_plugin_name($k),
				'name'=>$v['Name'],
				'installed'=>$v['Version'],
				'latest'=>$v['DownloadVersion'],
				'upgrade'=>$v['Upgrade'] ? 'Yes' : 'No'
			);
		}

		$headers = array(
			'slug',
			'name',
			'installed',
			'latest',
			'upgrade'
		);

		$args = array('format'=>'table');

		$out = new Formatter($args, $headers);
		$out->display_items($data);

		WP_CLI::success(
			__(
				sprintf('Found %d Must-Use plugin(s).', count($data)),
				'musty'
			)
		);
		return true;
	}

	/**
	 * Install a Must-Use Plugin
	 *
	 * ## OPTIONS
	 *
	 * <plugin|zip|url>...
	 * : One or more plugin slugs or download URIs to install.
	 *
	 * [--force]
	 * : If the plugin already exists, this will force a re-install.
	 *
	 * @param array $args Slug(s) or URI(s).
	 * @param array $assoc_args Flags.
	 * @return bool True/false.
	 */
	public function install($args, $assoc_args = array()) {
		global $wp_filesystem;
		static::load_mu_plugins();

		$force = Utils\get_flag_value($assoc_args, 'force');

		$changed = 0;

		// Now try to install everything.
		foreach ($args as $plugin) {

			// Might be able to save some time...
			if (
				!$force &&
				preg_match('/^[a-z0-9\-]+$/', $plugin) &&
				$wp_filesystem->exists(trailingslashit(WPMU_PLUGIN_DIR) . $plugin)
			) {
				WP_CLI::warning("$plugin " . __('already exists. Use --force to re-install.'));
				continue;
			}

			// Try to install it as a regular plugin, first.
			$result = WP_CLI::runcommand(
				'plugin install ' . escapeshellarg($plugin) . ' --force',
				array(
					'return'=>'all',
					'parse'=>false,
					'launch'=>true,
					'exit_error'=>false,
				)
			);
			$result = (array) $result;

			// Didn't work.
			if (
				!array_key_exists('return_code', $result) ||
				(0 !== $result['return_code'])
			) {
				WP_CLI::error("$plugin " . __('could not be installed.'));
			}

			// Unfortunately `plugin install` doesn't return the slug
			// corresponding to what it just did. The best we can do
			// is see what script/directory most recently changed.
			if (false === ($last = static::latest_plugin())) {
				WP_CLI::error(__('The state of the following plugin could not be determined. It may or may not be in the normal plugins folder:') . " $plugin");
			}

			// Try to move it.
			$old_path = trailingslashit(WP_PLUGIN_DIR) . $last;
			$new_path = trailingslashit(WPMU_PLUGIN_DIR) . $last;

			// Remove the existing path, if necessary.
			if ($wp_filesystem->exists($new_path)) {
				if ($force) {
					if (!$wp_filesystem->delete($new_path, true)) {
						WP_CLI::error("$plugin " . __('already exists and could not be removed.'));
						$wp_filesystem->delete($old_path, true);
					}

					WP_CLI::warning("$plugin " . __('already exists; forcing re-install...'));
				}
				else {
					WP_CLI::warning("$plugin " . __('already exists. Use --force to re-install.'));
					$wp_filesystem->delete($old_path, true);
					continue;
				}
			}

			if (!$wp_filesystem->move($old_path, $new_path, true)) {
				WP_CLI::error("$last " . __('could not be moved to the Must-Use folder.'));
			}

			$changed++;

			WP_CLI::success("$last " . __('was successfully added to the Must-Use folder.'));
		}

		// Last thing, rebuild the links.
		if ($changed > 0) {
			static::$mu_plugins = null;
			$this->autoloader();
		}

		return true;
	}

	/**
	 * Upgrade Must-Use Plugins
	 *
	 * ## OPTIONS
	 *
	 * [<plugin>]
	 * : Plugin slug to update. Default: all plugins.
	 *
	 * @param mixed $args Plugin slug.
	 * @return bool True/false.
	 */
	public function upgrade($args=null) {
		global $wp_filesystem;
		static::load_mu_plugins();

		$to_update = null;
		if (is_array($args) && count($args)) {
			$to_update = $args[0];
		}

		// Nothing?
		if (!is_array(static::$mu_plugins) || !count(static::$mu_plugins)) {
			WP_CLI::warning(__('No Must-Use plugins were found.', 'musty'));
			return false;
		}

		$changed = 0;

		$updates = array();
		foreach (static::$mu_plugins as $k=>$v) {
			$slug = Utils\get_plugin_name($k);

			if (
				$v['Upgrade'] &&
				$v['DownloadURI'] &&
				(is_null($to_update) || ($slug === $to_update))
			) {
				$updates[$k] = $v;
			}
			elseif (
				(!$v['Version'] || !$v['DownloadURI']) &&
				(is_null($to_update) || ($slug === $to_update))
			) {
				WP_CLI::warning("$slug " . __('has no update source.'));
			}
		}

		// Nothing to update?
		if (!count($updates)) {
			if (is_null($to_update)) {
				WP_CLI::success(__('All Must-Use plugins are up-to-date.', 'musty'));
			}
			else {
				WP_CLI::success("$slug " . __('is already up-to-date.', 'musty'));
			}
			return true;
		}

		// One at a time now.
		foreach ($updates as $k=>$v) {
			$slug = Utils\get_plugin_name($k);

			$old_path = trailingslashit(WP_PLUGIN_DIR) . $slug;
			$new_path = trailingslashit(WPMU_PLUGIN_DIR) . $slug;

			// Try to install it as a regular plugin, first.
			$result = WP_CLI::runcommand(
				'plugin install ' . escapeshellarg($v['DownloadURI']) . ' --force',
				array(
					'return'=>'all',
					'parse'=>false,
					'launch'=>true,
					'exit_error'=>false,
				)
			);
			$result = (array) $result;

			// Didn't work.
			if (
				!array_key_exists('return_code', $result) ||
				(0 !== $result['return_code'])
			) {
				WP_CLI::warning("$slug " . __('could not be updated.'));
				if ($wp_filesystem->exists($old_path)) {
					$wp_filesystem->delete($old_path, true);
				}
				continue;
			}

			// Remove the existing path, if necessary.
			if ($wp_filesystem->exists($new_path)) {
				if (!$wp_filesystem->delete($new_path, true)) {
					WP_CLI::warning("$slug " . __('could not be updated.'));
					if ($wp_filesystem->exists($old_path)) {
						$wp_filesystem->delete($old_path, true);
					}
					continue;
				}
			}

			if (!$wp_filesystem->move($old_path, $new_path, true)) {
				WP_CLI::warning("$slug " . __('could not be updated.'));
				if ($wp_filesystem->exists($old_path)) {
					$wp_filesystem->delete($old_path, true);
				}
				continue;
			}

			WP_CLI::success("$slug " . __(
				sprintf(
					'was successfully updated from %s to %s.',
					$v['Version'],
					$v['DownloadVersion']
				)
			));

			$changed++;
		}

		// Last thing, rebuild the links.
		if ($changed > 0) {
			static::$mu_plugins = null;
			$this->autoloader();
		}

		return true;
	}
}
