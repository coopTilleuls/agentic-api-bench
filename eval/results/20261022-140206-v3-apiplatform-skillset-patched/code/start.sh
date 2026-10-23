#!/usr/bin/env bash
# Serves the bookshop API on http://localhost:8089 in the foreground; stop it by killing this process.
set -euo pipefail

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --no-progress --prefer-dist
fi

php bin/console cache:clear --no-warmup --quiet
php bin/console cache:warmup --quiet

exec php -d variables_order=EGPCS -S 0.0.0.0:8089 -t public public/index.php
