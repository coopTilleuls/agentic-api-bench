# Shared helpers for run.sh and score.sh. Source it; it defines no side effects.
[ -n "${BASH_VERSION:-}" ] || { echo "eval/lib.sh must be sourced from bash (it resolves paths with BASH_SOURCE)" >&2; return 1 2>/dev/null || exit 1; }
EVAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$EVAL_DIR/.." && pwd)"
COMPOSE=(docker compose -f "$REPO_DIR/compose.yaml")
PG_PORT=54329
EVAL_DB="${EVAL_DB:-llmapi_eval}"
EVAL_ROLE=eval_rw
EVAL_DSN="postgres://$EVAL_ROLE:$EVAL_ROLE@127.0.0.1:$PG_PORT/$EVAL_DB"
API_PORT=8089
STUB_PORT=18090

psql_admin() {
	"${COMPOSE[@]}" exec -T postgres psql -v ON_ERROR_STOP=1 -q -U llmapi "$@"
}

start_postgres() {
	"${COMPOSE[@]}" up -d --wait >/dev/null 2>&1
}

# spec_dir prints the directory holding spec.md, seed.sql and assert.sh of a
# spec version: eval/ for v1, eval/v2/ for v2, eval/v2.1/ for v2.1 (the
# evolution task: the v2 seed plus its migration.sql, its own assert.sh),
# eval/v3/ for v3 (v2's behaviour behind a JSON-LD/Hydra representation, the v2 seed).
spec_dir() {
	case "$1" in
	v1) echo "$EVAL_DIR" ;;
	v2) echo "$EVAL_DIR/v2" ;;
	v3) echo "$EVAL_DIR/v3" ;;
	v2.1) echo "$EVAL_DIR/v2.1" ;;
	*) echo "unknown spec $1" >&2; return 1 ;;
	esac
}

# prompt_file prints the file of a spec version given to the agent as the task.
prompt_file() {
	echo "$(spec_dir "$1")/spec.md"
}

# seed_dir prints the directory holding the seed.sql of a spec version
# (v2.1 starts from the v2 seed and applies its own migration.sql after it; v3
# shares the v2 seed).
seed_dir() {
	case "$1" in
	v2.1 | v3) echo "$EVAL_DIR/v2" ;;
	*) spec_dir "$1" ;;
	esac
}

# reset_db recreates $EVAL_DB from the seed.sql of $SPEC (default v1), then
# applies the migration.sql of the spec version if it has one. The eval role may read and write
# rows but not create objects, so a solution cannot bypass the query layer
# with views.
reset_db() {
	psql_admin -d postgres >/dev/null 2>&1 <<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$EVAL_ROLE') THEN
    CREATE ROLE $EVAL_ROLE LOGIN PASSWORD '$EVAL_ROLE';
  END IF;
END \$\$;
DROP DATABASE IF EXISTS $EVAL_DB WITH (FORCE);
CREATE DATABASE $EVAL_DB;
SQL
	if [ -f "$(seed_dir "${SPEC:-v1}")/schema.sql" ]; then
		psql_admin -d "$EVAL_DB" >/dev/null <"$(seed_dir "${SPEC:-v1}")/schema.sql"
	fi
	psql_admin -d "$EVAL_DB" >/dev/null <"$(seed_dir "${SPEC:-v1}")/seed.sql"
	if [ -f "$(spec_dir "${SPEC:-v1}")/migration.sql" ]; then
		psql_admin -d "$EVAL_DB" >/dev/null <"$(spec_dir "${SPEC:-v1}")/migration.sql"
	fi
	psql_admin -d "$EVAL_DB" >/dev/null <<SQL
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO $EVAL_ROLE;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO $EVAL_ROLE;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO $EVAL_ROLE;
SQL
}

# start_stub serves the external quotes API with the caddy binary itself,
# from a copy outside the repository.
start_stub() {
	local bin dir
	bin="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
	stop_stub
	dir="$(mktemp -d "${TMPDIR:-/tmp}/llmapi-stub-XXXXXX")"
	STUB_DIR="$dir"
	cp "$EVAL_DIR/stub/quotes.json" "$dir/"
	(cd "$dir" && CADDY_ADMIN=off "$bin" file-server --listen "127.0.0.1:$STUB_PORT" --root "$dir" >"$dir/stub.log" 2>&1 &)
	wait_http "http://127.0.0.1:$STUB_PORT/quotes.json" 10
}

