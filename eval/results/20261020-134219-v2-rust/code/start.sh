#!/bin/sh
# Builds the bookshop API and runs it in the foreground on port 8089.
cd "$(dirname "$0")" || exit 1
cargo build --release --quiet || exit 1
exec ./target/release/bookshop
