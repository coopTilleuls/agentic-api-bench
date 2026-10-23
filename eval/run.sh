#!/usr/bin/env bash
# Runs the blank-agent eval: `claude -p` in a container with /work holding only
# the caddy binary (--stack runtime) or nothing (baselines), the spec as the task.
#
#   eval/run.sh [--stack runtime|nextjs|nestjs|apiplatform|laravel|django|go|rust] [--spec v1|v2|v3]
#               [--mcp|--no-mcp] [--scaffolded|--no-scaffolded] [--context none|agents-md|skillset|shipped | --context-dir <dir>]
#               [--apip-ref <sha>]
#               [--model MODEL] [--max-turns N] [--smoke] [--api-key]
#   eval/run.sh --task evolve --from <source run dir>
#               [--model MODEL] [--max-turns N] [--smoke] [--api-key]
#
# --stack defaults to runtime and --spec to v1 (eval/spec.md; v2 is
# eval/v2/spec.md; v3 is eval/v3/spec.md, v2 behind a JSON-LD/Hydra representation). --mcp|--no-mcp is required for the runtime stack and
# refused for the baselines (nextjs, apiplatform, go, rust: Next.js, API Platform
# on Symfony, plain Go or plain Rust, built with that ecosystem's usual tools;
# v2 and v3 only; no MCP server).
# laravel (Laravel + laravel-json-api/laravel, Composer) and django (Django, Django
# REST framework + djangorestframework-jsonapi, pip in a virtualenv) are further
# baselines, spec v3 only.
# --scaffolded (default for --spec v3 on apiplatform, laravel and django, refused
# elsewhere; --no-scaffolded turns it off): the workdir already holds the stack's
# project with its dependencies installed, created before the agent starts in the
# stack image through the proxy with the commands of scaffold.sh (scaffold_cmd in
# lib.sh), logged in install.log; the resolved top-level versions go to versions.txt.
# --context shipped (laravel only): nothing is added, Claude Code loads the CLAUDE.md the
# Laravel skeleton ships (the laravel-boost bootstrap guidelines; sha256 of it and of
# AGENTS.md in context_rev); on laravel --context none leaves it unloaded.
# --context (spec v3, --scaffolded; default none) adds a context to the agent. A
# context is a directory: its files (AGENTS.md, CLAUDE.md...) are copied to the
# workdir root after the scaffold and project CLAUDE.md loading is switched on for
# the run (CLAUDE_CODE_DISABLE_CLAUDE_MDS=0); its optional plugin/ subdirectory
# (.claude-plugin/plugin.json, skills/) is mounted read-only at /opt/context-plugin
# and loaded with --plugin-dir. The name is recorded in context, its commit and
# tree hash in context_rev. --context-dir <dir> takes any such directory (any
# scaffolded stack; the run name suffix is the directory name); on apiplatform two
# shortcuts build it from the official API Platform skillset
# (~/projects/api-platform-skillset, or $SKILLSET_DIR, at its HEAD):
#   agents-md  its AGENTS.md and CLAUDE.md (@AGENTS.md)
#   skillset   agents-md plus its plugin (.claude-plugin/, skills/)
# --apip-ref <sha> (apiplatform, spec v3, --scaffolded only): after the scaffold, switch API Platform to the
# api-platform/core monorepo at that commit of main (scaffold_apip_ref_cmd in lib.sh; downloaded as a dist
# from api.github.com, already allowed) and set api_platform.jsonapi.strict_member_names: true, because the
# fix is opt-in and without it the `_id` bug stays. Recorded in apip_ref and, as api-platform/core@ref, in
# versions.txt; the run name gets the suffix -patched.
# The apiplatform proxy of spec v3 also allows api-platform.com (what the AGENTS.md
# links to), whatever the context; only the files differ between the conditions.
# The runtime stack always runs bin/caddy-eval, built by build_binary (no host
# paths), never the bin/caddy of the README build. Each stack has its own image
# and its own proxy allowlist (eval/docker/proxy/filter-<stack>).
# The model defaults to claude-sonnet-5-5, --max-turns to 80; both, the stack
# and the spec are recorded in the run dir.
# --task evolve (spec v2.1, eval/v2.1/): a fresh agent gets the code a v2 run
# produced and applies the change request of eval/v2.1/change-request.md.
# Stack and, for the runtime, the MCP variant come from the source run dir
# (stack file; init.json lists the MCP server). The workdir is a copy of the
# source run's code/ directory (the workdir file's directory only when code/ is
# absent; the source is never modified). For nextjs and apiplatform the
# dependencies (npm ci / npm install, composer install) are installed before
# the agent starts, in the stack image through the proxy, logged in
# install.log (go: go mod download, rust: cargo fetch, into the workdir caches
# .gopath / .cargo-home, which the go and rust images keep in /work); for the runtime the current clean eval binary replaces ./caddy.
# The database is the v2 seed plus eval/v2.1/migration.sql. The prompt is the change request, then the v2 spec
# exactly as the source agent saw it (taken from the source prompt.txt), then
# the stack footer. Results land in <timestamp>-v2.1-<stack>[-<variant>]/ with
# the files task and source_run; metrics.sh then reports the lines changed
# against the source run's code.
# Every run archives its workdir into <run dir>/code/ once the agent is done
# (before scoring; without the caddy binary, node_modules, vendor, var, .next,
# public/bundles, .git, target, .gopath, .cache, .cargo-home): the workdir is a temporary directory, code/ is the
# evidence.
# Isolation: image with the claude CLI only, HOME on an empty tmpfs, /work as
# the only mount, an internal network reaching Postgres, and the internet only
# through a proxy allowing Anthropic hosts. Auth is the subscription OAuth
# token: CLAUDE_CODE_OAUTH_TOKEN, else ~/.config/agentic-api-bench/eval-token (mode
# 600); ANTHROPIC_API_KEY (API billing) is refused unless --api-key is given.
# --provider alibaba runs the same CLI against Alibaba Cloud Model Studio's
# Anthropic-compatible endpoint (Singapore) with a non-Claude --model
# (deepseek-*, kimi-*, qwen*): the key comes from ~/.config/agentic-api-bench/alibaba-key
# (mode 600) as ANTHROPIC_AUTH_TOKEN, the proxy also allows that host, and every
# model alias of the CLI points at --model. The CLI's cost figure prices those
# tokens as Claude's and is meaningless there; use the token counts.
# Credentials are passed by variable name, never on a command line.
#
# The run is rejected unless the stream-json init event lists no skill, no
# plugin except cc-plugin-sec-default@builtin (which no setting switches off),
# memory paths only inside the empty HOME, the expected auth source and model,
# and exactly the expected MCP servers, connected. --context also accepts the
# context files in /work as memory paths and requires exactly its plugin and
# skills (none when it has no plugin); anything else still rejects the run. --smoke sends a trivial
# prompt and only checks the init event. Results land in
# eval/results/<timestamp>-<variant>/.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