stop_stub() {
	stop_port "$STUB_PORT"
	[ -n "${STUB_DIR:-}" ] && rm -rf "$STUB_DIR"
	STUB_DIR=""
}

stop_port() {
	local pids
	pids="$(lsof -t -iTCP:"$1" -sTCP:LISTEN 2>/dev/null || true)"
	if [ -n "$pids" ]; then
		kill $pids 2>/dev/null || true
		sleep 0.5
	fi
}

wait_http() {
	local url="$1" timeout="$2" i
	for ((i = 0; i < timeout * 4; i++)); do
		if curl -s -o /dev/null --max-time 1 "$url"; then
			return 0
		fi
		sleep 0.25
	done
	return 1
}

# Images and proxy per stack: runtime = the caddy binary (v1 and v2), nextjs
# apiplatform, go and rust = the baselines (v2 and v3). Targets of eval/docker/Dockerfile.
AGENT_IMAGE=llmapi-eval-agent
PROXY_NAME=llmapi-eval-proxy
EVAL_NET=llmapi-eval
DOCKER_DSN="postgres://$EVAL_ROLE:$EVAL_ROLE@postgres:5432/$EVAL_DB"

stack_image() {
	case "$1" in
	runtime) echo llmapi-eval-agent ;;
	nestjs) echo llmapi-eval-nextjs ;;
	nextjs | apiplatform | laravel | django | go | rust) echo "llmapi-eval-$1" ;;
	*) echo "unknown stack $1" >&2; return 1 ;;
	esac
}

# stack_target prints the Dockerfile target of a stack (nestjs shares nextjs's Node.js image).
stack_target() {
	case "$1" in
	nestjs) echo nextjs ;;
	*) echo "$1" ;;
	esac
}

VALIDATOR_IMAGE=llmapi-eval-validator

# ensure_validator_image builds the JSON-LD/OpenAPI validator image (v3 scoring)
# once; delete the image to rebuild it.
ensure_validator_image() {
	docker image inspect "$VALIDATOR_IMAGE" >/dev/null 2>&1 ||
		docker build -q -t "$VALIDATOR_IMAGE" "$EVAL_DIR/docker/validator" >/dev/null
}

# proxy_tag <stack> <provider> prints the proxy image of a run: the apiplatform
# proxy of spec v3 also allows the documentation host (its own image).
proxy_tag() {
	local stack="${1:-runtime}" provider="${2:-anthropic}"
	if [ "$stack" = apiplatform ] && [ "${SPEC:-}" = v3 ]; then
		echo "llmapi-eval-proxy-$stack-$provider-docs"
	else
		echo "llmapi-eval-proxy-$stack-$provider"
	fi
}

build_images() {
	local stack="${1:-runtime}" provider="${2:-anthropic}" docs=0
	[ "${SPEC:-}" = v3 ] && { ensure_validator_image || return 1; }
	[ "$stack" = apiplatform ] && [ "${SPEC:-}" = v3 ] && docs=1
	docker build -q -t "$(stack_image "$stack")" --target "$(stack_target "$stack")" -f "$EVAL_DIR/docker/Dockerfile" "$EVAL_DIR" >/dev/null
	docker build -q -t "$(proxy_tag "$stack" "$provider")" --build-arg "STACK=$stack" --build-arg "PROVIDER=$provider" --build-arg "DOCS=$docs" "$EVAL_DIR/docker/proxy" >/dev/null
}

# ensure_eval_network creates the internal network (no route out) holding
# the agent, Postgres (alias "postgres") and the egress proxy (alias "proxy").
ensure_eval_network() {
	docker network inspect "$EVAL_NET" >/dev/null 2>&1 || docker network create --internal "$EVAL_NET" >/dev/null
	local pg
	pg="$("${COMPOSE[@]}" ps -q postgres)"
	if ! docker inspect -f '{{json .NetworkSettings.Networks}}' "$pg" | grep -q "\"$EVAL_NET\""; then
		docker network connect --alias postgres "$EVAL_NET" "$pg"
	fi
}

