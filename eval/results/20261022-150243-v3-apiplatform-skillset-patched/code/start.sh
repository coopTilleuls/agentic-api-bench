#!/bin/sh
# Serves the bookshop API on http://localhost:8089 in the foreground, until this process is killed.
cd "$(dirname "$0")" || exit 1
APP_ENV=prod php bin/console cache:clear >/dev/null 2>&1
exec env APP_ENV=prod php -S 0.0.0.0:8089 -t public public/index.php
