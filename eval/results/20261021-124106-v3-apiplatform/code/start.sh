#!/bin/sh
# Serves the bookshop API on http://localhost:8089 in the foreground.
cd "$(dirname "$0")" || exit 1
export APP_ENV=prod APP_DEBUG=0
php bin/console cache:warmup >/dev/null 2>&1
exec php -S 0.0.0.0:8089 -t public public/index.php