start_proxy() {
	docker rm -f "$PROXY_NAME" >/dev/null 2>&1 || true
	docker run -d --name "$PROXY_NAME" "$(proxy_tag "${1:-runtime}" "${2:-anthropic}")" >/dev/null
	docker network connect --alias proxy "$EVAL_NET" "$PROXY_NAME"
}

stop_proxy() {
	docker rm -f "$PROXY_NAME" >/dev/null 2>&1 || true
}

# build_binary builds the eval binary without host paths: -trimpath and
# -buildvcs=false, stripped, flags passed to xcaddy through XCADDY_GO_BUILD_FLAGS.
# xcaddy records the module's replace directive (the local directory) in the
# build info, which -trimpath does not touch, so it builds from a copy of the
# sources in a neutral temporary directory.
build_binary() {
	local out tmp src rc=0
	out="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
	tmp="$(mktemp -d "${TMPDIR:-/tmp}/llmapi-build-XXXXXX")"
	src="$tmp/agentic-api-bench"
	mkdir -p "$src"
	{ (cd "$REPO_DIR" && tar --exclude=./bin --exclude=./.git --exclude=./eval/results --exclude=./research -cf - .) | tar -xf - -C "$src" &&
		(cd "$src" && CGO_ENABLED=0 XCADDY_GO_BUILD_FLAGS="-trimpath -buildvcs=false -ldflags='-s -w'" \
			xcaddy build v2.11.7 --with github.com/coopTilleuls/agentic-api-bench=. --output "$out"); } || rc=$?
	rm -rf "$tmp"
	return "$rc"
}

# check_binary_clean refuses a binary embedding the repository path or $HOME,
# which the agent could read with strings.
check_binary_clean() {
	local pat
	for pat in "$REPO_DIR" "$HOME"; do
		if strings "$1" | grep -qF "$pat"; then
			echo "REFUSED: $1 embeds host path $pat; delete bin/caddy to have run.sh rebuild it (see README Build)" >&2
			return 1
		fi
	done
}

MCP_NAME=llmapi-eval-framework-mcp
MCP_ALIAS=framework-mcp

# start_mcp_sidecar runs the framework MCP server in its own container on the
# eval network, following the dir option of $1/Caddyfile (mounted read-only),
# so the agent container has no MCP process.
start_mcp_sidecar() {
	local work="$1" bindir
	bindir="$(mktemp -d "${TMPDIR:-/tmp}/llmapi-mcpbin-XXXXXX")"
	MCP_BINDIR="$bindir"
	cp "$work/caddy" "$bindir/framework-mcp"
	docker rm -f "$MCP_NAME" >/dev/null 2>&1 || true
	docker run -d --name "$MCP_NAME" --network "$EVAL_NET" --network-alias "$MCP_ALIAS" \
		--user "$(id -u):$(id -g)" --tmpfs "/home/agent:uid=$(id -u),gid=$(id -g),mode=700" \
		-v "$work:/work:ro" -v "$bindir:/opt/mcp:ro" --workdir /work \
		--entrypoint /opt/mcp/framework-mcp "$AGENT_IMAGE" \
		resource mcp --http 0.0.0.0:8090 --config /work/Caddyfile --dsn "$DOCKER_DSN" >/dev/null
	local i
	for ((i = 0; i < 80; i++)); do
		docker run --rm --network "$EVAL_NET" --entrypoint curl "$AGENT_IMAGE" -s -o /dev/null \
			-X POST "http://$MCP_ALIAS:8090/mcp" -d '{"jsonrpc":"2.0","id":0,"method":"ping"}' && return 0
		sleep 0.25
	done
	docker logs "$MCP_NAME" >&2 || true
	return 1
}

stop_mcp_sidecar() {
	docker rm -f "$MCP_NAME" >/dev/null 2>&1 || true
	[ -n "${MCP_BINDIR:-}" ] && rm -rf "$MCP_BINDIR"
	MCP_BINDIR=""
}

# stack_prune_names <stack> prints the extra find(1) tests pruning the
# dependency and cache directories of laravel and django; nothing for the others.
stack_prune_names() {
	case "$1" in
	laravel) echo "-o -name storage" ;;
	nestjs) echo "-o -name dist" ;;
	django) echo "-o -name .venv -o -name venv -o -name __pycache__ -o -name staticfiles" ;;
	esac
}

