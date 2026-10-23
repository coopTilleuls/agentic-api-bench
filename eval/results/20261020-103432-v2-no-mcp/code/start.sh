#!/bin/sh
cd "$(dirname "$0")" && exec ./caddy run --config Caddyfile
