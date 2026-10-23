#!/bin/sh
# Serves the bookshop API on http://localhost:8089 (stop it by killing this process).
cd "$(dirname "$0")" || exit 1
exec ./caddy run --config Caddyfile
