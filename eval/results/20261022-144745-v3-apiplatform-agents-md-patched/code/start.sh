#!/usr/bin/env bash
# Serves the Bookshop API on http://localhost:8089. Run from this directory, without argument.
# The process runs in the foreground: stop the API by killing it.
set -eu

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0
# Several workers, so that a slow payment does not block the other requests.
export PHP_CLI_SERVER_WORKERS=4

php bin/console cache:warmup --no-interaction

exec php -S 0.0.0.0:8089 -t public public/index.php
