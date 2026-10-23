#!/usr/bin/env bash
# Serves the bookshop API on http://localhost:8089 in the foreground.
# Stop it by killing this process.
set -euo pipefail

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0
export DATABASE_URL='postgresql://eval_rw:eval_rw@postgres:5432/llmapi_eval?serverVersion=16&charset=utf8'

php bin/console cache:clear --no-warmup --quiet
php bin/console cache:warmup --quiet

# Listen on IPv6 too when the kernel has it (dual-stack), otherwise on IPv4 only.
if [ -e /proc/net/if_inet6 ]; then
    address='[::]:8089'
else
    address='0.0.0.0:8089'
fi

exec php -S "$address" -t public public/index.php
