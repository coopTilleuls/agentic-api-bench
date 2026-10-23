#!/bin/sh
cd "$(dirname "$0")" || exit 1
export APP_ENV="${APP_ENV:-production}" APP_DEBUG="${APP_DEBUG:-false}"
export APP_KEY="${APP_KEY:-base64:Zm9yLWV2YWwtb25seS0zMi1ieXRlcy1rZXktMTIzNDU=}"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
exec php -d date.timezone=UTC -S "0.0.0.0:${PORT:-8089}" -t public