VARIANT=""
STACK=runtime
SPEC=v1
TASK=build
FROM=""
STACK_SET=0
SPEC_SET=0
MAX_TURNS=80
MODEL=claude-sonnet-5-5
SMOKE=0
USE_API_KEY=0
PROVIDER=anthropic
SCAFFOLDED=""
CONTEXT=none
CONTEXT_DIR_ARG=""
APIP_REF=""
while [ $# -gt 0 ]; do
	case "$1" in
	--mcp) VARIANT=mcp ;;
	--no-mcp) VARIANT=no-mcp ;;
	--stack) STACK="${2:?--stack needs a value}"; STACK_SET=1; shift ;;
	--spec) SPEC="${2:?--spec needs a value}"; SPEC_SET=1; shift ;;
	--task) TASK="${2:?--task needs a value}"; shift ;;
	--from) FROM="${2:?--from needs a value}"; shift ;;
	--max-turns) MAX_TURNS="${2:?--max-turns needs a value}"; shift ;;
	--model) MODEL="$2"; shift ;;
	--smoke) SMOKE=1 ;;
	--api-key) USE_API_KEY=1 ;;
	--provider) PROVIDER="${2:?--provider needs a value}"; shift ;;
	--scaffolded) SCAFFOLDED=1 ;;
	--no-scaffolded) SCAFFOLDED=0 ;;
	--context) CONTEXT="${2:?--context needs a value}"; shift ;;
	--context-dir) CONTEXT_DIR_ARG="${2:?--context-dir needs a value}"; shift ;;
	--apip-ref) APIP_REF="${2:?--apip-ref needs a value}"; shift ;;
	*) echo "unknown argument $1" >&2; exit 2 ;;
	esac
	shift
