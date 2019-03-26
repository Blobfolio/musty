<?php
/**
 * Musty: File Helpers
 *
 * @package musty
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\wp\musty;

use blobfolio\wp\musty\vendor\common;
use PclZip;
use Throwable;
use WP_CLI;
use ZipArchive;

class files {

	// Files used to build an ownership/permission consensus.
	const TEST_FILES = array(
		'index.php',
		'wp-config.php',
		'wp-cron.php',
		'wp-load.php',
		'wp-settings.php',
	);

	protected static $abspath;
	protected static $plugins_dir;
	protected static $mu_plugins_dir;
	protected static $tmp_dir;
	protected static $uploads_dir;



	// -----------------------------------------------------------------
	// Paths
	// -----------------------------------------------------------------

	/**
	 * Get Abspath
	 *
	 * @return string Path.
	 */
	public static function get_base_dir() {
		if (\is_null(static::$abspath)) {
			static::$abspath = common\file::path(\ABSPATH);
			static::$abspath = \preg_replace('/^[a-z]:/ui', '', static::$abspath);
		}

		return static::$abspath;
	}

	/**
	 * Get Plugins Root
	 *
	 * @return string Path.
	 */
	public static function get_plugins_dir() {
		if (\is_null(static::$plugins_dir)) {
			static::$plugins_dir = common\file::path(\WP_PLUGIN_DIR);
		}

		return static::$plugins_dir;
	}

	/**
	 * Get MU-Plugins Root
	 *
	 * @return string Path.
	 */
	public static function get_mu_plugins_dir() {
		if (\is_null(static::$mu_plugins_dir)) {
			static::$mu_plugins_dir = common\file::path(\WPMU_PLUGIN_DIR);
			if (! @\file_exists(static::$mu_plugins_dir)) {
				common\file::mkdir(static::$mu_plugins_dir, \FS_CHMOD_DIR);
				if (! @\file_exists(static::$mu_plugins_dir)) {
					WP_CLI::error(
						\__('The Must-Use plugin directory does not exist and could not be created.', 'musty')
					);
				}
			}
		}

		return static::$mu_plugins_dir;
	}

	/**
	 * Get Tmp Root
	 *
	 * @return string Path.
	 */
	public static function get_tmp_dir() {
		if (\is_null(static::$tmp_dir)) {
			static::$tmp_dir = static::get_uploads_dir() . '.musty/';
			if (! @\file_exists(static::$tmp_dir)) {
				common\file::mkdir(static::$tmp_dir, \FS_CHMOD_DIR, true);
				if (! @\file_exists(static::$tmp_dir)) {
					WP_CLI::error(
						\__('The Musty temporary directory could not be created.', 'musty')
					);
				}
			}
		}

		return static::$tmp_dir;
	}

	/**
	 * Clean Tmp Root
	 *
	 * @param bool $rebuild Rebuild.
	 * @return bool True/false.
	 */
	public static function clean_tmp_dir(bool $rebuild=true) {
		if (\is_null(static::$tmp_dir)) {
			return true;
		}

		try {
			if (@\file_exists(static::$tmp_dir)) {
				static::delete(static::$tmp_dir);
			}

			if (@\file_exists(static::$tmp_dir)) {
				WP_CLI::error(
					\__('The Musty temporary directory could not be cleaned.', 'musty')
				);
			}

			static::$tmp_dir = null;
			if ($rebuild) {
				static::get_tmp_dir();
			}
		} catch (Throwable $e) {
			return false;
		}

		return true;
	}

	/**
	 * Get Uploads Root
	 *
	 * @return string Path.
	 */
	public static function get_uploads_dir() {
		if (\is_null(static::$uploads_dir)) {
			$uploads_dir = \wp_upload_dir();
			static::$uploads_dir = common\file::path($uploads_dir['basedir']);
		}

		return static::$uploads_dir;
	}

	// ----------------------------------------------------------------- end paths



	// -----------------------------------------------------------------
	// Misc Helpers
	// -----------------------------------------------------------------

	/**
	 * Delete a File or Directory
	 *
	 * @param string $path Path.
	 * @return bool True/false.
	 */
	public static function delete(string $path) {
		// Obviously bad path.
		common\ref\file::path($path, true);
		if (
			! $path ||
			! \preg_match('/^' . \preg_quote(static::get_base_dir(), '/') . '.+/ui', $path)
		) {
			echo "Bad path: $path\n";
			return false;
		}

		if (@\is_file($path)) {
			@\unlink($path);
		}
		else {
			common\file::rmdir($path);
		}

		return ! @\file_exists($path);
	}

	/**
	 * Unzip File
	 *
	 * The native WordPress functions rely on WP_Filesystem, which is
	 * a bit funky with CLI mode. So... gotta rewrite it all. Haha.
	 *
	 * Unlike the WP version, the destination path is expected to exist.
	 *
	 * @param string $zip Zip path.
	 * @param string $to Destination path.
	 * @return bool True/false.
	 */
	public static function unzip_file(string $zip, string $to) {
		common\ref\file::path($zip);
		common\ref\file::path($to);

		if (
			! $zip ||
			! $to ||
			! @\is_file($zip) ||
			! @\is_dir($to)
		) {
			return false;
		}

		$to = \trailingslashit($to);

		// Do it with ZipArchive?
		if (
			\class_exists('ZipArchive', false) &&
			\apply_filters('unzip_file_use_ziparchive', true)
		) {
			try {
				$size = 0;
				$dirs = array();

				$z = new ZipArchive();
				if (true !== ($zopen = $z->open($zip, ZipArchive::CHECKCONS))) {
					return false;
				}

				// First pass, calculate the needed size and build a
				// list of directories.
				for ($x = 0; $x < $z->numFiles; ++$x) {
					if (false === ($info = $z->statIndex($x))) {
						return false;
					}

					// Skip Mac nonsense.
					if (0 === \strpos($info['name'], '__MACOSX/')) {
						continue;
					}

					$size += $info['size'];

					if ('/' === \substr($info['name'], -1)) {
						$dirs[] = common\file::path("{$to}{$info['name']}", false);
					}
					elseif ('.' !== ($dirname = \dirname($info['name']))) {
						$dirs[] = common\file::path($to . \trailingslashit($dirname), false);
					}
				}

				// Make sure we have room.
				$space = (int) @\disk_free_space(\WP_CONTENT_DIR);
				if ($space && ($size * 2.1) > $space) {
					return false;
				}

				// Make the directories.
				$dirs = \array_unique($dirs);
				\rsort($dirs);
				foreach ($dirs as $d) {
					if (! @\file_exists($d)) {
						common\file::mkdir($d, \FS_CHMOD_DIR);
						if (! @\is_dir($d)) {
							return false;
						}
					}
				}

				// One more time around, kick out the files.
				for ($x = 0; $x < $z->numFiles; ++$x) {
					if (false === ($info = $z->statIndex($x))) {
						return false;
					}

					// Skippable things.
					if (
						('/' === \substr($info['name'], -1)) ||
						(0 === \strpos($info['name'], '__MACOSX/'))
					) {
						continue;
					}

					if (false === ($out = $z->getFromIndex($x))) {
						return false;
					}

					@\file_put_contents("{$to}{$info['name']}", $out);
					if (! @\file_exists("{$to}{$info['name']}")) {
						return false;
					}
					else {
						@\chmod("{$to}{$info['name']}", \FS_CHMOD_FILE);
					}
				}

				$z->close();

				return true;
			} catch (Throwable $e) {
				return false;
			}
		}

		// Try PclZip instead.
		require_once \ABSPATH . 'wp-admin/includes/class-pclzip.php';

		try {
			$z = new PclZip($zip);
			$size = 0;
			$dirs = array();

			$files = $z->extract(\PCLZIP_OPT_EXTRACT_AS_STRING);
			if (! \is_array($files) || ! \count($files)) {
				return false;
			}

			// Again, one loop for size and whatnot.
			foreach ($files as $v) {
				// Skip Mac nonsense.
				if (0 === \strpos($info['name'], '__MACOSX/')) {
					continue;
				}

				$size += $v['size'];

				if ($v['folder']) {
					$dirs[] = common\file::path("{$to}{$v['filename']}", false);
				}
				else {
					$dirs[] = common\file::path($to . \trailingslashit(\dirname($v['filename'])), false);
				}
			}

			// Make sure we have room.
			$space = (int) @\disk_free_space(\WP_CONTENT_DIR);
			if ($space && ($size * 2.1) > $space) {
				return false;
			}

			// Make the directories.
			$dirs = \array_unique($dirs);
			\rsort($dirs);
			foreach ($dirs as $d) {
				if (! @\file_exists($d)) {
					common\file::mkdir($d, \FS_CHMOD_DIR, true);
					if (! @\is_dir($d)) {
						return false;
					}
				}
			}

			// And once more around to actually extract the files.
			foreach ($files as $v) {
				// Skippable things.
				if (
					$v['folder'] ||
					(0 === \strpos($info['name'], '__MACOSX/'))
				) {
					continue;
				}

				@\file_put_contents("{$to}{$v['filename']}", $v['content']);
				if (! @\file_exists("{$to}{$v['filename']}")) {
					return false;
				}
				else {
					@\chmod("{$to}{$v['filename']}", \FS_CHMOD_FILE);
				}
			}

			return true;
		} catch (Throwable $e) {
			return false;
		}

		return false;
	}

	/**
	 * Get Tmp Files
	 *
	 * After e.g. unzipping a package, the temporary directory will have
	 * something in it, hopefully.
	 *
	 * @return array Files.
	 */
	public static function get_tmp_files() {
		$out = array();
		$path = static::get_tmp_dir();

		$handle = @\opendir($path);
		while (false !== ($entry = @\readdir($handle))) {
			// Anything but a dot === not empty.
			if (('.' === $entry) || ('..' === $entry)) {
				continue;
			}

			$out[] = common\file::path("{$path}{$entry}");
		}

		return $out;
	}

	// ----------------------------------------------------------------- end misc

}
