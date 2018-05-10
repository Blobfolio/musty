<?php
/**
 * Compile Plugin
 *
 * This will update dependencies, optimize the autoloader, and
 * optionally generate a new release zip.
 *
 * @package musty
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

namespace blobfolio\dev;

use \blobfolio\bob\utility;
use \blobfolio\common\file as v_file;

class plugin extends \blobfolio\bob\base\build_wp {
	const NAME = 'musty';

	// Various file paths.
	const SOURCE_DIR = MUSTY_SOURCE_DIR;
	const COMPOSER_CONFIG = MUSTY_COMPOSER_CONFIG;
	const GRUNT_TASK = 'build';
	const PHPAB_AUTOLOADER = MUSTY_PHPAB_AUTOLOADER;

	// Namespace patching.
	const VENDOR_DIR = MUSTY_SOURCE_DIR . 'lib/vendor/';
	const NAMESPACE_SWAP = 'blobfolio\\wp\\musty\\vendor\\';

	// Release info.
	const RELEASE_OUT = MUSTY_RELEASE_DIR . 'musty.zip';
	const RELEASE_COMPRESS = array(
		'%TMP%lib/vendor/blobfolio/',
	);

	// There are no file dependencies.
	const SKIP_FILE_DEPENDENCIES = true;

	protected static $_version;



	/**
	 * Patch Extra
	 *
	 * @param string $content Content.
	 * @return int Replacements.
	 */
	protected static function patch_extra(string &$content) {
		$manual = array(
			"\\blobfolio\\common"=>"\\blobfolio\\wp\\musty\\vendor\\common",
			"\\blobfolio\\domain"=>"\\blobfolio\\wp\\musty\\vendor\\domain",
			"use \\blobfolio\\phone\\phone;"=>''
		);
		$tmp = $content;
		$content = str_replace(
			array_keys($manual),
			array_values($manual),
			$content
		);
		return ($tmp !== $content) ? 1 : 0;
	}

	/**
	 * Patch Version
	 *
	 * @param string $version Version.
	 * @return void Nothing.
	 */
	protected static function patch_version(string $version) {
		// Patch the base hook cache-break version.
		$tmp = file_get_contents(static::SOURCE_DIR . 'index.php');
		$tmp = preg_replace("/define\('MUSTY_VERSION', '(\d+\.\d+\.\d+)'\);/", "define('MUSTY_VERSION', '$version');", $tmp);
		file_put_contents(static::SOURCE_DIR . 'index.php', $tmp);

		// Store this so we can find it more easily later.
		static::$_version = $version;
	}

	/**
	 * Post-Package Tasks
	 *
	 * @return void Nothing.
	 */
	protected static function post_package() {
		// We also want to build a Debian package.
		utility::log('Copying debian release files…');

		$working = utility::generate_tmp_dir();
		v_file::copy(MUSTY_SKEL_DIR, $working);

		// Remove composer file.
		$composer = "{$working}composer.json";
		if (is_file($composer)) {
			unlink($composer);
		}

		// Copy our working directory over too.
		$php = "{$working}opt/musty/";
		v_file::copy(static::$working_dir, $php);

		// If there is a control file, try to patch it.
		$control = "{$working}DEBIAN/control";
		if (is_file($control)) {
			utility::log('Patching DEBIAN control…');
			$tmp = file_get_contents($control);
			$tmp = str_replace(
				array(
					'%VERSION%',
					'%SIZE%',
				),
				array(
					static::get_package_version(),
					static::get_package_size(),
				),
				$tmp
			);
			file_put_contents($control, $tmp);
		}

		// Make the deb!
		$deb = MUSTY_RELEASE_DIR . 'wp-cli-musty.deb';
		utility::deb($working, $deb);
	}

	/**
	 * Get Version
	 *
	 * Projects will handle this differently depending on how and where
	 * sources come from.
	 *
	 * @return string Version.
	 */
	protected static function get_package_version() {
		return static::$_version;
	}

	/**
	 * Get Size
	 *
	 * We can usually calculate this automatically.
	 *
	 * @return int Size.
	 */
	protected static function get_package_size() {
		$size = 0;

		// Size the whole working directory.
		if (static::$working_dir && is_dir(static::$working_dir)) {
			$size += v_file::dirsize(static::$working_dir);

			// Subtract the DEBIAN folder.
			if (is_dir(static::$working_dir . 'DEBIAN/')) {
				$size -= v_file::dirsize(static::$working_dir . 'DEBIAN/');
			}
		}

		return $size;
	}
}
