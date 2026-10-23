#!/usr/bin/env bash
# Authored-code metrics must anchor their rules at the project root of the stack
# (shallowest dir holding composer.json, package.json, go.mod, Cargo.toml), not at
# the workdir root. Usage: eval/tests/metrics_test.sh
set -uo pipefail
EVAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$EVAL_DIR/lib.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
fail=0
check() {
	if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: expected '$3', got '$2'"; fail=1; fi
}

skel() { cp "$EVAL_DIR/scaffolds/apiplatform/files/$1" "$2/$1"; }
mkrun() { # mkrun <name> <stack> <project dir relative to code, "" for root>
	local run="$TMP/$1" p="$3" code
	code="$run/code"
	mkdir -p "$code/$p"
	echo "$2" >"$run/stack"
	echo '{"type":"result","num_turns":1}' >"$run/transcript.jsonl"
	echo "$code/${p:+$p/}"
}

# project under app/
base="$(mkrun nested apiplatform app)"
mkdir -p "$base/public/bundles/apiplatform" "$base/src" "$base/config" "$base/vendor/x" "$TMP/nested/code/extra"
skel composer.json "${base%/}"
echo 'console.log(1)' >"$base/public/bundles/apiplatform/init.js"
printf 'a\nb\nc\n' >"$base/public/bundles/apiplatform/big.js"
printf '<?php\nclass Foo {}\n' >"$base/src/Foo.php"
echo '<?php return [];' >"$base/config/bundles.php"
echo '<?php return [];' >"$base/config/reference.php"
echo 'APP_SECRET=x' >"$base/.env.dev"
echo '<?php' >"$base/vendor/x/y.php"
printf '#!/bin/sh\nexit 0\n' >"$TMP/nested/code/start.sh"

check "root of nested project" "$(project_root "$TMP/nested/code" apiplatform)" "app"
authored="$(list_authored "$TMP/nested/code" apiplatform | cut -f2 | sort | tr '\n' ' ')"
check "nested authored files" "$authored" "app/src/Foo.php start.sh "
"$EVAL_DIR/metrics.sh" "$TMP/nested" >/dev/null 2>&1
check "nested metrics authored_files" "$(jq .authored_files "$TMP/nested/metrics.json")" "2"
check "nested metrics project_root" "$(jq -r .project_root "$TMP/nested/metrics.json")" "app"

# project at the workdir root
base="$(mkrun flat apiplatform "")"
mkdir -p "$base/public/bundles/apiplatform" "$base/src"
skel composer.json "${base%/}"
printf 'a\nb\n' >"$base/public/bundles/apiplatform/init.js"
printf '<?php\nclass Foo {}\n' >"$base/src/Foo.php"
check "root of flat project" "$(project_root "$TMP/flat/code" apiplatform)" "."
check "flat authored files" "$(list_authored "$TMP/flat/code" apiplatform | cut -f2 | tr '\n' ' ')" "src/Foo.php "
"$EVAL_DIR/metrics.sh" "$TMP/flat" >/dev/null 2>&1
check "flat metrics authored_files" "$(jq .authored_files "$TMP/flat/metrics.json")" "1"
check "flat metrics project_root" "$(jq -r .project_root "$TMP/flat/metrics.json")" "."

# shallowest marker wins; dependency dirs are ignored
mkdir -p "$TMP/multi/a/b" "$TMP/multi/node_modules/p"
touch "$TMP/multi/a/package.json" "$TMP/multi/a/b/package.json" "$TMP/multi/node_modules/p/package.json"
check "shallowest marker" "$(project_root "$TMP/multi" nextjs)" "a"
check "runtime root" "$(project_root "$TMP/multi" runtime)" "."
mkdir -p "$TMP/nomarker"
check "no marker falls back to workdir" "$(project_root "$TMP/nomarker" go)" "."

# archive drops <root>/public/bundles
mkdir -p "$TMP/wd/app/public/bundles/x" "$TMP/wd/app/src" "$TMP/wd/app/vendor"
touch "$TMP/wd/app/composer.json" "$TMP/wd/app/public/bundles/x/a.js" "$TMP/wd/app/src/Foo.php" "$TMP/wd/app/vendor/v.php"
archive_workdir "$TMP/wd" "$TMP/arch" apiplatform
check "archive content" "$(cd "$TMP/arch" && find . -type f | sort | tr '\n' ' ')" "./app/composer.json ./app/src/Foo.php "

exit $fail
