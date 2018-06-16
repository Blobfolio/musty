#!/bin/bash
#
# NPM: Clean Libs
# Composer can pull in a lot of garbage that we just don't need. Haha.
#
# These are a little too cumbersome to deal with inside NPM.
##



# Let's start with generic Composer files.
find lib/vendor \( -name "*.markdown" -o -name "*.md" -o -name ".*.yml" -o -name ".gitattributes" -o -name ".gitignore" -o -name "build.xml" -o -name "phpunit.*" \) -type f -not -path "./node_modules/*" -delete

# Now some generic Composer folders.
find lib/vendor \( -name ".git" -o -name "examples" -o -iname "test" -o -iname "tests" \) -type d -not -path "./node_modules/*" -exec rm -rf {} +

# Specific files and folders.
if [ -e "lib/vendor/autoload.php" ]; then
	rm lib/vendor/autoload.php
fi
if [ -e "lib/vendor/blobfolio/blob-common/lib/blobfolio/common/mime.php" ]; then
	rm lib/vendor/blobfolio/blob-common/lib/blobfolio/common/mime.php
fi
if [ -e "lib/vendor/blobfolio/blob-common/lib/blobfolio/common/image.php" ]; then
	rm lib/vendor/blobfolio/blob-common/lib/blobfolio/common/image.php
fi
if [ -e "lib/vendor/bin" ]; then
	rm -rf lib/vendor/bin
fi
if [ -e "lib/vendor/composer" ]; then
	rm -rf lib/vendor/composer
fi
if [ -e "lib/vendor/blobfolio/blob-mimes" ]; then
	rm -rf lib/vendor/blobfolio/blob-mimes
fi
if [ -e "lib/vendor/blobfolio/blob-phone" ]; then
	rm -rf lib/vendor/blobfolio/blob-phone
fi

# Project-wide removals!
find . \( -name "composer.json" -o -name "composer.lock" -o -name ".DS_Store" \) -not -path "./node_modules/*" -delete

# We're done!
echo -e "\033[2mcleaned:\033[0m Composer garbage."



exit 0
