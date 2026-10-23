#!/usr/bin/env bash
# Usage: scaffold.sh <runtime|nextjs|nestjs|apiplatform|laravel|django|go|rust>
# Writes eval/scaffolds/<stack>/manifest.tsv (relative path <TAB> sha256) of a
# pristine scaffold, used by metrics.sh to tell authored files from generated
# ones, and the pristine files themselves under eval/scaffolds/<stack>/files/
# (diffed for modified files). No node_modules, vendor or lock files.
#   runtime       empty manifest: the workdir starts with the binary only
#   nextjs        create-next-app@$NEXT_VERSION, TypeScript, App Router, no Tailwind/ESLint/src dir
#   apiplatform   symfony/skeleton:$SKELETON_VERSION + composer require api
#   laravel       composer create-project laravel/laravel + composer require laravel-json-api/laravel
#                 (storage/, bootstrap/cache/ and database/database.sqlite are left out)
#   django        python venv (.venv) + pip install django djangorestframework djangorestframework-jsonapi
#                 psycopg[binary], then django-admin startproject app . (.venv, __pycache__ left out)
#   nestjs        npx @nestjs/cli new (npm, no git), dist/ and node_modules left out
#   go            go mod init example.com/api (go.mod only; go.sum is lock-like, excluded)
#   rust          cargo init --bin --name api (Cargo.toml, src/main.rs; Cargo.lock and target/ excluded)
# The scaffold is generated in the stack image, through the stack proxy, on an
# internal network (same setup as run.sh).
set -euo pipefail
EVAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$EVAL_DIR/lib.sh"

NEXT_VERSION=16.4.2
EXCL_RE='(^|/)(node_modules|vendor|var|\.next|target)(/|$)|^public/bundles/|(^|/)(package-lock\.json|go\.sum|[^/]*\.lock)$'

STACK="${1:?usage: scaffold.sh <runtime|nextjs|nestjs|apiplatform|laravel|django|go|rust>}"
case "$STACK" in
laravel) EXCL_RE="$EXCL_RE|^(storage|bootstrap/cache)/|^database/database\\.sqlite\$" ;;
nestjs) EXCL_RE="$EXCL_RE|(^|/)dist(/|\$)" ;;
django) EXCL_RE="$EXCL_RE|(^|/)(\\.venv|venv|__pycache__|staticfiles)(/|\$)|\\.pyc\$" ;;
esac
OUT_DIR="$EVAL_DIR/scaffolds/$STACK"
mkdir -p "$OUT_DIR"

if [ "$STACK" = runtime ]; then
	: >"$OUT_DIR/manifest.tsv"
	echo "wrote $OUT_DIR/manifest.tsv (empty)"
	exit 0
fi

case "$STACK" in
nextjs) CMD="npx --yes create-next-app@$NEXT_VERSION app --ts --app --no-tailwind --no-eslint --no-src-dir --import-alias '@/*' --use-npm --skip-install --yes --disable-git" ;;
apiplatform | laravel | django | nestjs) CMD="mkdir app && cd app && $(scaffold_cmd "$STACK")" ;;
go) CMD="mkdir app && cd app && go mod init example.com/api" ;;
rust) CMD="mkdir app && cd app && cargo init --bin --name api --vcs none" ;;
*) echo "unknown stack $STACK" >&2; exit 2 ;;
esac

SNET=llmapi-scaffold
SPROXY=llmapi-scaffold-proxy
TMP="$(mktemp -d "${TMPDIR:-/tmp}/llmapi-scaffold-XXXXXX")"
cleanup() {
	docker rm -f "$SPROXY" >/dev/null 2>&1 || true
	docker network rm "$SNET" >/dev/null 2>&1 || true
	rm -rf "$TMP"
}
trap cleanup EXIT

build_images "$STACK"
docker network create --internal "$SNET" >/dev/null
docker run -d --name "$SPROXY" "llmapi-eval-proxy-$STACK-anthropic" >/dev/null
docker network connect --alias proxy "$SNET" "$SPROXY"

PX=http://proxy:8888
mapfile -t CACHE < <(pkg_cache_args)
docker run --rm --network "$SNET" --user "$(id -u):$(id -g)" --tmpfs "/home/agent:uid=$(id -u),gid=$(id -g),mode=700,exec" \
	"${CACHE[@]}" -v "$TMP:/work" -e HTTPS_PROXY=$PX -e HTTP_PROXY=$PX -e https_proxy=$PX -e http_proxy=$PX \
	-e COMPOSER_HOME=/home/agent/.composer \
	--entrypoint bash "$(stack_image "$STACK")" -c "$CMD"

rm -rf "$OUT_DIR/files"
mkdir -p "$OUT_DIR/files"
(cd "$TMP/app" && find . -type f -print0 | sort -z | while IFS= read -r -d '' f; do
	rel="${f#./}"
	[[ "$rel" =~ $EXCL_RE ]] && continue
	mkdir -p "$OUT_DIR/files/$(dirname "$rel")"
	cp "$rel" "$OUT_DIR/files/$rel"
	printf '%s\t%s\n' "$rel" "$(sha256sum "$rel" | cut -d' ' -f1)"
done) >"$OUT_DIR/manifest.tsv"
echo "wrote $OUT_DIR/manifest.tsv ($(wc -l <"$OUT_DIR/manifest.tsv") files)"