# project_root <code dir> <stack> prints the project root relative to the code
# dir: the shallowest directory holding the stack marker (composer.json,
# package.json, manage.py, go.mod, Cargo.toml), dependency and cache directories ignored;
# "." when it is the code dir itself, when no marker is found, and for the
# runtime stack (no project). An agent may scaffold in a subdirectory
# (composer create-project symfony/skeleton app).
project_root() {
	local work="$1" stack="$2" proj cand
	case "$stack" in nextjs | nestjs) proj=package.json ;; apiplatform | laravel) proj=composer.json ;; django) proj=manage.py ;; go) proj=go.mod ;; rust) proj=Cargo.toml ;; *) echo .; return 0 ;; esac
	if [ -f "$work/$proj" ]; then echo .; return 0; fi
	cand="$(cd "$work" && find . -maxdepth 4 \( -name node_modules -o -name vendor -o -name var -o -name .next -o -name .git -o -name target -o -name .gopath -o -name .cache -o -name .cargo-home $(stack_prune_names "$stack") \) -prune -o -type f -name "$proj" -print 2>/dev/null |
		awk -F/ '{print NF "\t" $0}' | sort -n -k1,1 -k2,2 | head -1 | cut -f2)"
	if [ -n "$cand" ]; then dirname "${cand#./}"; else echo .; fi
}

# archive_workdir <workdir> <dest> [stack] copies the code an agent wrote into
# <dest> (<run dir>/code), without what is rebuilt or downloaded: the root caddy
# binary, node_modules, vendor, var, .next, <project root>/public/bundles, .git,
# target (Rust) and the Go and Cargo caches .gopath, .cache, .cargo-home; lock
# files are kept. The stack defaults to $STACK. The workdir lives in a temporary
# directory that does not last, so this copy is the evidence of a run.
archive_workdir() {
	local work="$1" dest="$2" stack="${3:-${STACK:-runtime}}" root
	local -a extra=() extra_anchored=()
	root="$(project_root "$work" "$stack")"
	case "$stack" in
	laravel) extra_anchored=(--exclude="./${root#./}/storage" --exclude="./${root#./}/bootstrap/cache") ;;
	nestjs) extra=(--exclude=dist) ;;
	django) extra=(--exclude=.venv --exclude=venv --exclude=__pycache__ --exclude='*.pyc' --exclude=staticfiles) ;;
	esac
	rm -rf "$dest"
	mkdir -p "$dest"
	tar -C "$work" --no-anchored --exclude=node_modules --exclude=vendor --exclude=var --exclude=.next --exclude=.git --exclude=target "${extra[@]}" \
		--anchored --exclude=./caddy --exclude="./${root#./}/public/bundles" --exclude=./.gopath --exclude=./.cache --exclude=./.cargo-home "${extra_anchored[@]}" -cf - . | tar -C "$dest" -xf -
}

# source_code_dir <run dir> prints where the code of a run is: <run dir>/code,
# else (runs made before the archive existed) the directory in its workdir file
# when it still exists.
source_code_dir() {
	if [ -d "$1/code" ]; then
		echo "$1/code"
	elif [ -f "$1/workdir" ] && [ -d "$(cat "$1/workdir")" ]; then
		cat "$1/workdir"
	else
		return 1
	fi
}

# pkg_cache_args prints docker args mounting persistent package caches (unauthenticated GitHub dist downloads hit the rate limit); install containers only.
pkg_cache_args() {
	local base="${LLMAPI_CACHE_DIR:-$HOME/.cache/agentic-api-bench}" t
	for t in composer npm pip; do mkdir -p "$base/$t" || return 1; done
	printf '%s\n' -v "$base/composer:/cache/composer" -v "$base/npm:/cache/npm" -v "$base/pip:/cache/pip" \
		-e COMPOSER_CACHE_DIR=/cache/composer -e npm_config_cache=/cache/npm -e PIP_CACHE_DIR=/cache/pip
}

