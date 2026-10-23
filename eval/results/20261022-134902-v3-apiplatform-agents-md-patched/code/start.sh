#!/bin/sh
# Serves the bookshop API on http://localhost:8089 in the foreground.
set -e
cd "$(dirname "$0")"
export APP_ENV=prod
export APP_DEBUG=0
php bin/console cache:clear --no-warmup -q
php bin/console cache:warmup -q
export PHP_CLI_SERVER_WORKERS=4
exec php -S 0.0.0.0:8089 -t public public/index.php
