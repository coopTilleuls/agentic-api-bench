#!/bin/sh
# Starts the Bookshop API on http://localhost:8089 in the foreground. Stop it by killing this process.
set -e
cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --no-scripts --no-progress
fi

php bin/console cache:clear --no-warmup >/dev/null 2>&1 || true
php bin/console cache:warmup >/dev/null

# exec: the PHP server replaces this shell, so killing the process stops the API.
exec php -S 0.0.0.0:8089 -t public public/index.php