done
USAGE="usage: run.sh [--stack runtime|nextjs|nestjs|apiplatform|laravel|django|go|rust] [--spec v1|v2|v3] [--mcp|--no-mcp] [--scaffolded|--no-scaffolded] [--context none|agents-md|skillset|shipped | --context-dir <dir>] [--apip-ref <sha>] [--model MODEL] [--max-turns N] [--smoke] [--api-key] | --task evolve --from <source run dir> [--model MODEL] [--max-turns N] [--smoke] [--api-key]"
case "$TASK" in build | evolve) ;; *) echo "unknown task $TASK. $USAGE" >&2; exit 2 ;; esac
SOURCE_WORK=""
SOURCE_SPEC_TEXT=""
if [ "$TASK" = evolve ]; then
	[ -n "$FROM" ] || { echo "--task evolve needs --from <source run dir>. $USAGE" >&2; exit 2; }
	[ "$STACK_SET$SPEC_SET" = 00 ] && [ -z "$VARIANT" ] || { echo "--task evolve takes stack and variant from the source run: no --stack, --spec, --mcp or --no-mcp. $USAGE" >&2; exit 2; }
	FROM="$(cd "$FROM" 2>/dev/null && pwd)" || { echo "source run dir not found. $USAGE" >&2; exit 2; }
	for f in stack spec prompt.txt init.json; do
		[ -f "$FROM/$f" ] || { echo "source run $FROM has no $f" >&2; exit 2; }
	done
	[ "$(cat "$FROM/spec")" = v2 ] || { echo "source run must be a v2 run (spec is $(cat "$FROM/spec"))" >&2; exit 2; }
	[ ! -f "$FROM/timed_out" ] || echo "warning: the source run timed out" >&2
	STACK="$(cat "$FROM/stack")"
	SPEC=v2.1
	if [ "$STACK" = runtime ]; then
		if [ "$(jq -r '[.mcp_servers[]?.name] | length' "$FROM/init.json")" -gt 0 ]; then VARIANT=mcp; else VARIANT=no-mcp; fi
	fi
	if ! SOURCE_WORK="$(source_code_dir "$FROM")"; then
		SOURCE_WORK=""
		[ "$SMOKE" = 1 ] || { echo "source run $FROM has no code/ directory and its workdir no longer exists" >&2; exit 2; }
		echo "warning: source run $FROM has no code/ directory and its workdir no longer exists; smoke run with an empty one" >&2
	fi
	# The spec as the source agent saw it: the prompt up to the "---" line before the stack footer.
	SOURCE_SPEC_TEXT="$(awk '$0 == "---" {exit} {print}' "$FROM/prompt.txt")"
	[ -n "$SOURCE_SPEC_TEXT" ] || { echo "cannot extract the spec from $FROM/prompt.txt" >&2; exit 2; }
fi
case "$STACK" in runtime | nextjs | nestjs | apiplatform | laravel | django | go | rust) ;; *) echo "unknown stack $STACK. $USAGE" >&2; exit 2 ;; esac
case "$SPEC" in v1 | v2 | v2.1 | v3) ;; *) echo "unknown spec $SPEC. $USAGE" >&2; exit 2 ;; esac
[[ "$MAX_TURNS" =~ ^[1-9][0-9]*$ ]] || { echo "--max-turns must be a positive integer. $USAGE" >&2; exit 2; }
if [ "$TASK" = evolve ]; then
	:
elif [ "$STACK" = runtime ]; then
	[ -n "$VARIANT" ] || { echo "--stack runtime needs --mcp or --no-mcp. $USAGE" >&2; exit 2; }
else
	case "$STACK" in
	nestjs | laravel | django) [ "$SPEC" = v3 ] || { echo "the $STACK baseline only runs with --spec v3. $USAGE" >&2; exit 2; } ;;
	*) [ "$SPEC" = v2 ] || [ "$SPEC" = v3 ] || { echo "the $STACK baseline only runs with --spec v2 or v3. $USAGE" >&2; exit 2; } ;;
	esac
	[ -z "$VARIANT" ] || { echo "--mcp/--no-mcp only applies to --stack runtime: baselines have no MCP server. $USAGE" >&2; exit 2; }
	VARIANT="$STACK"
fi

case "$CONTEXT" in none | agents-md | skillset | shipped) ;; *) echo "unknown context $CONTEXT. $USAGE" >&2; exit 2 ;; esac
CONTEXT_SRC=""
if [ -n "$CONTEXT_DIR_ARG" ]; then
	[ "$CONTEXT" = none ] || { echo "--context and --context-dir are exclusive. $USAGE" >&2; exit 2; }
	CONTEXT_SRC="$(cd "$CONTEXT_DIR_ARG" 2>/dev/null && pwd)" || { echo "context dir $CONTEXT_DIR_ARG not found. $USAGE" >&2; exit 2; }
	CONTEXT="$(basename "$CONTEXT_SRC")"
	case "$CONTEXT" in none | agents-md | skillset | shipped) echo "the context dir name $CONTEXT is reserved. $USAGE" >&2; exit 2 ;; esac
	[ -z "$(find "$CONTEXT_SRC" -mindepth 1 -maxdepth 1 ! -name plugin -print -quit)" ] && [ ! -d "$CONTEXT_SRC/plugin" ] && { echo "context dir $CONTEXT_SRC is empty" >&2; exit 2; }
	[ ! -d "$CONTEXT_SRC/plugin" ] || [ -f "$CONTEXT_SRC/plugin/.claude-plugin/plugin.json" ] || { echo "$CONTEXT_SRC/plugin has no .claude-plugin/plugin.json" >&2; exit 2; }
fi
CAN_SCAFFOLD=0
if [ "$TASK" = build ] && [ "$SPEC" = v3 ]; then
	case "$STACK" in apiplatform | nestjs | laravel | django) CAN_SCAFFOLD=1 ;; esac
