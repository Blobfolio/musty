# Musty

Musty is a [WP-CLI](https://wp-cli.org/) plugin that allows [must-use](https://codex.wordpress.org/Must_Use_Plugins) WordPress plugins to be managed more or less like regular plugins.

 * Install must-use plugins from slug or URI.
 * Detect and apply must-use plugin updates.
 * Uninstall must-use plugins.
 * List must-use plugins.
 * Symlink workaround for plugins that come in a directory.
 * Support for third-party-hosted content via filters.



##### Table of Contents

1. [How it Works](#how-it-works)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Use](#use)
5. [Developer Reference](#developer-reference)
6. [License](#license)



## How it Works

Must-use plugins are like normal plugins, except they're stored in `wp-content/mu-plugins/`, and management happens entirely through the file system. Because they exist, WordPress loads them at boot. That's the "must" in "must-use".

The inability for such plugins to be managed through `wp-admin` is a primary selling point for system administrators.

### Activation

Many normal plugins can be installed in must-use mode, except for the fact that they're contained in a subdirectory. Because the MU process is entirely file-based, WordPress limits its scan to the top level of the `mu-plugins/` directory.

To work around this, Musty generates a top-level [symlink](https://en.wikipedia.org/wiki/Symbolic_link) for each plugin it finds living in a subdirectory, using the slugs as a naming scheme. For example:

```
wp-content/mu-plugins/my-plugin.php -> wp-content/mu-plugins/my-plugin/index.php
```

WordPress loads the links, which resolve to the real plugins. Problem solved.

Note: Musty should not be combined with alternative bootstrapping methods, such as an autoloader file containing manual `require()` references.

### Installation

Musty will install plugins in must-use mode given a slug or Zip file (local or remote). If the plugin is already installed as a regular plugin, it will be deleted to prevent collisions. This deletion bypasses the uninstall process, so data and settings should be retained.

Not all plugins will work correctly in must-use mode. They must be able to correctly determine their path (`mu-plugins/` is not `plugins/`), and some functionality, like localization, requires the use of different functions to correctly trigger.

### Updates

One unfortunate consequence of `wp-admin` ignoring MU plugins is that they don't get to take part in the usual update triggers. Musty, at least, will parse each must-use plugin fully to find its remote version and download information, so system administrators can run updates via WP-CLI.

Third-party-hosted plugins can also be updated in this way, provided they expose the necessary information. See [Developer Reference](#developer-reference) for a list of methods and examples.

### Removal

Must-use plugins can also be removed via Musty. This process will also clean up any broken symlinks, etc.



## Requirements

 * PHP 5.6+;
 * A *nix OS;
 * WP-CLI;

Musty is not compatible with WordPress Multi-Site installations.



## Installation

You can manually download [musty.zip](https://raw.githubusercontent.com/Blobfolio/musty/master/release/musty.zip) and extract it somewhere on your server.

Debian-based servers can also install Musty using Blobfolio's APT repository:

```bash
# Import the signing key
wget -qO - https://apt.blobfolio.com/public.gpg.key | apt-key add -

# apt.blobfolio.com requires HTTPS connection support.
# This may or may not already be configured on your
# machine. If APT is unable to connect, install:
apt-get install apt-transport-https

# Debian Stretch
echo "deb [arch=amd64] https://apt.blobfolio.com/debian/ stretch main" > /etc/apt/sources.list.d/blobfolio.list

# Ubuntu Artful
echo "deb [arch=amd64] https://apt.blobfolio.com/debian/ artful main" > /etc/apt/sources.list.d/blobfolio.list

# Update APT sources
apt-get update

# Install it! Note: this will also install the "wp-cli"
# package, if not present.
apt-get install wp-cli-musty
```

Once you have the files on your server, they will need to be added to the WP-CLI [configuration](https://make.wordpress.org/cli/handbook/config/#config-files).

```
require:
  - /opt/musty/index.php
```

WP-CLI automatically recognizes the following generic configuration paths:
 
 * `/site/root/wp-cli.local.yml`
 * `/site/root/wp-cli.yml`
 * `~/.wp-cli/config.yml`

The `.deb` package comes with an example configuration that can be used if you don't need to specify any other options.

```bash
# Install as a symlink.
ln -s /usr/share/musty/wp-cli.local.yml /your/preferred/config/path

# Or copy it.
cp -a /usr/share/musty/wp-cli.local.yml /your/preferred/config/path
```

To verify that the plugin is working correctly, `cd` to a site root and type:

```bash
# This should return information about Musty's subcommands.
wp musty --help
```



## Use

Musty includes the following commands for managing must-use plugins:

| Command      | Description                 |
| ------------ | --------------------------- |
| dumpautoload | (re)Generate Symlinks       |
| install      | Install a Must-Use Plugin   |
| list         | List Must-Use Plugins       |
| uninstall    | Uninstall a Must-Use Plugin |
| upgrade      | Upgrade Must-Use Plugin(s)  |

Command reference is available in the usual fashion:

```bash
# e.g. type any of the following from a site's root.
wp musty dumpautoload --help
wp musty install --help
wp musty list --help
wp musty uninstall --help
wp musty upgrade --help
```

Musty also includes a little in the way of self-awareness:

| Command     | Description                                    |
| ----------- | ---------------------------------------------- |
| self-update | Update the Musty plugin to the latest version. |
| version     | Show Musty plugin details.                     |



## Developer Reference

Musty can install plugins by slug (if hosted by [WordPress](https://wordpress.org/plugins/)) or from a local or remote Zip file.

Update detection for WP-hosted plugins is automatic, but for third-party plugins, Musty needs a little help. Specifically, Musty needs to know the current stable version and where to get it.

There are several ways to expose this information:

### JSON Feed

If your plugin has its own JSON feed mirroring the WP.org API format — with the keys `version` and `download_link` — Musty can use that to pull the relevant information.

#### Metadata

To specify this URL via metadata, add an `Info URI` entry to your main plugin file's headers:

```php
<?php
/**
 * My super awesome plugin is the best.
 *
 * @package my-plugin
 * @version 0.2.0-1
 *
 * @wordpress-plugin
 * Plugin Name: My Plugin
 * Version: 0.2.0-1
 * Info URI: https://mydomain.com/plugin-info.json
 * ...
 */
```

#### Filter

Or to specify this URL via filter, use the following:

```php
function my_uri_function($json_url) {
    return 'https://mydomain.com/plugin-info.json';
}
add_filter('musty_info_uri_my-plugin/index.php', 'my_uri_function');
```

### Manual Version via Filter

If remote updates are handled in some other arbitrary way, you can inform Musty of the latest release version via filter:

```php
function my_version_function($json_url) {
    return '1.2.3';
}
add_filter('musty_download_version_my-plugin/index.php', 'my_version_function');
```

### Manual Download Link via Filter

If remote updates are handled in some other arbitrary way, you can inform Musty of the remote Zip file via filter:

```php
function my_zip_function($json_url) {
    return 'https://mydomain.com/plugin.zip';
}
add_filter('musty_download_version_my-plugin/index.php', 'my_zip_function');
```



## License

Copyright © 2017 [Blobfolio, LLC](https://blobfolio.com) &lt;hello@blobfolio.com&gt;

This work is free. You can redistribute it and/or modify it under the terms of the Do What The Fuck You Want To Public License, Version 2.

    DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
    Version 2, December 2004
    
    Copyright (C) 2004 Sam Hocevar <sam@hocevar.net>
    
    Everyone is permitted to copy and distribute verbatim or modified
    copies of this license document, and changing it is allowed as long
    as the name is changed.
    
    DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
    TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION
    
    0. You just DO WHAT THE FUCK YOU WANT TO.
