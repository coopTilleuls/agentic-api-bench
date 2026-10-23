#!/usr/bin/env bash
# Serves the bookshop API on http://localhost:8089. Run from this directory, no argument.
# The server runs in the foreground: stop it by killing this process.
set -eu

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

php bin/console cache:clear --no-warmup --quiet
php bin/console cache:warmup --quiet

exec php -S 0.0.0.0:8089 -t public public/index.php
