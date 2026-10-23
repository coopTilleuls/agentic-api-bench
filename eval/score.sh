#!/usr/bin/env bash
# Scores an API built for eval/spec.md with the assertions of assert.sh.
#
#   eval/score.sh [--docker] [--stack runtime|nextjs|apiplatform|go|rust] [--spec v1|v2|v2.1|v3] [--start-wait SECONDS] <workdir> [caddy-binary]
#
# --spec v2 (docker mode only) scores a v2 solution: ./start.sh is started from
# <workdir> inside the image of --stack, the server is awaited on :8089, then
# eval/v2/assert.sh runs if it exists. The server is always stopped.
# --spec v2.1 scores an evolved v2 solution the same way with eval/v2.1/assert.sh,
# on the v2 seed plus eval/v2.1/migration.sql (reset_db).
#
# --spec v3 scores like v2 with eval/v3/assert.sh, which also saves the documents it
# fetched into a host temp directory (mounted at /score-out, never the workdir);
# eval/v3/validate.py then checks them (OpenAPI, JSON:API schema) in the
# network-less llmapi-eval-validator image, and its PASS/FAIL lines are appended.
# The "score:" line of the output is rewritten to count both.
#
# --start-wait (or EVAL_START_WAIT) is how long the server may take to answer
# on :8089 after ./start.sh (docker v2/v2.1/v3 modes): 30 s by default, 180 s
# for the go and rust stacks, whose start.sh may compile. The wait used and the
# time the server took are printed (start_wait / start_ready lines). The go and
# rust images keep their module/crate caches in <workdir> (.gopath, .cargo-home,
# .cache, target), and the scoring container has no network: a start.sh that
# needs a download fails.
#
# <workdir> holds the solution: a Caddyfile started with `caddy run` from that
# directory, as the spec requires. The database is reset from seed.sql first,
# so the result does not depend on what the agent did to the data.
#
# Host mode (default) runs the server and the quotes stub on this machine;
# the binary defaults to <workdir>/caddy, then to bin/caddy of the repository.
# --docker runs the server inside the eval image on the eval network, as the
# agent did (its Caddyfile points at postgres:5432).
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

DOCKER=0
STACK=runtime
SPEC=v1
START_WAIT="${EVAL_START_WAIT:-}"
while [ $# -gt 0 ]; do
	case "$1" in
	--docker) DOCKER=1 ;;
	--stack) STACK="${2:?--stack needs a value}"; shift ;;
	--spec) SPEC="${2:?--spec needs a value}"; shift ;;
	--start-wait) START_WAIT="${2:?--start-wait needs a value}"; shift ;;
	*) break ;;
	esac
	shift
done
WORK="$(cd "${1:?usage: score.sh [--docker] [--stack S] [--spec v1|v2|v2.1|v3] [--start-wait SECONDS] <workdir> [caddy-binary]}" && pwd)"
AGENT_IMAGE="$(stack_image "$STACK")" || exit 2
SPEC_DIR="$(spec_dir "$SPEC")" || exit 2
if [ -z "$START_WAIT" ]; then
	case "$STACK" in go | rust) START_WAIT=180 ;; *) START_WAIT=30 ;; esac
fi
[[ "$START_WAIT" =~ ^[1-9][0-9]*$ ]] || { echo "--start-wait must be a positive integer" >&2; exit 2; }
if [ "$SPEC" != v1 ] && [ "$DOCKER" != 1 ]; then
	echo "--spec $SPEC needs --docker" >&2
	exit 2
fi

start_postgres
reset_db

