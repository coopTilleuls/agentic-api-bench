#!/bin/sh
# Starts the Bookshop API on http://localhost:8089. Stop it by killing this process.
set -e
cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0
export APP_SECRET="${APP_SECRET:-bookshop-local-secret}"
export DATABASE_URL="postgresql://eval_rw:eval_rw@postgres:5432/llmapi_eval?serverVersion=17&charset=utf8"

if [ ! -f vendor/autoload_runtime.php ]; then
    composer install --no-interaction --no-progress
fi

php bin/console cache:warmup > /dev/null

export PHP_CLI_SERVER_WORKERS=8
exec php -S 0.0.0.0:8089 -t public public/index.php
