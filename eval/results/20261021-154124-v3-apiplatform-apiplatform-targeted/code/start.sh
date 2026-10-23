#!/bin/sh
# Starts the bookshop API on http://localhost:8089. Run from this directory, with no argument.
# Stop it by killing this process.
set -e
cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0
export DATABASE_URL="postgresql://eval_rw:eval_rw@postgres:5432/llmapi_eval?serverVersion=16&charset=utf8"

php -d variables_order=EGPCS bin/console cache:clear --no-warmup --quiet
php -d variables_order=EGPCS bin/console cache:warmup --quiet

export PHP_CLI_SERVER_WORKERS=4
exec php -d variables_order=EGPCS -S 0.0.0.0:8089 -t public
