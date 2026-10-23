#!/bin/sh
# Starts the Bookshop API on http://localhost:8089, in the foreground: stop it by killing this process.
cd "$(dirname "$0")" || exit 1
export APP_ENV=prod
php bin/console cache:warmup --no-interaction >/dev/null 2>&1 || true
exec php -d variables_order=EGPCS -S 0.0.0.0:8089 -t public public/index.php
