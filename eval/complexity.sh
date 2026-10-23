#!/usr/bin/env bash
# Usage: complexity.sh <run dir>
# Static complexity of the AUTHORED content of a run (same authored set as
# authored_loc in metrics.sh: files absent from eval/scaffolds/<stack>/manifest.tsv
# or differing from it, see list_authored in lib.sh; manifest paths and generated-file rules are relative to the project root, see project_root). Writes <run dir>/complexity.json
# and merges it under "complexity" in <run dir>/metrics.json when that exists.
# A directory without code/ and transcript.jsonl (example-v2) is read as the
# code itself (stack from its "stack" file, else runtime): JSON goes to stdout only.
#
# imperative: ast-metrics (pinned, eval/tools/ast-metrics) over authored
#   .ts .tsx .js .mjs .cjs .php .go .rs, and .star Starlark staged as .py (close to Python;
#   files ast-metrics returns nothing for are listed in parse_failures). .d.ts declaration
#   files are skipped (AUTH_EXCL_RE in lib.sh already drops Symfony's generated files). Modified scaffold files are measured WHOLE
#   (no line-level meaning for complexity): see modified_whole.
#   cc_total = sum of the cyclomatic complexity of the files (each function and
#   closure counts 1 + its branches), cc_max_file = highest complexity of one file
#   (ast-metrics 0.43.1 exposes no per-function figure: its maxCyclomaticComplexity
#   reports 1 for a class holding a complexity-9 method, so it is not used),
#   mi_avg = mean of the per-file maintainability index (files without an index,
#   too small to compute one, are left out; mi_files says how many count),
#   lloc = logical lines, functions = functions, methods and (TS/JS) arrow
#   functions (numberMethods of the report). The binary runs in a network
#   namespace without network when unshare allows it.
# config: authored .json .yaml .yml (lock files excluded): keys = mapping keys,
#   leaves = scalar values (an empty map or list is one leaf; list positions are
#   not part of a path). Modified scaffold files (package.json, tsconfig.json,
#   config/*.yaml, composer.json): only the keys and leaves absent from the
#   pristine file (eval/tools/src, multiset difference).
# sql: eval/tools/src/sql.go, same difference rule for modified files; its header
#   comment lists the heuristic's limits.
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
RUN="${1:?usage: complexity.sh <run dir>}"
RUN="${RUN%/}"
TOOLS="$EVAL_DIR/tools"
AST_VERSION=0.43.1
AST_SHA256=42c5fe159f05bac923a112fb16f25d7c4c517561c5ffd42151bd0e4fc4729af2
AST_URL="https://github.com/ast-metrics/ast-metrics/releases/download/v$AST_VERSION/ast-metrics_Linux_x86_64"

ensure_ast_metrics() {
	local bin="$TOOLS/ast-metrics"
	if [ -x "$bin" ] && [ "$(sha256sum "$bin" | cut -d' ' -f1)" = "$AST_SHA256" ]; then return 0; fi
	mkdir -p "$TOOLS"
	curl -fsSL -o "$bin.part" "$AST_URL" || return 1
	if [ "$(sha256sum "$bin.part" | cut -d' ' -f1)" != "$AST_SHA256" ]; then
		echo "ast-metrics sha256 mismatch, discarded" >&2
		rm -f "$bin.part"
		return 1
	fi
	chmod +x "$bin.part" && mv "$bin.part" "$bin"
}

ensure_complexity_tool() {
	local bin="$TOOLS/bin/complexity-tool"
	if [ -x "$bin" ] && [ -z "$(find "$TOOLS/src" -newer "$bin" -type f 2>/dev/null | head -1)" ]; then return 0; fi
	mkdir -p "$TOOLS/bin"
	(cd "$TOOLS/src" && go build -o "$bin" .) >&2
}

ensure_ast_metrics || { echo "cannot get ast-metrics $AST_VERSION" >&2; exit 1; }
ensure_complexity_tool || { echo "cannot build eval/tools/src" >&2; exit 1; }
CTOOL="$TOOLS/bin/complexity-tool"

# Declaration files a tool regenerates are not authored logic (Symfony's generated files are already out of list_authored, see AUTH_EXCL_RE).
is_generated() { case "$1" in *.d.ts) return 0 ;; esac; return 1; }

STANDALONE=0
if [ -d "$RUN/code" ] || [ -f "$RUN/transcript.jsonl" ]; then
	WORK="$(source_code_dir "$RUN" 2>/dev/null)" || { echo "no code for $RUN" >&2; exit 1; }
else
	WORK="$RUN"
	STANDALONE=1
fi
STACK="$(cat "$RUN/stack" 2>/dev/null || echo runtime)"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/llmapi-complexity-XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage" "$TMP/home"

list_authored "$WORK" "$STACK" >"$TMP/authored.tsv" || { echo "no scaffold manifest for stack $STACK" >&2; exit 1; }

# ---- imperative code ----
: >"$TMP/staged.txt"
: >"$TMP/modified_whole.txt"
while IFS=$'\t' read -r abs rel status old; do
	is_generated "$rel" && continue
	case "$rel" in
	*.ts | *.tsx | *.js | *.mjs | *.cjs | *.php | *.go | *.rs) dest="$rel" ;;
	*.star) dest="$rel.py" ;;
	*) continue ;;
	esac
	mkdir -p "$TMP/stage/$(dirname "$dest")"
	cp "$abs" "$TMP/stage/$dest"
	echo "$dest" >>"$TMP/staged.txt"
	[ "$status" = modified ] && echo "$rel" >>"$TMP/modified_whole.txt"
done <"$TMP/authored.tsv"

