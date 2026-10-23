#!/bin/sh
# Builds the API and runs it in the foreground on port 8089.
set -e
cd "$(dirname "$0")"
npm run build
exec node dist/main.js