fi
if [ "$CAN_SCAFFOLD" = 1 ]; then
	[ -n "$SCAFFOLDED" ] || SCAFFOLDED=1
else
	[ -z "$SCAFFOLDED" ] || { echo "--scaffolded/--no-scaffolded only applies to --spec v3 on apiplatform, nestjs, laravel and django. $USAGE" >&2; exit 2; }
	SCAFFOLDED=0
fi
if [ -n "$APIP_REF" ]; then
	[ "$STACK" = apiplatform ] && [ "$CAN_SCAFFOLD" = 1 ] && [ "$SCAFFOLDED" = 1 ] || { echo "--apip-ref only applies to --stack apiplatform --spec v3 --scaffolded. $USAGE" >&2; exit 2; }
	[[ "$APIP_REF" =~ ^[0-9a-f]{7,40}$ ]] || { echo "--apip-ref takes a commit sha of api-platform/core main (7 to 40 hex digits). $USAGE" >&2; exit 2; }
fi
if [ "$CONTEXT" = shipped ]; then
	[ "$STACK" = laravel ] && [ "$CAN_SCAFFOLD" = 1 ] || { echo "--context shipped only applies to --stack laravel --spec v3. $USAGE" >&2; exit 2; }
	[ "$SCAFFOLDED" = 1 ] || { echo "--context shipped needs --scaffolded. $USAGE" >&2; exit 2; }
elif [ -n "$CONTEXT_SRC" ]; then
	[ "$CAN_SCAFFOLD" = 1 ] && [ "$SCAFFOLDED" = 1 ] || { echo "--context-dir needs --spec v3 --scaffolded on apiplatform, nestjs, laravel or django. $USAGE" >&2; exit 2; }
elif [ "$CONTEXT" != none ]; then
	[ "$STACK" = apiplatform ] && [ "$CAN_SCAFFOLD" = 1 ] || { echo "--context $CONTEXT only applies to --stack apiplatform --spec v3. $USAGE" >&2; exit 2; }
	[ "$SCAFFOLDED" = 1 ] || { echo "--context $CONTEXT needs --scaffolded. $USAGE" >&2; exit 2; }
	SKILLSET_DIR="${SKILLSET_DIR:-$HOME/projects/api-platform-skillset}"
	CONTEXT_REV="$(git -C "$SKILLSET_DIR" rev-parse HEAD 2>/dev/null)" || { echo "$SKILLSET_DIR is not the api-platform-skillset git repository (set SKILLSET_DIR)" >&2; exit 2; }
fi

AUTH_VAR=CLAUDE_CODE_OAUTH_TOKEN
EXPECTED_KEY_SOURCE=none
case "$PROVIDER" in
anthropic) ;;
alibaba)
	[ "$USE_API_KEY" = 0 ] || { echo "--api-key and --provider alibaba are exclusive" >&2; exit 2; }
	case "$MODEL" in claude-*) echo "--provider alibaba needs a non-Claude --model (deepseek-*, kimi-*, qwen*)" >&2; exit 2 ;; esac
	KEY_FILE="$HOME/.config/agentic-api-bench/alibaba-key"
	[ -f "$KEY_FILE" ] || { echo "no Alibaba key: create $KEY_FILE (mode 600)" >&2; exit 1; }
	[ "$(stat -c %a "$KEY_FILE")" = 600 ] || { echo "$KEY_FILE must have mode 600" >&2; exit 1; }
	ANTHROPIC_AUTH_TOKEN="$(tr -d '[:space:]' <"$KEY_FILE")"
	export ANTHROPIC_AUTH_TOKEN
	unset CLAUDE_CODE_OAUTH_TOKEN ANTHROPIC_API_KEY
	AUTH_VAR=ANTHROPIC_AUTH_TOKEN
	# The CLI reports apiKeySource "none" for ANTHROPIC_AUTH_TOKEN; the model check below is what proves the endpoint.
	EXPECTED_KEY_SOURCE=none
	;;
*) echo "unknown provider $PROVIDER (anthropic|alibaba)" >&2; exit 2 ;;
esac
if [ "$PROVIDER" = alibaba ]; then
	:
elif [ "$USE_API_KEY" = 1 ]; then
	[ -n "${ANTHROPIC_API_KEY:-}" ] || { echo "--api-key given but ANTHROPIC_API_KEY is not set" >&2; exit 1; }
	AUTH_VAR=ANTHROPIC_API_KEY
	EXPECTED_KEY_SOURCE=ANTHROPIC_API_KEY
	unset CLAUDE_CODE_OAUTH_TOKEN
