#!/bin/sh
# Builds and runs the bookshop API on :8089. The server replaces this shell
# (exec), so killing the started process stops the API.
cd "$(dirname "$0")" || exit 1
go build -o bookshop . || exit 1
exec ./bookshop
