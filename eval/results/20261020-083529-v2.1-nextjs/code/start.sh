#!/bin/sh
# Builds the Next.js app and serves the bookshop API on port 8089 in the foreground.
set -e
cd "$(dirname "$0")"
export NEXT_TELEMETRY_DISABLED=1

if [ ! -d node_modules ]; then
  npm ci --no-audit --no-fund
fi
npx next build

# exec so that killing this process stops the server itself.
exec node node_modules/next/dist/bin/next start -p 8089
