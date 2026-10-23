#!/usr/bin/env bash
# Runs inside the scoring container, once the server was started and awaited:
#
#   framework_guard.sh <workdir> <port>
#
# Checks that the process listening on <port> executes the caddy binary of
# <workdir> (compared by content, so a renamed or copied binary still counts).
# Prints "framework_bypassed: false|true" (score.sh and metrics.sh read it) and
# a loud line when true. Always exits 0: the assertions run regardless.
WORK="${1:?usage: framework_guard.sh <workdir> <port>}"
PORT="${2:?usage: framework_guard.sh <workdir> <port>}"

hexport="$(printf '%04X' "$PORT")"
inodes="$(awk -v p=":$hexport" '$4 == "0A" && $2 ~ p "$" {print $10}' /proc/net/tcp /proc/net/tcp6 2>/dev/null | sort -u)"
exe=""
for inode in $inodes; do
	for fd in /proc/[0-9]*/fd/*; do
		if [ "$(readlink "$fd" 2>/dev/null)" = "socket:[$inode]" ]; then
			pid="${fd#/proc/}"
			pid="${pid%%/*}"
			exe="/proc/$pid/exe"
			break 2
		fi
	done
done

reason=""
if [ -z "$inodes" ]; then
	reason="nothing listens on :$PORT"
elif [ -z "$exe" ]; then
	reason="the process listening on :$PORT is not visible"
elif [ ! -f "$WORK/caddy" ]; then
	reason="no caddy binary in the workdir"
elif [ "$(sha256sum <"$exe" 2>/dev/null | cut -d' ' -f1)" != "$(sha256sum <"$WORK/caddy" | cut -d' ' -f1)" ]; then
	reason="the listener on :$PORT is $(readlink "$exe" 2>/dev/null || echo unknown), not the workdir caddy binary"
fi

if [ -n "$reason" ]; then
	echo "!!! FRAMEWORK BYPASSED: $reason !!!"
	echo "framework_bypassed: true"
else
	echo "framework_bypassed: false"
fi
exit 0
