#!/bin/sh
# Starts the bookshop API on http://localhost:8089 (foreground; stop it by killing the process).
cd "$(dirname "$0")" && exec ./caddy run --config Caddyfile
