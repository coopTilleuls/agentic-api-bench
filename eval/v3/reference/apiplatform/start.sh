#!/bin/sh
cd "$(dirname "$0")" || exit 1
export APP_ENV=prod APP_DEBUG=0
export DATABASE_URL="${DATABASE_URL:-postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval}"
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
rm -rf var/cache
php bin/console cache:warmup >/dev/null
exec php -d date.timezone=UTC -d variables_order=EGPCS -S 0.0.0.0:${PORT:-8089} -t public
