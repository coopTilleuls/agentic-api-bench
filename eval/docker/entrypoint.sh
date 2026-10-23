#!/bin/sh
# Starts the quotes stub before running the command.
set -e
# User settings in the empty HOME switch off the bundled skills and the
# builtin plugins that can be switched off.
mkdir -p "$CLAUDE_CONFIG_DIR"
cp /opt/claude/settings.json "$CLAUDE_CONFIG_DIR/settings.json"
/opt/stub/stub &
for _ in $(seq 1 40); do
	curl -s -o /dev/null http://127.0.0.1:18090/quotes.json && break
	sleep 0.25
done
exec "$@"