printf '{}' >"$TMP/imperative.json"
if [ -s "$TMP/staged.txt" ]; then
	RUNNER=()
	NOTE_NET=none
	if unshare -rn true 2>/dev/null; then RUNNER=(unshare -rn); NOTE_NET=unshare; fi
	(cd "$TMP/stage" && HOME="$TMP/home" "${RUNNER[@]}" "$TOOLS/ast-metrics" analyze --report-json="$TMP/report.json" --report-summary=false --typescript-extensions .js,.mjs,.cjs \
		--exclude '(^|/)(node_modules|vendor|var|\.next|target)(/|$)' . >"$TMP/ast.log" 2>&1)
	if [ -s "$TMP/report.json" ]; then
		jq -n --slurpfile r "$TMP/report.json" --rawfile staged "$TMP/staged.txt" --arg stage "$TMP/stage/" --arg net "$NOTE_NET" '
			($r[0]) as $r
			| ($r.concernedFiles | map(.path | ltrimstr($stage))) as $parsed
			| ($staged | split("\n") | map(select(. != ""))) as $all
			| ($r.concernedFiles | map(.maintainability.maintainabilityIndex | select(. != null))) as $mi
			| {
				imperative_files: ($all | length),
				parse_failures: ($all - $parsed),
				cc_total: ([$r.concernedFiles[].complexity.cyclomatic // 0] | add // 0),
				cc_max_file: ([$r.concernedFiles[].complexity.cyclomatic // 0] | max // 0),
				mi_avg: (if ($mi | length) == 0 then null else (($mi | add) / ($mi | length) * 100 | round / 100) end),
				mi_files: ($mi | length),
				lloc: ($r.lloc // 0),
				functions: ($r.numberMethods // 0),
				ast_metrics_network: $net
			}' >"$TMP/imperative.json"
	else
		jq -n --rawfile staged "$TMP/staged.txt" '{imperative_files: ($staged | split("\n") | map(select(. != "")) | length), parse_failures: ($staged | split("\n") | map(select(. != ""))), cc_total: null, cc_max_file: null, mi_avg: null, mi_files: 0, lloc: null, functions: null, error: "ast-metrics produced no report"}' >"$TMP/imperative.json"
	fi
else
	jq -n '{imperative_files: 0, parse_failures: [], cc_total: 0, cc_max_file: 0, mi_avg: null, mi_files: 0, lloc: 0, functions: 0}' >"$TMP/imperative.json"
fi

# ---- config and SQL, file by file ----
cfg_keys=0 cfg_leaves=0 cfg_files=0
: >"$TMP/config_failures.txt"
: >"$TMP/sql_files.jsonl"
while IFS=$'\t' read -r abs rel status old; do
	case "$rel" in
	*.json | *.yaml | *.yml)
		args=(config)
		[ "$status" = modified ] && [ -f "$old" ] && args+=(-base "$old")
		if out="$("$CTOOL" "${args[@]}" "$abs" 2>"$TMP/err")"; then
			cfg_keys=$((cfg_keys + $(jq -r .keys <<<"$out")))
			cfg_leaves=$((cfg_leaves + $(jq -r .leaves <<<"$out")))
			cfg_files=$((cfg_files + 1))
		else
			echo "$rel: $(head -1 "$TMP/err")" >>"$TMP/config_failures.txt"
		fi
		;;
	esac
	case "$rel" in
	*.d.ts) continue ;;
	*.ts | *.tsx | *.js | *.mjs | *.cjs | *.php | *.go | *.rs | *.star | *.sql | *.json | *.yaml | *.yml | *.sh)
		args=(sql)
		[ "$status" = modified ] && [ -f "$old" ] && args+=(-base "$old")
		if out="$("$CTOOL" "${args[@]}" "$abs" 2>/dev/null)"; then
			jq -c --arg f "$rel" '{file: $f} + .' <<<"$out" >>"$TMP/sql_files.jsonl"
		fi
		;;
	esac
done <"$TMP/authored.tsv"

RESULT="$(jq -n --slurpfile imp "$TMP/imperative.json" --slurpfile sqlf "$TMP/sql_files.jsonl" \
	--rawfile mw "$TMP/modified_whole.txt" --rawfile cf "$TMP/config_failures.txt" \
	--argjson ck "$cfg_keys" --argjson cl "$cfg_leaves" --argjson cn "$cfg_files" --arg stack "$STACK" '
	$imp[0] + {
		modified_whole: ($mw | split("\n") | map(select(. != ""))),
		config_keys: $ck,
		config_leaves: $cl,
		config_files: $cn,
		config_failures: ($cf | split("\n") | map(select(. != ""))),
		sql_statements: ([$sqlf[].sql_statements] | add // 0),
		sql_joins: ([$sqlf[].sql_joins] | add // 0),
		sql_subqueries: ([$sqlf[].sql_subqueries] | add // 0),
		sql_where_predicates: ([$sqlf[].sql_where_predicates] | add // 0),
		sql_tokens: ([$sqlf[].sql_tokens] | add // 0),
		qb_calls: ([$sqlf[].qb_calls] | add // 0),
		sql_files: [$sqlf[] | select(.sql_statements > 0 or .qb_calls > 0) | {file, statements: .sql_statements, qb_calls}]
	} + {stack: $stack, ast_metrics: "'"$AST_VERSION"'"}')"

if [ "$STANDALONE" = 1 ]; then
	echo "$RESULT"
	exit 0
fi
echo "$RESULT" >"$RUN/complexity.json"
if [ -f "$RUN/metrics.json" ]; then
	jq --argjson c "$RESULT" '.complexity = $c' "$RUN/metrics.json" >"$TMP/metrics.json" && cp "$TMP/metrics.json" "$RUN/metrics.json"
fi
echo "$RESULT"
