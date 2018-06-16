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

use \blobfolio\bob\io;
use \blobfolio\bob\log;
use \blobfolio\common\file as v_file;

class plugin extends \blobfolio\bob\base\mike_wp {
	// Project Name.
	const NAME = 'musty';
	const DESCRIPTION = 'Musty is a WP-CLI plugin to help with Must-Use plugin management.';
	const CONFIRMATION = '';
	const SLUG = 'musty';
	const USE_GRUNT = '';
	const USE_NPM = 'build';

	const RELEASE_TYPE = 'zip';
	const RELEASE_COMPRESS = array('lib/vendor/blobfolio/');

	const PATCH_CLASSES = true;
	const NAMESPACE_SWAP = 'blobfolio\\wp\\musty\\vendor\\';



	/**
	 * Overload: Patch Version
	 *
	 * @return void Nothing.
	 */
	protected static function patch_version() {
		// The index file also contains a constant.
		$file = static::get_plugin_dir() . 'index.php';
		$content = file_get_contents($file);
		$content = preg_replace(
			"/define\('MUSTY_VERSION', '(\d+\.\d+\.\d+)'\);/",
			"define('MUSTY_VERSION', '" . static::$_version . "');",
			$content
		);
		file_put_contents($file, $content);
	}

	/**
	 * Overload: Class Patching
	 *
	 * @param string $content Content.
	 * @return int Replacements.
	 */
	protected static function patch_classes(string &$content) {
		$manual = array(
			"\\blobfolio\\common"=>"\\blobfolio\\wp\\musty\\vendor\\common",
			"\\blobfolio\\domain"=>"\\blobfolio\\wp\\musty\\vendor\\domain",
			"use \\blobfolio\\phone\\phone;"=>''
		);
		$tmp = $content;
		$content = str_replace(
			array_keys($manual),
			array_values($manual),
			$content,
			$count
		);
		return $count;
	}

	/**
	 * Overload: Build Release
	 *
	 * Unlike most plugins, this one also needs to be packaged for
	 * Debian systems.
	 *
	 * @return void Nothing.
	 */
	protected static function build_release() {
		log::print('Copying Debian release files…');

		$tmp = io::make_dir();
		v_file::copy(static::get_skel_dir(), $tmp);

		// Except we don't need composer.
		if (is_file("{$tmp}composer.json")) {
			unlink("{$tmp}composer.json");
		}

		// Copy our working directory over too.
		v_file::copy(static::$_working_dir, "{$tmp}opt/musty/");

		$file = "{$tmp}DEBIAN/control";
		if (!is_file($file)) {
			log::error('Missing control file.');
		}

		log::print('Patching control file…');
		$content = file_get_contents($file);
		$content = str_replace(
			array(
				'%VERSION%',
				'%SIZE%',
			),
			array(
				static::get_package_version(),
				static::get_package_size(),
			),
			$content
		);
		file_put_contents($file, $content);

		$deb = dirname(static::get_release_path()) . '/wp-cli-musty.deb';
		io::deb($tmp, $deb);

		log::print('Cleaning up…');
		v_file::rmdir($tmp);
	}

	/**
	 * Get Shitlist
	 *
	 * @return array Shitlist.
	 */
	protected static function get_shitlist() {
		return io::SHITLIST;
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
}
