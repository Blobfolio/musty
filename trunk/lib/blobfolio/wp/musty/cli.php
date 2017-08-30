<?php
/**
 * Musty: CLI Commands
 *
 * @package musty
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\wp\musty;

use \blobfolio\wp\musty\vendor\common;
use \PclZip;
use \WP_CLI;
use \WP_CLI\Utils;
use \ZipArchive;

/**
 * Musty
 *
 * Manage Must-Use Plugins via WP-CLI.
 *
 * ## EXAMPLES
 *
 *     wp looksee --help
 */
class cli extends \WP_CLI_Command {

	/**
	 * (re)Generate Symlinks
	 *
	 * WordPress requires a PHP script in the main MU plugins folder,
	 * which is different behavior than for normal plugins, which get
	 * into folders. This will generate symlinks to the main plugin
	 * files so they can be loaded properly.
	 *
	 * @return void Nothing.
	 *
	 * @alias autoloader
	 */
	public function dumpautoload() {
		$plugins = plugins::get_mu_plugins();
		$base = files::get_mu_plugins_dir();

		$changed = 0;

		// First pass, generate symlinks we're expecting.
		$links = array();
		foreach ($plugins as $k=>$v) {
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
				WP_CLI::warning(
					__('Could not create symlink to', 'musty') . " {$k}."
				);
			}

			$changed++;
		}

		// Now remove other symlinks.
		if ($dir = @opendir($base)) {
			while (false !== ($file = @readdir($dir))) {
				if (
					('.' === $file) ||
					('..' === $file) ||
					!@is_link("{$base}{$file}")
				) {
					continue;
				}

				if (!array_key_exists($file, $links)) {
					@unlink("{$base}{$file}");
					if (@is_link("{$base}{$file}")) {
						WP_CLI::warning(
							__('Could not remove old symlink', 'musty') . " {$base}{$file}."
						);
					}
					else {
						WP_CLI::warning(
							__('Removed old symlink', 'musty') . " {$base}{$file}."
						);
					}
				}
			}
			@closedir($dir);
		}

