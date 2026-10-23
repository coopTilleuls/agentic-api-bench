#!/usr/bin/env bash
# Starts the bookshop API on http://localhost:8089 in the foreground.
set -euo pipefail
cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --no-progress --no-dev --optimize-autoloader
fi

php bin/console cache:clear --no-interaction --quiet
exec php -S 0.0.0.0:8089 -t public public/index.php
