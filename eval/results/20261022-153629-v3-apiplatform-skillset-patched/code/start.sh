#!/usr/bin/env bash
# Serves the Bookshop API on http://localhost:8089 in the foreground. Stop it by killing this process.
set -euo pipefail
cd "$(dirname "$0")"
export APP_ENV=prod APP_DEBUG=0
php bin/console cache:clear --no-interaction > /dev/null
exec php -S 0.0.0.0:8089 -t public public/index.php
