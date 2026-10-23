#!/bin/sh
cd "$(dirname "$0")" && exec "${CADDY:-./caddy}" run
