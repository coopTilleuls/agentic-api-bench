#!/usr/bin/env bash
# Starts the Bookshop API on http://localhost:8089 in the prod environment.
# Run from any directory; the process stays in the foreground and is stopped by killing it.
set -euo pipefail

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --no-progress
fi

php bin/console cache:clear --no-warmup --quiet
php bin/console cache:warmup --quiet

exec php -d variables_order=EGPCS -S 0.0.0.0:8089 -t public public/index.php
