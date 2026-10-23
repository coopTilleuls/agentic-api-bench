#!/bin/sh
# Builds the API and runs it in the foreground on port 8089 (exec, so killing this process stops it).
set -e
cd "$(dirname "$0")"
rm -rf dist
npx tsc -p tsconfig.build.json --incremental false
exec node dist/main.js
