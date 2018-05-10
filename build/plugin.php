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

use \blobfolio\dev\plugin;

require(__DIR__ . '/lib/vendor/autoload.php');

// Set up some quick constants, namely for path awareness.
define('MUSTY_BUILD_DIR', __DIR__ . '/');
define('MUSTY_SOURCE_DIR', dirname(MUSTY_BUILD_DIR) . '/trunk/');
define('MUSTY_RELEASE_DIR', dirname(MUSTY_BUILD_DIR) . '/release/');
define('MUSTY_SKEL_DIR', MUSTY_BUILD_DIR . 'skel/');
define('MUSTY_COMPOSER_CONFIG', MUSTY_SKEL_DIR . 'composer.json');
define('MUSTY_PHPAB_AUTOLOADER', MUSTY_SOURCE_DIR . 'lib/autoload.php');

// Compilation is as easy as calling this method!
plugin::compile();

// We're done!
exit(0);