# install_dependencies <workdir> <stack> <log> installs, inside the stack
# image and through the proxy (to be running), what an evolved checkout needs:
# npm ci (npm install without a lock file) for package.json, composer install
# for composer.json, go mod download for go.mod, cargo fetch for Cargo.toml (the
# go and rust images keep their caches in /work: .gopath, .cargo-home).
# The runtime has nothing to install.
install_dependencies() {
	local work="$1" stack="$2" log="$3" cmd=""
	case "$stack" in
	nextjs)
		if [ -f "$work/package.json" ]; then
			if [ -f "$work/package-lock.json" ]; then cmd="npm ci"; else cmd="npm install"; fi
		fi
		;;
	apiplatform)
		[ -f "$work/composer.json" ] && cmd="composer install --no-interaction"
		;;
	go)
		[ -f "$work/go.mod" ] && cmd="go mod download"
		;;
	rust)
		[ -f "$work/Cargo.toml" ] && cmd="cargo fetch"
		;;
	esac
	if [ -z "$cmd" ]; then
		echo "nothing to install for $stack" >"$log"
		return 0
	fi
	echo "\$ $cmd" >"$log"
	local -a cache
	mapfile -t cache < <(pkg_cache_args)
	docker run --rm --network "$EVAL_NET" --user "$(id -u):$(id -g)" --tmpfs "/home/agent:uid=$(id -u),gid=$(id -g),mode=700" \
		"${cache[@]}" -v "$work:/work" -e HTTPS_PROXY=http://proxy:8888 -e HTTP_PROXY=http://proxy:8888 -e NO_PROXY=localhost,127.0.0.1,postgres \
		-e https_proxy=http://proxy:8888 -e http_proxy=http://proxy:8888 -e no_proxy=localhost,127.0.0.1,postgres \
		"$(stack_image "$stack")" sh -c "$cmd" >>"$log" 2>&1
}

SKELETON_VERSION='7.4.*'

# scaffold_cmd <stack> prints the shell commands creating the pristine scaffold
# of apiplatform, laravel or django, dependencies included, in the empty
# current directory (scaffold.sh runs it in app/ to build the manifest,
# prescaffold_workdir in the workdir). Django's virtualenv is .venv.
scaffold_cmd() {
	case "$1" in
	apiplatform) echo "composer create-project symfony/skeleton:'$SKELETON_VERSION' . --no-interaction --no-progress --no-ansi && composer require api --no-interaction --no-progress --no-ansi" ;;
	laravel) echo "composer create-project laravel/laravel . --no-interaction --no-progress --no-ansi && composer require laravel-json-api/laravel --no-interaction --no-progress --no-ansi" ;;
	django) echo "python3 -m venv .venv && .venv/bin/pip install --no-input --disable-pip-version-check django djangorestframework djangorestframework-jsonapi 'psycopg[binary]' && .venv/bin/django-admin startproject app ." ;;
	nestjs) echo "npx --yes @nestjs/cli new app --directory . --package-manager npm --skip-git" ;;
	*) echo "no scaffold for stack $1" >&2; return 1 ;;
	esac
}

# scaffold_apip_ref_cmd <ref> prints the commands switching the apiplatform
# scaffold to the api-platform/core monorepo at <ref> (a commit of main, 7 to 40
# hex digits), run in the project after scaffold_cmd. The split packages are not
# an option: their subtree-split commits do not carry the monorepo sha. core
# replaces them (self.version, 5.1.x-dev by branch alias, which satisfies the
# ^5.0 of the scaffold), so composer drops them and a later
# `composer require api-platform/json-api` is satisfied by the replace. Flex
# unconfigures api-platform/symfony after configuring core and removes the bundle
# line they share: recipes:install restores it. symfony/uid stays installed (it came
# with api-platform/json-schema, which core does not require). minimum-stability dev
# is needed for dev-main; prefer-stable keeps every other package on releases.
# jsonapi.strict_member_names is opt-in: without it the "_id" member name stays.
# The auto-scripts run last so cache:clear proves the container boots with the key.
scaffold_apip_ref_cmd() {
	[[ "$1" =~ ^[0-9a-f]{7,40}$ ]] || { echo "invalid --apip-ref $1 (a commit sha of api-platform/core main, 7 to 40 hex digits)" >&2; return 1; }
	echo "composer config minimum-stability dev && composer config prefer-stable true" \
		"&& composer require 'api-platform/core:dev-main#$1' 'symfony/uid:$SKELETON_VERSION' --no-scripts --no-interaction --no-progress --no-ansi" \
		"&& composer recipes:install api-platform/core --force --reset --no-interaction --no-ansi" \
		"&& printf '    jsonapi:\\n        strict_member_names: true\\n' >>config/packages/api_platform.yaml" \
		"&& composer run-script auto-scripts --no-interaction --no-ansi"
}

