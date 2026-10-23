#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
[ -d .venv ] || { python3 -m venv .venv && .venv/bin/pip install -q -r requirements.txt; }
exec .venv/bin/gunicorn bookshop.wsgi --bind "0.0.0.0:${PORT:-8089}" --workers 2 --threads 4
