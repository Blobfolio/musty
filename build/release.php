<?php
/**
 * Package!
 *
 * We want to get rid of source files and whatnot, and since they're
 * kinda all over the place, it is better to let a robot handle it.
 *
 * Dirty, dirty work.
 *
 * @package musty
 * @author  Blobfolio, LLC <hello@blobfolio.com>
 */

define('BUILD_DIR', dirname(__FILE__) . '/');
define('SKEL_DIR', BUILD_DIR . 'skel/');
define('PLUGIN_BASE', dirname(BUILD_DIR) . '/trunk/');
define('RELEASE_BASE', dirname(BUILD_DIR) . '/wp-cli-musty/');
define('RELEASE_SOURCE', RELEASE_BASE . 'opt/musty/');



// Find the version.
$tmp = @file_get_contents(PLUGIN_BASE . 'index.php');
preg_match('/@version\s+([\d\.\-]+)/', $tmp, $matches);
if (is_array($matches) && count($matches)) {
	define('RELEASE_VERSION', $matches[1]);
}
else {
	echo "\nCould not determine version.";
	exit(1);
}



echo "\n";
echo "+ Copying the source.\n";

// Delete the release base if it already exists.
if (file_exists(RELEASE_BASE)) {
	shell_exec('rm -rf ' . escapeshellarg(RELEASE_BASE));
}

// Copy the trunk.
mkdir(RELEASE_BASE . 'opt', 0755, true);
shell_exec('cp -aR ' . escapeshellarg(PLUGIN_BASE) . ' ' . escapeshellarg(RELEASE_SOURCE));

// Copy the debian stuff.
shell_exec('cp -aR ' . escapeshellarg(SKEL_DIR . 'DEBIAN/') . ' ' . escapeshellarg(RELEASE_BASE . 'DEBIAN/'));
$tmp = @file_get_contents(RELEASE_BASE . 'DEBIAN/control');
$tmp = str_replace('%VERSION%', RELEASE_VERSION, $tmp);
@file_put_contents(RELEASE_BASE . 'DEBIAN/control', $tmp);



echo "+ Cleaning the source.\n";
unlink(RELEASE_SOURCE . 'Gruntfile.js');
unlink(RELEASE_SOURCE . 'package.json');
shell_exec('rm -rf ' . escapeshellarg(RELEASE_SOURCE . 'node_modules/'));
shell_exec('find ' . escapeshellarg(RELEASE_BASE) . ' -name ".gitignore" -type f -delete');



echo "+ Fixing permissions.\n";
shell_exec('find ' . escapeshellarg(RELEASE_BASE) . ' -type d -print0 | xargs -0 chmod 755');
shell_exec('find ' . escapeshellarg(RELEASE_BASE) . ' -type f -print0 | xargs -0 chmod 644');



echo "\nDone!.\n";