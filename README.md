# Musty

Musty is a [WP-CLI](https://wp-cli.org/) plugin that allows must-use WordPress plugins to be managed more or less like regular plugins.

 * Install must-use plugins from slug or URI.
 * Detect and apply must-use plugin updates.
 * Uninstall must-use plugins.
 * List must-use plugins.
 * Symlink workaround for plugins that come in a directory.
 * Support for third-party-hosted content via filters.



##### Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Use](#use)
4. [Developer Reference](#developer-reference)
5. [License](#license)



## Requirements

 * PHP 5.6+;
 * A *nix OS;
 * WP-CLI;

Musty is not compatible with WordPress Multi-Site installations.



## Installation

To build from source:

```bash
# Clone the repository.
git clone https://github.com/Blobfolio/musty.git musty

# Run the build script. Afterwards, you'll find the compiled source in the "trunk" sub-directory.
php musty/build/build.php
```

Alternatively, `.deb` binaries are available via Blobfolio's APT repository for Debian Stretch and Ubuntu Zesty. (Other Debian-based distributions may also work, but aren't officially supported.)

Note: This depends on the `wp-cli` package, also provided by Blobfolio's repo. If missing, it will be installed too.

```bash
# Import the signing key
wget -qO - https://apt.blobfolio.com/public.gpg.key | apt-key add -

# apt.blobfolio.com requires HTTPS connection support.
# This may or may not already be configured on your
# machine. If APT is unable to connect, install:
apt-get install apt-transport-https

# Debian Stretch
echo "deb [arch=amd64] https://apt.blobfolio.com/debian/ stretch main" > /etc/apt/sources.list.d/blobfolio.list

# Ubuntu Zesty
echo "deb [arch=amd64] https://apt.blobfolio.com/debian/ zesty main" > /etc/apt/sources.list.d/blobfolio.list

# Update APT sources
apt-get update

# Install it!
apt-get install wp-cli-musty
```

Musty is meant to be installed as a global plugin; its path will need to be added as a requirement in your WP-CLI [configuration](https://make.wordpress.org/cli/handbook/config/#config-files).

```
require:
  - /opt/musty/index.php
```

If you do not have a WP-CLI configuration already, you can use any of the following paths:
 
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



## Use

Musty includes the following commands:

| Command      | Description                 |
| ------------ | --------------------------- |
| dumpautoload | Activate Plugins            |
| install      | Install a Must-Use Plugin   |
| list         | List Must-Use Plugins       |
| uninstall    | Uninstall a Must-Use Plugin |
| upgrade      | Upgrade Must-Use Plugin(s)  |

Command reference is available in the usual fashion:

```bash
# Type the following from your site root.
wp musty the-command --help
```



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
