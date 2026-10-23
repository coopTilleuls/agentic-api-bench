#!/bin/sh
# Serves the bookshop API on http://localhost:8089 in the foreground.
cd "$(dirname "$0")" && exec ./caddy run --config Caddyfile