else
	if [ -n "${ANTHROPIC_API_KEY:-}" ]; then
		echo "ANTHROPIC_API_KEY is set: refusing, the eval runs on the subscription OAuth token. Unset it, or pass --api-key to bill the API." >&2
		exit 1
	fi
	if [ -z "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]; then
		TOKEN_FILE="$HOME/.config/agentic-api-bench/eval-token"
		if [ ! -f "$TOKEN_FILE" ]; then
			echo "no credentials: set CLAUDE_CODE_OAUTH_TOKEN or create $TOKEN_FILE (mode 600) with a token from 'claude setup-token'" >&2
			exit 1
		fi
		if [ "$(stat -c %a "$TOKEN_FILE")" != 600 ]; then
			echo "$TOKEN_FILE must have mode 600 (chmod 600 $TOKEN_FILE)" >&2
			exit 1
		fi
		CLAUDE_CODE_OAUTH_TOKEN="$(tr -d '[:space:]' <"$TOKEN_FILE")"
		export CLAUDE_CODE_OAUTH_TOKEN
	fi
fi

AGENT_IMAGE="$(stack_image "$STACK")"
SPEC_DIR="$(spec_dir "$SPEC")"

STAMP="$(date +%Y%m%d-%H%M%S)"
if [ "$TASK" = evolve ]; then
	RUN_NAME="$STAMP-v2.1-$STACK"
	[ "$STACK" = runtime ] && RUN_NAME="$RUN_NAME-$VARIANT"
elif [ "$STACK" = runtime ]; then
	RUN_NAME="$STAMP-$VARIANT"
	[ "$SPEC" = v2 ] && RUN_NAME="$STAMP-v2-$VARIANT"
	[ "$SPEC" = v3 ] && RUN_NAME="$STAMP-v3-$VARIANT"
else
	RUN_NAME="$STAMP-$SPEC-$STACK"
	[ "$CONTEXT" != none ] && RUN_NAME="$RUN_NAME-$CONTEXT"
	[ -n "$APIP_REF" ] && RUN_NAME="$RUN_NAME-patched"
fi
RESULTS="$EVAL_DIR/results/$RUN_NAME"
[ "$SMOKE" = 1 ] && RESULTS="$RESULTS-smoke"
mkdir -p "$RESULTS"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/llmapi-eval-XXXXXX")"
if [ "$TASK" = evolve ] && [ -n "$SOURCE_WORK" ]; then
	cp -a "$SOURCE_WORK/." "$WORK/"
	rm -f "$WORK/caddy"
fi
if [ "$STACK" = runtime ]; then
	BIN="$REPO_DIR/bin/caddy-eval"
	build_binary "$BIN" >"$RESULTS/build.log" 2>&1 || { echo "binary build failed, see $RESULTS/build.log" >&2; exit 1; }
	cp "$BIN" "$WORK/caddy"
	check_binary_clean "$WORK/caddy"
fi
echo "$WORK" >"$RESULTS/workdir"
echo "$MODEL" >"$RESULTS/model"
echo "$PROVIDER" >"$RESULTS/provider"
echo "$STACK" >"$RESULTS/stack"
echo "$SPEC" >"$RESULTS/spec"
echo "$TASK" >"$RESULTS/task"
[ "$TASK" = evolve ] && echo "$FROM" >"$RESULTS/source_run"
echo "$MAX_TURNS" >"$RESULTS/max_turns"
if [ "$CAN_SCAFFOLD" = 1 ]; then
	echo "$CONTEXT" >"$RESULTS/context"
	echo "$SCAFFOLDED" >"$RESULTS/scaffolded"
	[ -n "$APIP_REF" ] && echo "$APIP_REF" >"$RESULTS/apip_ref"
fi

build_images "$STACK" "$PROVIDER"
start_postgres
reset_db
ensure_eval_network
start_proxy "$STACK" "$PROVIDER"
if [ "$TASK" = evolve ]; then
	install_dependencies "$WORK" "$STACK" "$RESULTS/install.log" || { echo "dependency install failed, see $RESULTS/install.log" >&2; exit 1; }
fi
CONTAINER="llmapi-eval-agent-$STAMP"
cleanup() {
	docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
	stop_mcp_sidecar
	stop_proxy
	[ -n "${CONTEXT_TMP:-}" ] && rm -rf "$CONTEXT_TMP"
	# code/ is the evidence; the workdir only lasts until it is archived.
	# chmod: the Go module cache is read-only, which makes a bare rm -rf fail.
	[ -d "$RESULTS/code" ] && { chmod -R u+w "$WORK" 2>/dev/null; rm -rf "$WORK"; }
	return 0
}
trap cleanup EXIT

if [ "$SCAFFOLDED" = 1 ]; then
	prescaffold_workdir "$WORK" "$STACK" "$RESULTS/install.log" "$RESULTS/versions.txt" "$APIP_REF" || { echo "scaffold failed, see $RESULTS/install.log" >&2; rm -rf "$WORK"; exit 1; }
fi
PLUGIN_DIR=""
CONTEXT_FILES=()
if [ "$CONTEXT" = shipped ]; then
	(cd "$WORK" && sha256sum AGENTS.md CLAUDE.md) >"$RESULTS/context_rev" || { echo "the scaffold has no AGENTS.md/CLAUDE.md" >&2; rm -rf "$WORK"; exit 1; }
	CONTEXT_FILES=(AGENTS.md CLAUDE.md)
elif [ "$CONTEXT" != none ]; then
	if [ -z "$CONTEXT_SRC" ]; then
		# agents-md and skillset: the files of the recorded commit of the skillset, laid out as a context dir.
		CONTEXT_TMP="$(mktemp -d "${TMPDIR:-/tmp}/llmapi-context-XXXXXX")"
		CONTEXT_SRC="$CONTEXT_TMP/src"
		mkdir "$CONTEXT_SRC"
		git -C "$SKILLSET_DIR" archive "$CONTEXT_REV" AGENTS.md CLAUDE.md .claude-plugin skills | tar -xf - -C "$CONTEXT_TMP"
		mv "$CONTEXT_TMP/AGENTS.md" "$CONTEXT_TMP/CLAUDE.md" "$CONTEXT_SRC/"
		if [ "$CONTEXT" = skillset ]; then
			mkdir "$CONTEXT_SRC/plugin"
			mv "$CONTEXT_TMP/.claude-plugin" "$CONTEXT_TMP/skills" "$CONTEXT_SRC/plugin/"
		fi
		echo "$CONTEXT_REV" >"$RESULTS/context_rev"
	else
		{ git -C "$CONTEXT_SRC" rev-parse HEAD 2>/dev/null || true; (cd "$CONTEXT_SRC" && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1); } >"$RESULTS/context_rev"
	fi
	while IFS= read -r -d '' f; do CONTEXT_FILES+=("$f"); done < <(cd "$CONTEXT_SRC" && find . -mindepth 1 -maxdepth 1 ! -name plugin -printf '%f\0')
	printf '%s\n' "${CONTEXT_FILES[@]}" >"$RESULTS/context_files"
	[ "${#CONTEXT_FILES[@]}" -eq 0 ] || cp -a "${CONTEXT_FILES[@]/#/$CONTEXT_SRC/}" "$WORK/"
	[ ! -d "$CONTEXT_SRC/plugin" ] || PLUGIN_DIR="$CONTEXT_SRC/plugin"
fi

SCAFFOLD_NOTE=""
if [ "$SCAFFOLDED" = 1 ]; then
	case "$STACK" in
	apiplatform) SCAFFOLD_NOTE="The project is already created in this directory with API Platform installed." ;;
	nestjs) SCAFFOLD_NOTE="The project is already created in this directory with NestJS installed." ;;
	laravel) SCAFFOLD_NOTE="The project is already created in this directory with Laravel installed." ;;
	django) SCAFFOLD_NOTE="The project is already created in this directory with Django installed in the virtualenv .venv." ;;
	esac
