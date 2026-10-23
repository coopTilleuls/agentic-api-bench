#!/bin/sh
# Builds the API and runs it in the foreground on port 8089; stop it by killing this process.
set -e
cd "$(dirname "$0")"
npx nest build >&2
exec node dist/main.js