# scaffold_versions_cmd <stack> [apip ref] prints the commands listing the top-level
# dependencies that scaffold_cmd resolved (name and version), run in the project.
# With an apiplatform ref it adds the line "api-platform/core@ref <locked reference>".
scaffold_versions_cmd() {
	case "$1" in
	apiplatform)
		echo "{ composer show --direct --no-ansi; composer show --no-ansi 'api-platform/*'; } | awk '{print \$1, \$2}' | sort -u"
		[ -z "${2:-}" ] || echo "composer show --no-ansi api-platform/core | awk '/^source/ {print \"api-platform/core@ref\", \$NF; exit}'"
		;;
	laravel) echo "{ composer show --direct --no-ansi; composer show --no-ansi 'laravel-json-api/*'; } | awk '{print \$1, \$2}' | sort -u" ;;
	django) echo ".venv/bin/pip freeze" ;;
	nestjs) echo "npm ls --depth=0 --json | jq -r '.dependencies | to_entries[] | \"\\(.key) \\(.value.version)\"'" ;;
	*) return 1 ;;
	esac
}

# stack_run <workdir> <stack> <command> runs a command in the stack image with
# the workdir as /work, through the proxy (to be running); the home is an
# exec tmpfs so tools may run their own scripts.
stack_run() {
	local -a cache
	mapfile -t cache < <(pkg_cache_args)
	docker run --rm --network "$EVAL_NET" --user "$(id -u):$(id -g)" --tmpfs "/home/agent:uid=$(id -u),gid=$(id -g),mode=700,exec" \
		"${cache[@]}" -v "$1:/work" -e HTTPS_PROXY=http://proxy:8888 -e HTTP_PROXY=http://proxy:8888 -e NO_PROXY=localhost,127.0.0.1,postgres \
		-e https_proxy=http://proxy:8888 -e http_proxy=http://proxy:8888 -e no_proxy=localhost,127.0.0.1,postgres \
		-e COMPOSER_HOME=/home/agent/.composer \
		--entrypoint bash "$(stack_image "$2")" -c "$3"
}

# prescaffold_workdir <workdir> <stack> <log> <versions file> [apip ref] creates the stack's
# scaffold (scaffold_cmd) in the empty workdir, logged in <log>, and writes the
# resolved top-level versions to <versions file>. An apip ref (apiplatform) then
# switches API Platform to that core commit (scaffold_apip_ref_cmd).
prescaffold_workdir() {
	local work="$1" stack="$2" log="$3" versions="$4" ref="${5:-}" cmd
	cmd="$(scaffold_cmd "$stack")" || return 1
	echo "\$ $cmd" >"$log"
	stack_run "$work" "$stack" "$cmd" >>"$log" 2>&1 || return 1
	if [ -n "$ref" ]; then
		cmd="$(scaffold_apip_ref_cmd "$ref")" || return 1
		echo "\$ $cmd" >>"$log"
		stack_run "$work" "$stack" "$cmd" >>"$log" 2>&1 || return 1
	fi
	stack_run "$work" "$stack" "$(scaffold_versions_cmd "$stack" "$ref" | paste -sd';')" >"$versions" 2>>"$log"
}

# Never authored, anywhere: dependency/build output (target/ is the Rust build
# dir), lock files (go.sum, Cargo.lock). AUTH_EXCL_RE is matched against the path
# relative to the code dir; it also holds what the go and rust images keep at the
# root of /work (.gopath .cache .cargo-home) and the runtime binary (caddy).
AUTH_EXCL_RE='(^|/)(node_modules|vendor|var|\.next|\.git|target)(/|$)|^(\.gopath|\.cache|\.cargo-home)(/|$)|(^|/)(package-lock\.json|go\.sum|[^/]*\.lock)$|^caddy$'