		if ($changed) {
			WP_CLI::success(
				__('The symlinks have been regenerated.', 'musty')
			);
		}
	}

	/**
	 * List Must-Use Plugins
	 *
	 * @return bool True/false.
	 *
	 * @subcommand list
	 */
	public function _list() {
		$plugins = plugins::get_mu_plugins();

		// Nothing?
		if (!is_array($plugins) || !count($plugins)) {
			WP_CLI::warning(
				__('No Must-Use plugins were found.', 'musty')
			);
			return false;
		}

		// Pull relevant data.
		$data = array();
		foreach ($plugins as $k=>$v) {
			$data[] = array(
				'slug'=>Utils\get_plugin_name($k),
				'name'=>$v['Name'],
				'installed'=>$v['Version'],
				'latest'=>$v['DownloadVersion'],
				'upgrade'=>($v['Upgrade'] ? __('Yes', 'musty') : __('No', 'musty')),
			);
		}

		$headers = array(
			__('slug', 'musty'),
			__('name', 'musty'),
			__('installed', 'musty'),
			__('latest', 'musty'),
			__('upgrade', 'musty'),
		);

		Utils\format_items('table', $data, $headers);

		WP_CLI::success(
			sprintf(
				__('Found %d Must-Use plugin(s).', 'musty'),
				count($data)
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
		$force = !!Utils\get_flag_value($assoc_args, 'force', false);

		$changed = 0;

		// Now try to install everything.
		foreach ($args as $plugin) {
			// Might be able to save some time...
			if (
				!$force &&
				preg_match('/^[a-z0-9\-]+$/', $plugin) &&
				@file_exists(files::get_mu_plugins_dir() . $plugin)
			) {
				WP_CLI::warning(
					"$plugin " . __('already exists. Use --force to re-install.', 'musty')
				);
				continue;
			}

			$result = plugins::get_source($plugin, $force);
			if (is_wp_error($result)) {
				WP_CLI::warning(
					$result->get_error_message()
				);
			}
			else {
				WP_CLI::success(
					"$plugin " . __('was installed.', 'musty')
				);
				$changed++;
			}
		}

		// Last thing, rebuild the links.
		if ($changed > 0) {
			plugins::get_mu_plugins(true);
			$this->dumpautoload();
		}

		return true;
	}

	/**
	 * Upgrade Must-Use Plugin(s)
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
		$plugins = plugins::get_mu_plugins();

		$to_update = null;
		if (is_array($args) && count($args)) {
			$to_update = $args[0];
		}

		// Nothing?
		if (!is_array($plugins) || !count($plugins)) {
			WP_CLI::warning(
				__('No Must-Use plugins were found.', 'musty')
			);
			return false;
		}

		$changed = 0;

		$updates = array();
		$found = false;
		foreach ($plugins as $k=>$v) {
			$slug = Utils\get_plugin_name($k);

			// Looking for something specific?
			if (!is_null($to_update)) {
				if ($slug !== $to_update) {
					continue;
				}
				$found = true;
			}

			if ($v['Upgrade'] && $v['DownloadURI']) {
				$updates[$k] = $v;
			}
			elseif (!$v['Version'] || !$v['DownloadURI']) {
				WP_CLI::warning(
					"$slug " . __('has no update source.', 'musty')
				);
			}
		}

		if (!is_null($to_update) && !$found) {
			WP_CLI::error(
				"$to_update " . __('is not installed.', 'musty')
			);
		}

		// Nothing to update?
		if (!count($updates)) {
			if (is_null($to_update)) {
				WP_CLI::success(
					__('All Must-Use plugins are up-to-date.', 'musty')
				);
			}
			else {
				WP_CLI::success(
					"$to_update " . __('is already up-to-date.', 'musty')
				);
			}
			return true;
		}

		// One at a time now.
		foreach ($updates as $k=>$v) {
			$slug = Utils\get_plugin_name($k);

			$result = plugins::get_source($v['DownloadURI'], true);
			if (is_wp_error($result)) {
				WP_CLI::warning(
					$result->get_error_message()
				);
			}
			else {
				WP_CLI::success(
					"$slug " . sprintf(
						__('was successfully updated from %s to %s.', 'musty'),
						$v['Version'],
						$v['DownloadVersion']
					)
				);
			}

			$changed++;
		}

		// Last thing, rebuild the links.
		if ($changed > 0) {
			plugins::get_mu_plugins(true);
			$this->dumpautoload();
		}

		return true;
	}

	/**
	 * Uninstall a Must-Use Plugin
	 *
	 * ## OPTIONS
	 *
	 * <plugin>
	 * : Plugin slug to remove.
	 *
	 * @param mixed $args Plugin slug.
	 * @return bool True/false.
	 */
	public function uninstall($args=array()) {
		if (!is_array($args) || !count($args)) {
			WP_CLI::error(
				__('A plugin slug is required.', 'musty')
			);
		}
		$slug = common\mb::trim($args[0]);
		if (!$slug) {
			WP_CLI::error(
				__('A plugin slug is required.', 'musty')
			);
		}

		$base = files::get_mu_plugins_dir();

		// If it is just a file, remove it.
		if (@is_file("{$base}{$slug}")) {
			@unlink("{$base}{$slug}");
		}
		elseif (@is_dir("{$base}{$slug}")) {
			files::delete("{$base}{$slug}");
		}
		else {
			WP_CLI::error(
				"$slug " . __('is not installed.', 'musty')
			);
		}

		if (@file_exists("{$base}{$slug}")) {
			WP_CLI::error(
				"$slug " . __('could not be removed.', 'musty')
			);
		}

		WP_CLI::success(
			"$slug " . __('has been removed.', 'musty')
		);

		plugins::get_mu_plugins(true);
		$this->dumpautoload();

		return true;
	}

	/**
	 * Musty Self-Update
	 *
	 * Update Musty to the latest release.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : If the plugin already exists, this will force a re-install.
	 *
	 * @param array $args Slug(s) or URI(s).
	 * @param array $assoc_args Flags.
	 * @return bool True/false.
	 *
	 * @subcommand self-update
	 */
	public function self_update($args, $assoc_args = array()) {
		$force = !!Utils\get_flag_value($assoc_args, 'force', false);

		if (false === ($musty = plugins::get_musty())) {
			WP_CLI::error(
				__('Musty version information could not be parsed.', 'musty')
			);
		}

		if (!$musty['DownloadURI']) {
			WP_CLI::error(
				__('The remote download URI for Musty could not be found.', 'musty')
			);
		}

		if (!$force && !$musty['Upgrade']) {
			WP_CLI::warning(
				__('Musty is already up-to-date. Use --force to reinstall.', 'musty')
			);
			return false;
		}

		// Get the source.
		$file = download_url($musty['DownloadURI']);
		if (is_wp_error($file)) {
			WP_CLI::error(
				$file->get_error_message()
			);
		}

		// Unzip it.
		$base = files::get_tmp_dir();
		if (true !== files::unzip_file($file, $base)) {
			@unlink($file);
			files::clean_tmp_dir(true);
			return new WP_Error(
				'file',
				__('Could not extract Zip', 'musty') . '.'
			);
		}
		@unlink($file);

		// Take a look at the files.
		$files = files::get_tmp_files();
		$source = false;
		if (count($files) === 1) {
			$source = $files[0];
			if ('musty' !== basename($source)) {
				$source = false;
			}
		}

		// We're expecting a directory named "musty" to have been
		// extracted.
		if (!$source) {
			return new WP_Error(
				'file',
				__('Could not extract Zip', 'musty') . '.'
			);
			files::clean_tmp_dir(true);
		}

		// Do some swapping.
		$backup = trailingslashit(untrailingslashit(MUSTY_ROOT) . '.' . time());
		@rename(MUSTY_ROOT, $backup);
		if (!@file_exists(MUSTY_ROOT) && @file_exists($backup)) {
			@rename($source, MUSTY_ROOT);
			common\file::rmdir($backup);
		}
		else {
			WP_CLI::error(
				__('Musty could not override its own files.', 'musty')
			);
		}

		files::clean_tmp_dir(true);
		$musty_new = plugins::get_musty(true);

		WP_CLI::success(
			'Musty ' . sprintf(
				__('was successfully updated from %s to %s.', 'musty'),
				$musty['Version'],
				$musty_new['Version']
			)
		);

		return true;
	}

	/**
	 * Musty Version
	 *
	 * Print information about Musty itself.
	 *
	 * @return bool True/false.
	 */
	public function version() {
		if (false === ($musty = plugins::get_musty())) {
			WP_CLI::error(
				__('Musty version information could not be parsed.', 'musty')
			);
		}

		// Pull relevant data.
		$data = array(
			array(
				'slug'=>'musty',
				'name'=>$musty['Name'],
				'installed'=>$musty['Version'],
				'latest'=>$musty['DownloadVersion'],
				'upgrade'=>($musty['Upgrade'] ? __('Yes', 'musty') : __('No', 'musty')),
			),
		);

		$headers = array(
			__('slug', 'musty'),
			__('name', 'musty'),
			__('installed', 'musty'),
			__('latest', 'musty'),
			__('upgrade', 'musty'),
		);

		Utils\format_items('table', $data, $headers);

		if (!$musty['Upgrade']) {
			WP_CLI::success(
				__('Musty is up-to-date.', 'musty')
			);
		}
		else {
			WP_CLI::warning(
				__('An update is available. Run "wp musty self-update" to apply it.', 'musty')
			);
		}

		WP_CLI::log(
			__('For more information, visit', 'musty') . " {$musty['PluginURI']}"
		);

		return true;
	}
}
