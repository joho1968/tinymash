#!/bin/sh
set -eu

BUILD_DIR=/tmp/tinymash-deploy
TARGET=example.com:/var/www/tinymash/

php8.4 bin/tinymash.php deploy "$BUILD_DIR"

# This sample preserves site-local runtime state by default. Review any
# delete/exclude strategy carefully before using it against an existing site.
rsync -a \
    --exclude '/app/config/tinymash.json' \
    --exclude '/data/*' \
    --exclude '/users/*' \
    --exclude '/tmp/*' \
    "$BUILD_DIR"/ "$TARGET"
