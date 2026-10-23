#!/bin/sh
# Serves the bookshop API on http://localhost:8089 in the foreground; stop it by killing this process.
cd "$(dirname "$0")" || exit 1
exec php -S 0.0.0.0:8089 -t public public/index.php