fi
case "$STACK/$SPEC" in
runtime/v1) FOOTER="The only tool you have is the ./caddy binary in this directory. PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./caddy run from this directory and tested over HTTP." ;;
runtime/v2 | runtime/v2.1 | runtime/v3) FOOTER="The only tool you have is the ./caddy binary in this directory. PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
nextjs/v2 | nextjs/v2.1 | nextjs/v3) FOOTER="Build it with Next.js (App Router route handlers). Node.js 22 and npm are available and the npm registry is reachable. PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
apiplatform/v2 | apiplatform/v2.1) FOOTER="Build it with API Platform on Symfony. PHP 8.4, Composer and the Symfony CLI are available and Packagist is reachable. PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
apiplatform/v3) FOOTER="Build it with API Platform on Symfony. PHP 8.4, Composer and the Symfony CLI are available and Packagist is reachable.${SCAFFOLD_NOTE:+ $SCAFFOLD_NOTE} PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
nestjs/v3) FOOTER="Build it with NestJS. Node.js 22 and npm are available and the npm registry is reachable.${SCAFFOLD_NOTE:+ $SCAFFOLD_NOTE} PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
laravel/v3) FOOTER="Build it with Laravel (JSON:API through the laravel-json-api/laravel package). PHP 8.4 and Composer are available and Packagist is reachable.${SCAFFOLD_NOTE:+ $SCAFFOLD_NOTE} PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
django/v3) FOOTER="Build it with Django (Django REST framework and djangorestframework-jsonapi). Python 3 and pip are available and PyPI is reachable.${SCAFFOLD_NOTE:+ $SCAFFOLD_NOTE} PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
go/v2 | go/v2.1 | go/v3) FOOTER="Build it in Go. The Go toolchain is installed and the Go module proxy is reachable, so you may use any module. PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
rust/v2 | rust/v2.1 | rust/v3) FOOTER="Build it in Rust. The stable Rust toolchain with cargo is installed and crates.io is reachable, so you may use any crate. PostgreSQL DSN: $DOCKER_DSN
Work in this directory. When you are done, leave the API stopped; it will be started with ./start.sh from this directory and tested over HTTP." ;;
esac
if [ "$TASK" = evolve ]; then
	PROMPT="$(cat "$SPEC_DIR/change-request.md")

