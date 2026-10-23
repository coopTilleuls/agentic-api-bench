#!/usr/bin/env bash
# Serves the bookshop API on http://localhost:8089, in the foreground.
# Stop it by killing this process.
set -eu

cd "$(dirname "$0")"

export APP_ENV=prod
export APP_DEBUG=0
export APP_SECRET="${APP_SECRET:-bookshop-eval-secret}"
export DATABASE_URL='postgresql://eval_rw:eval_rw@postgres:5432/llmapi_eval?serverVersion=17&charset=utf8'

php bin/console cache:clear --no-interaction

exec php -d variables_order=EGPCS -S 0.0.0.0:8089 -t public public/index.php
