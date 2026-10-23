#!/bin/sh
# Starts the Bookshop API on http://localhost:8089 (PHP built-in server, stopped by killing it).
set -e
cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

php bin/console cache:clear
php bin/console cache:warmup

exec php -d variables_order=EGPCS -S 0.0.0.0:8089 -t public public/index.php
