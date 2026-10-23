#!/bin/sh
# Serves the Bookshop API on http://localhost:8089 in production mode.
# Runs in the foreground: killing this process stops the API.
set -eu

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0

php bin/console cache:clear --no-debug

exec php -d variables_order=EGPCS -S '[::]:8089' -t public public/index.php