# Never authored, relative to the project root: Symfony assets installed into
# public/bundles and the files Symfony Flex or the console regenerate on any
# install (config/reference.php, config/bundles.php, .env.dev with its random APP_SECRET).
PROJ_EXCL_RE='^public/bundles/|^config/(reference|bundles)\.php$|^\.env\.dev$'

# Per stack, on top of the two above: the virtualenv, bytecode and collected
# static files of django (anywhere in the tree); the runtime and cache
# directories of laravel, relative to the project root.
DJANGO_AUTH_EXCL_RE='(^|/)(\.venv|venv|__pycache__|staticfiles)(/|$)|\.pyc$'
LARAVEL_PROJ_EXCL_RE='^(storage|bootstrap/cache)/|^database/database\.sqlite$'

# authored_excluded <path relative to the code dir> <project root> [stack]
# succeeds when the file is never authored: AUTH_EXCL_RE on the path,
# PROJ_EXCL_RE on the path relative to the project root (files outside the
# root only get AUTH_EXCL_RE), plus the stack's own rule (stack defaults to $STACK).
authored_excluded() {
	local rel="$1" root="$2" stack="${3:-${STACK:-}}" sub
	[[ "$rel" =~ $AUTH_EXCL_RE ]] && return 0
	[ "$stack" = django ] && [[ "$rel" =~ $DJANGO_AUTH_EXCL_RE ]] && return 0
	[ "$stack" = nestjs ] && [[ "$rel" =~ (^|/)dist(/|$) ]] && return 0
	if [ "$root" = . ]; then
		sub="$rel"
	elif [[ "$rel" == "$root/"* ]]; then
		sub="${rel#"$root/"}"
	else
		return 1
	fi
	[[ "$sub" =~ $PROJ_EXCL_RE ]] && return 0
	[ "$stack" = laravel ] && [[ "$sub" =~ $LARAVEL_PROJ_EXCL_RE ]] && return 0
	return 1
}

# context_file <rel> succeeds when the file is one of the context files run.sh
# copied into the workdir root (CONTEXT_FILES_SKIPPED, newline separated, set by
# metrics.sh from the run's context_files): never authored.
context_file() {
	local f
	[ -n "${CONTEXT_FILES_SKIPPED:-}" ] || return 1
	while IFS= read -r f; do
		[ "$1" = "$f" ] || [[ "$1" == "$f/"* ]] && return 0
	done <<<"$CONTEXT_FILES_SKIPPED"
	return 1
}

# list_authored <code dir> <stack> prints, tab separated, one line per authored
# text file: <absolute path> <path relative to the code dir> new|modified
# <pristine scaffold file or empty>. Same rule as authored_loc in metrics.sh: a
# file is authored when absent from eval/scaffolds/<stack>/manifest.tsv or when
# its sha256 differs; manifest paths are matched relative to the project root
# (project_root), files outside the project root are always new.
list_authored() {
	local work="$1" stack="$2" scaffolds root rel key sum inside
	scaffolds="$EVAL_DIR/scaffolds/$stack"
	[ -f "$scaffolds/manifest.tsv" ] || return 1
	local -A scaffold=()
	while IFS=$'\t' read -r rel sum; do
		scaffold["$rel"]="$sum"
	done <"$scaffolds/manifest.tsv"
	root="$(project_root "$work" "$stack")"
	while IFS= read -r -d '' f; do
		rel="${f#./}"
		authored_excluded "$rel" "$root" "$stack" && continue
		context_file "$rel" && continue
		grep -Iq . "$work/$rel" 2>/dev/null || continue
		inside=1 key="$rel"
		if [ "$root" != . ]; then
			if [[ "$rel" == "$root/"* ]]; then key="${rel#"$root/"}"; else inside=0; fi
		fi
		if [ "$inside" = 0 ] || [ -z "${scaffold[$key]+x}" ]; then
			printf '%s\t%s\tnew\t\n' "$work/$rel" "$rel"
		elif [ "${scaffold[$key]}" != "$(sha256sum "$work/$rel" | cut -d' ' -f1)" ]; then
			printf '%s\t%s\tmodified\t%s\n' "$work/$rel" "$rel" "$scaffolds/files/$key"
		fi
	done < <(cd "$work" && find . -type f -print0 | sort -z)
}
