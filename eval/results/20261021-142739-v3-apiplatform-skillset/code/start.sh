#!/bin/sh
# Starts the Bookshop API on http://localhost:8089, in the foreground: stop it by killing this process.
set -e
cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

php bin/console cache:clear --no-warmup --quiet
php bin/console cache:warmup --quiet

exec php -S '[::]:8089' -t public public/index.php
