<?php
/**
 * Musty: Plugins
 *
 * @package musty
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\wp\musty;

use \blobfolio\wp\musty\vendor\common;
use \WP_CLI;
use \WP_CLI\Utils;
use \WP_Error;

class plugins {
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

	protected static $mu_plugins;

	/**
	 * Get MU Plugins
	 *
	 * @see get_plugins()
	 *
	 * @param bool $refresh Refresh.
	 * @return bool True/false.
	 */
	public static function get_mu_plugins($refresh=false) {
		static::load_mu_plugins('', $refresh);
		return static::$mu_plugins;
	}

	/**
	 * Find MU Plugin Paths
	 *
	 * Find main execution files within MU subfolders, expected in cases
	 * where "regular" plugins were just dumped into the mu-plugins
	 * path.
	 *
	 * @see get_plugins()
	 *
	 * @param string $subdir Directory.
	 * @param bool $refresh Refresh.
	 * @return bool True/false.
	 */
	protected static function load_mu_plugins($subdir='', $refresh=false) {
		try {
			$base = files::get_mu_plugins_dir();

			// Figure out subdir. This can only be one level in.
			if ($subdir) {
				common\ref\cast::to_string($subdir);
				common\ref\file::untrailingslash($subdir);
				common\ref\file::unleadingslash($subdir);

				if (
					(false !== common\mb::strpos($subdir, '/')) ||
					('.' === common\mb::substr($subdir, 0, 1)) ||
					!@is_dir("{$base}{$subdir}")
				) {
					return false;
				}

				common\ref\file::trailingslash($subdir);
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
			elseif (!is_array(static::$mu_plugins) || $refresh) {
				static::$mu_plugins = array();
			}

			if ($dir = @opendir("{$base}{$subdir}")) {
				while (false !== ($file = @readdir($dir))) {
					if (
						('.' === $file) ||
						('..' === $file) ||
						('.' === common\mb::substr($file, 0, 1)) ||
						is_link("{$base}{$subdir}{$file}")
					) {
						continue;
					}

					// Files.
					if (@is_file("{$base}{$subdir}{$file}")) {
						if ('.php' === strtolower(common\mb::substr($file, -4))) {
							// WordPress never codified naming
							// conventions for a plugin's "main" file.
							// The only way to figure that out is to
							// load PHP files and see if they have meta.
							$plugin_data = get_plugin_data("{$base}{$subdir}{$file}", false, false );

							// If name is good, this must be a main plugin file.
							if (!empty($plugin_data['Name'])) {

								// We want to provide a flexible way for
								// self-hosted plugins to be updated via
								// Musty. InfoURI should point to a JSON
								// file with the same data as the main
								// WP repo API. Although for our
								// purposes, only "version" and
								// "download_link" are required. This
								// can either be supplied in the main
								// plugin file's header as "Info URI: x"
								// or via the filter
								// "musty_info_uri_$pluginfile".
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
					elseif (!$subdir && @is_dir("{$base}{$file}")) {
						static::load_mu_plugins($file);
					}
				}
				@closedir($dir);
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
	 * For whatever reason, MU Plugins are removed from the usual update
	 * process, even if they're hosted by WP. This will run API calls to
	 * try to sort it out.
	 *
	 * @return bool True/false.
	 */
	protected static function load_mu_updates() {
		if (!is_array(static::$mu_plugins) || !count(static::$mu_plugins)) {
			return true;
		}

		foreach (static::$mu_plugins as $k=>$v) {
			// The plugin will try to check for updates itself, but
			// plugins can also provide their own methods and pass the
			// results via filters.
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
	 * Fetch Source
	 *
	 * Download, extract, and move a plugin source, be it a slug or
	 * local/remote zip.
	 *
	 * @param string $source Source.
	 * @param bool $force Force.
	 * @return WP_Error|bool True or error.
	 */
	public static function get_source($source, $force=false) {
		common\ref\cast::to_string($source);
		$file = false;
		$downloaded = true;

		// This appears to be a Zip path.
		if (false !== common\mb::strpos($source, '.')) {
			if (@is_file($source)) {
				$file = common\file::path($source, true);
				$downloaded = false;
			}
			else {
				common\ref\sanitize::url($source);
				if ($source) {
					$file = download_url($source);
					if (is_wp_error($file)) {
						return $file;
					}
				}
			}

			if (!$file) {
				return new WP_Error(
					'file',
					__('Invalid URI', 'musty') . ": $source"
				);
			}
		}

		// Maybe it is a slug.
		if (!$file) {
			$response = plugins_api(
				'plugin_information',
				array('slug'=>$source, 'fields'=>static::API_FIELDS)
			);
			if (
				!is_wp_error($response) &&
				is_a($response, 'stdClass') &&
				isset($response->version) &&
				isset($response->download_link)
			) {
				$source = $response->download_link;
				common\ref\sanitize::url($source);
				if ($source) {
					$file = download_url($source);
					if (is_wp_error($file)) {
						return $file;
					}
				}
			}
		}

		// If there still isn't a file, we're done.
		common\ref\file::path($file, true);
		if (!$file) {
			return new WP_Error(
				'file',
				__('Invalid URI', 'musty') . ": $source"
			);
		}

		// Extract it.
		$base = files::get_tmp_dir();
		if (true !== files::unzip_file($file, $base)) {
			if ($downloaded) {
				@unlink($file);
			}
			files::clean_tmp_dir(true);
			return new WP_Error(
				'file',
				__('Could not extract Zip', 'musty') . ": $source"
			);
		}

		// Don't need the zip any more.
		if ($downloaded) {
			@unlink($file);
		}

		// What do we have?
		$files = files::get_tmp_files();
		if (!count($files)) {
			return new WP_Error(
				'file',
				__('Could not extract Zip', 'musty') . ": $source"
			);
		}

		foreach ($files as $file) {
			$stub = preg_replace('/^' . preg_quote($base, '/') . '/ui', '', $file);

			// Does this exist as a regular plugin?
			if (@file_exists(files::get_plugins_dir() . $stub)) {
				if ($force) {
					files::delete(files::get_plugins_dir() . $stub);
				}
				else {
					files::clean_tmp_dir(true);
					return new WP_Error(
						'file',
						__('Plugin exists', 'musty') . ": $stub"
					);
				}
			}
			// Or maybe in mu-plugins?
			if (@file_exists(files::get_mu_plugins_dir() . $stub)) {
				if ($force) {
					files::delete(files::get_mu_plugins_dir() . $stub);
				}
				else {
					files::clean_tmp_dir(true);
					return new WP_Error(
						'file',
						__('MU Plugin exists', 'musty') . ": $stub"
					);
				}
			}

			@rename($file, files::get_mu_plugins_dir() . $stub);
		}

		// Last thing, clean the temporary directory.
		files::clean_tmp_dir(true);

		return true;
	}
}
