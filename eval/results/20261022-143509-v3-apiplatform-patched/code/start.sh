#!/bin/sh
# Starts the Bookshop API on http://localhost:8089 in the foreground. Stop it by killing this process.
set -eu

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0
export DATABASE_URL="postgresql://eval_rw:eval_rw@postgres:5432/llmapi_eval?serverVersion=16&charset=utf8"

php bin/console cache:clear --no-warmup --quiet
php bin/console cache:warmup --quiet

# Dual-stack when IPv6 is available, so that localhost resolves to a listening address.
if [ -f /proc/net/if_inet6 ]; then
    address='[::]:8089'
else
    address='0.0.0.0:8089'
fi

exec php -S "$address" -t public public/index.php