if [ "$DOCKER" = 1 ] && [ "$SPEC" != v1 ]; then
	ensure_eval_network
	SCORE_MOUNTS=()
	if [ "$SPEC" = v3 ]; then
		ensure_validator_image || exit 2
		SCORE_OUT="$(mktemp -d "${TMPDIR:-/tmp}/llmapi-score-out-XXXXXX")"
		SCORE_TXT="$(mktemp "${TMPDIR:-/tmp}/llmapi-score-txt-XXXXXX")"
		trap 'rm -rf "$SCORE_OUT" "$SCORE_TXT"' EXIT
		SCORE_MOUNTS=(-v "$SCORE_OUT:/score-out" -e ASSERT_OUT=/score-out)
	fi
	score_container() {
		docker run --rm --network "$EVAL_NET" --user "$(id -u):$(id -g)" \
			--tmpfs "/home/agent:uid=$(id -u),gid=$(id -g),mode=700" \
			-e STACK="$STACK" -e START_WAIT="$START_WAIT" "${SCORE_MOUNTS[@]}" -e SPEC_DIR_NAME="$(basename "$SPEC_DIR")" -v "$WORK:/work" -v "$EVAL_DIR:/eval:ro" "$AGENT_IMAGE" bash -c '
				echo "start_wait: ${START_WAIT}s"
				cd /work && (./start.sh >/tmp/server.log 2>&1 &)
				t0=$(date +%s)
				for _ in $(seq 1 $((START_WAIT * 4))); do curl -s -o /dev/null http://localhost:8089/ && break; sleep 0.25; done
				if curl -s -o /dev/null http://localhost:8089/; then echo "start_ready: $(( $(date +%s) - t0 ))s"; else echo "server did not start within ${START_WAIT}s; last log lines:"; tail -n 20 /tmp/server.log; fi
				[ "$STACK" = runtime ] && bash /eval/framework_guard.sh /work 8089
				if [ -f "/eval/$SPEC_DIR_NAME/assert.sh" ]; then
					bash "/eval/$SPEC_DIR_NAME/assert.sh" http://localhost:8089 /work
					rc=$?
				else
					echo "no assertions yet for $SPEC_DIR_NAME"
					rc=0
				fi
				exit $rc'
	}
	if [ "$SPEC" != v3 ]; then
		score_container
		exit $?
	fi
	score_container >"$SCORE_TXT"
	rc=$?
	vrc=0
	VAL_TXT="$(docker run --rm --network none -v "$SCORE_OUT:/in:ro" -v "$EVAL_DIR/v3:/v3:ro" "$VALIDATOR_IMAGE" python /v3/validate.py /in)" || vrc=$?
	VAL_TXT="$VAL_TXT" awk '
		BEGIN { val = ENVIRON["VAL_TXT"] }
		/^score: [0-9]+ passed, [0-9]+ failed/ && !done {
			p = $2; f = $4
			n = split(val, l, "\n")
			for (i = 1; i <= n; i++) { if (l[i] ~ /^PASS  /) p++; else if (l[i] ~ /^FAIL  /) f++ }
			print val; print "score: " p " passed, " f " failed"; done = 1; next }
		{ print }
		END { if (!done) print val }' "$SCORE_TXT"
	[ "$rc" -eq 0 ] && [ "$vrc" -eq 0 ] || rc=1
	exit $rc
fi

if [ "$DOCKER" = 1 ]; then
	ensure_eval_network
	docker run --rm --network "$EVAL_NET" --user "$(id -u):$(id -g)" \
		--tmpfs "/home/agent:uid=$(id -u),gid=$(id -g),mode=700" \
		-e STACK="$STACK" -v "$WORK:/work" -v "$EVAL_DIR:/eval:ro" "$AGENT_IMAGE" bash -c '
			cd /work && (./caddy run >/tmp/caddy.log 2>&1 &)
			for _ in $(seq 1 80); do curl -s -o /dev/null http://localhost:8089/ && break; sleep 0.25; done
			curl -s -o /dev/null http://localhost:8089/ || { echo "server did not start; last log lines:"; tail -n 20 /tmp/caddy.log; }
			[ "$STACK" = runtime ] && bash /eval/framework_guard.sh /work 8089
			/eval/assert.sh http://localhost:8089 /work'
	exit $?
fi

BIN="${2:-}"
if [ -z "$BIN" ]; then
	if [ -x "$WORK/caddy" ]; then BIN="$WORK/caddy"; else BIN="$REPO_DIR/bin/caddy"; fi
fi
BASE="http://localhost:$API_PORT"
LOG="$(mktemp "${TMPDIR:-/tmp}/llmapi-score-XXXXXX.log")"

cleanup() {
	[ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
	stop_stub
	wait 2>/dev/null
	rm -f "$LOG"
}
trap cleanup EXIT

start_stub "$BIN" || echo "warning: quotes stub did not start"
stop_port "$API_PORT"
(cd "$WORK" && DATABASE_URL="$EVAL_DSN" exec "$BIN" run >"$LOG" 2>&1) &
SERVER_PID=$!
if ! wait_http "$BASE/" 20; then
	echo "server did not start on $BASE; last log lines:"
	tail -n 20 "$LOG"
fi
"$EVAL_DIR/assert.sh" "$BASE" "$WORK"