---

$SOURCE_SPEC_TEXT

---

$FOOTER"
else
	PROMPT="$(cat "$(prompt_file "$SPEC")")

---

$FOOTER"
fi

DOCKER_ENV=(-e "$AUTH_VAR" -e HTTPS_PROXY=http://proxy:8888 -e HTTP_PROXY=http://proxy:8888 -e NO_PROXY=localhost,127.0.0.1,postgres,framework-mcp)
if [ "$PROVIDER" = alibaba ]; then
	DOCKER_ENV+=(-e ANTHROPIC_BASE_URL=https://dashscope-intl.aliyuncs.com/apps/anthropic
		-e "ANTHROPIC_DEFAULT_HAIKU_MODEL=$MODEL" -e "ANTHROPIC_DEFAULT_SONNET_MODEL=$MODEL"
		-e "ANTHROPIC_DEFAULT_OPUS_MODEL=$MODEL" -e "CLAUDE_CODE_SUBAGENT_MODEL=$MODEL")
fi
if [ "$STACK" != runtime ]; then
	# Composer, git and curl-based tools read the lowercase variants.
	DOCKER_ENV+=(-e https_proxy=http://proxy:8888 -e http_proxy=http://proxy:8888 -e no_proxy=localhost,127.0.0.1,postgres)
fi
CLAUDE_ARGS=(--output-format stream-json --verbose --permission-mode bypassPermissions --strict-mcp-config --model "$MODEL" --max-turns "$MAX_TURNS")
EXPECTED_MCP='[]'
PLUGIN_MOUNT=()
if [ "$CONTEXT" != none ]; then
	DOCKER_ENV+=(-e CLAUDE_CODE_DISABLE_CLAUDE_MDS=0)
fi
if [ -n "$PLUGIN_DIR" ]; then
	PLUGIN_MOUNT=(-v "$PLUGIN_DIR:/opt/context-plugin:ro")
	CLAUDE_ARGS+=(--plugin-dir /opt/context-plugin)
fi

if [ "$STACK" != runtime ]; then
	:
elif [ "$VARIANT" = mcp ]; then
	# Claude Code connects MCP servers at startup and retries a failed HTTP
	# server only a few times, so the agent's own server (started minutes
	# later) cannot be the one in --mcp-config. A framework MCP server is
	# started before claude instead, in a sidecar container (so its command
	# line is not visible to the agent) over the same ./resources directory.
	PROMPT="$PROMPT
An MCP server for this framework is connected to this session (\"caddy-resource\"). Once your API runs, it also serves MCP at http://localhost:$API_PORT/mcp."
	start_mcp_sidecar "$WORK"
	CLAUDE_ARGS+=(--mcp-config "{\"mcpServers\":{\"caddy-resource\":{\"type\":\"http\",\"url\":\"http://$MCP_ALIAS:8090/mcp\"}}}")
	EXPECTED_MCP='["caddy-resource"]'
else
	DOCKER_ENV+=(-e LLMAPI_MCP=off)
fi
if [ "$SMOKE" = 1 ]; then
	PROMPT="Reply with the single word ok."
fi
echo "$PROMPT" >"$RESULTS/prompt.txt"

TRANSCRIPT="$RESULTS/transcript.jsonl"
echo "running $STACK/$SPEC ($VARIANT) agent in container $CONTAINER, /work = $WORK"
docker run --name "$CONTAINER" --network "$EVAL_NET" \
	--user "$(id -u):$(id -g)" --tmpfs "/home/agent:uid=$(id -u),gid=$(id -g),mode=700" \
	-v "$WORK:/work" "${PLUGIN_MOUNT[@]}" "${DOCKER_ENV[@]}" "$AGENT_IMAGE" \
	claude -p "$PROMPT" "${CLAUDE_ARGS[@]}" >"$TRANSCRIPT" 2>"$RESULTS/claude.stderr" &
AGENT_PID=$!
START_TS=$(date +%s)
# A run that is still going after EVAL_TIMEOUT seconds (default 45 min) is
# stopped; it is scored as it is.
( sleep "${EVAL_TIMEOUT:-2700}" && docker rm -f "$CONTAINER" >/dev/null 2>&1 && echo timeout >"$RESULTS/timed_out" ) &
WATCHDOG=$!

# Check the init event as soon as it is written and stop a contaminated run.
INIT="$RESULTS/init.json"
for _ in $(seq 1 240); do
	if jq -c 'select(.type == "system" and .subtype == "init")' "$TRANSCRIPT" 2>/dev/null | head -1 >"$INIT" && [ -s "$INIT" ]; then
		break
	fi
	kill -0 "$AGENT_PID" 2>/dev/null || break
	sleep 0.5
done
if [ ! -s "$INIT" ]; then
	wait "$AGENT_PID" || true
	echo "REJECTED: no init event (see $RESULTS/claude.stderr)" | tee "$RESULTS/verdict.txt"
	exit 1
fi
# --context: the memory paths the context files may add, the plugin (source
# <name>@inline, mounted at /opt/context-plugin) and its skills, exactly.
ALLOWED_MEMORY='[]'
EXPECTED_PLUGINS='[]'
EXPECTED_SKILLS='[]'
if [ "${#CONTEXT_FILES[@]}" -gt 0 ]; then
	ALLOWED_MEMORY="$(printf '/work/%s\n' "${CONTEXT_FILES[@]}" | jq -R . | jq -sc .)"
fi
if [ -n "$PLUGIN_DIR" ]; then
	PLUGIN_NAME="$(jq -r .name "$PLUGIN_DIR/.claude-plugin/plugin.json")"
	EXPECTED_PLUGINS="$(jq -nc --arg p "$PLUGIN_NAME@inline" '[$p]')"
	EXPECTED_SKILLS="$(find "$PLUGIN_DIR/skills" -mindepth 1 -maxdepth 1 -type d -printf "$PLUGIN_NAME:%f\n" 2>/dev/null | jq -R . | jq -sc 'sort')"
fi
PROBLEMS="$(jq -r --argjson want "$EXPECTED_MCP" --arg keysource "$EXPECTED_KEY_SOURCE" --arg model "$MODEL" \
	--argjson allowmem "$ALLOWED_MEMORY" --argjson wantplugins "$EXPECTED_PLUGINS" --argjson wantskills "$EXPECTED_SKILLS" '
	[ ((.plugins // [])[] | select(.source != "cc-plugin-sec-default@builtin" and (.source | IN($wantplugins[]) | not)) | "plugin loaded: \(.source // .name)"),
	  (if ($wantplugins | length) > 0 and ((.plugins // []) | map(.source) | index($wantplugins[0]) == null) then "plugin missing: \($wantplugins[0])" else empty end),
	  (if ((.skills // []) | sort) != $wantskills then "skills loaded: \((.skills // []) | join(", ")), expected: \($wantskills | join(", "))" else empty end),
	  (if .apiKeySource != $keysource then "apiKeySource is \(.apiKeySource), expected \($keysource)" else empty end),
	  (if .model != $model then "model is \(.model), expected \($model)" else empty end),
	  ((.memory_paths // {}) | to_entries[] | select((.value | startswith("/home/agent/") | not) and (.value | IN($allowmem[]) | not)) | "memory path outside the empty HOME: \(.key)=\(.value)"),
	  (if ([.mcp_servers[]?.name] | sort) != ($want | sort) then "unexpected MCP servers: \([.mcp_servers[]?.name] | join(", "))" else empty end),
	  (.mcp_servers[]? | select(.status != "connected") | "MCP server \(.name) is \(.status)"),
	  (if (.cwd // "") != "/work" then "cwd is \(.cwd)" else empty end)
	] | .[]' "$INIT")"
if [ -n "$PROBLEMS" ]; then
	docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
	wait "$AGENT_PID" || true
	{ echo "REJECTED:"; echo "$PROBLEMS"; } | tee "$RESULTS/verdict.txt"
	exit 1
fi
echo "init event clean: $(jq -c '{model, cwd, plugins: [.plugins[]?.source], memory_paths, mcp_servers, skills, claude_code_version}' "$INIT")" | tee "$RESULTS/verdict.txt"

wait "$AGENT_PID" || true
echo $(( $(date +%s) - START_TS )) >"$RESULTS/wall_seconds"
pkill -P "$WATCHDOG" 2>/dev/null || true
kill "$WATCHDOG" 2>/dev/null || true
docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
jq -r 'select(.type == "result") | "result: \(.subtype), turns \(.num_turns), cost $\(.total_cost_usd // 0)"' "$TRANSCRIPT" | tee -a "$RESULTS/verdict.txt"

archive_workdir "$WORK" "$RESULTS/code"
echo "code archived in $RESULTS/code"

if [ "$SMOKE" = 1 ]; then
	exit 0
fi
score_rc=0
"$EVAL_DIR/score.sh" --docker --stack "$STACK" --spec "$SPEC" "$WORK" | tee "$RESULTS/score.txt" || score_rc=$?
"$EVAL_DIR/metrics.sh" "$RESULTS" >/dev/null || echo "metrics.sh failed" >&2
exit "$score_rc"
