#!/bin/sh
# Serves the Bookshop API on port 8089 in the foreground; stop it by killing this process.
set -eu

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

php bin/console cache:warmup --no-interaction >/dev/null

exec php -S '[::]:8089' -t public public/index.php
