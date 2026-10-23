#!/usr/bin/env bash
# Serves the Bookshop API on http://localhost:8089 in the foreground.
# Stop it by killing this process.
set -euo pipefail
cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --no-progress
fi

php bin/console cache:clear --no-warmup --quiet
php bin/console cache:warmup --quiet

export PHP_CLI_SERVER_WORKERS=8
exec php -S 0.0.0.0:8089 -t public public/index.php
