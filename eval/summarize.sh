#!/usr/bin/env bash
# Writes eval/results/summary.md from the run directories given as arguments
# (default: every non-smoke, non-failed-harness run in eval/results).
set -uo pipefail
EVAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESULTS="$EVAL_DIR/results"
OUT="$RESULTS/summary.md"

if [ $# -gt 0 ]; then
	RUNS=("$@")
else
	mapfile -t RUNS < <(find "$RESULTS" -mindepth 1 -maxdepth 1 -type d -name '2*' ! -name '*smoke*' ! -name '*harness-failed*' | sort)
fi

tool_uses() {
	jq -c 'select(.type == "assistant") | .message.content[]? | select(.type == "tool_use") | {name, input}' "$1/transcript.jsonl" 2>/dev/null
}

# tool_results prints the text of every tool result except those quoting the
# guide, whose example errors (unknown_column on author_name, unknown_property
# on titel) would otherwise count as errors received.
tool_results() {
	jq -r 'select(.type == "user") | .message.content[]? | select(.type == "tool_result") | .content | if type == "string" then . else (map(.text? // empty) | join("\n")) end | select((contains("author_name") or contains("titel")) | not)' "$1/transcript.jsonl" 2>/dev/null
}

describe_action() {
	jq -r '.name + " " + ((.input.command // .input.file_path // .input.pattern // .input.url // (.input | tostring)) | gsub("\n"; " ") | .[0:110])'
}

used() {
	local dir="$1" mcp_tool="$2" cli="$3" via=()
	if tool_uses "$dir" | jq -e --arg t "mcp__caddy-resource__$mcp_tool" 'select(.name == $t)' >/dev/null 2>&1; then
		via+=("mcp")
	fi
	if tool_uses "$dir" | jq -e --arg c "resource $cli" 'select(.name == "Bash" and (.input.command // "" | contains($c)))' >/dev/null 2>&1; then
		via+=("cli")
	fi
	if tool_uses "$dir" | jq -e --arg t "$mcp_tool" 'select(.name == "Bash" and (.input.command // "" | contains("/mcp")) and (.input.command // "" | contains($t)))' >/dev/null 2>&1; then
		via+=("http-mcp")
	fi
	if [ ${#via[@]} -eq 0 ]; then echo "no"; else (IFS=+; echo "${via[*]}"); fi
}

rows=()
for dir in "${RUNS[@]}"; do
	dir="${dir%/}"
	name="$(basename "$dir")"
	model="$(cat "$dir/model" 2>/dev/null || echo "?")"
	stack="$(cat "$dir/stack" 2>/dev/null || echo runtime)"
	variant=-
	if [ "$stack" = runtime ]; then
		variant=no-mcp
		[[ "$name" == *-mcp* && "$name" != *-no-mcp* ]] && variant=mcp
	fi
	spec="$(cat "$dir/spec" 2>/dev/null || echo v1)"
	task="$(cat "$dir/task" 2>/dev/null || echo build)"
	jq -e 'has("authored_loc")' "$dir/metrics.json" >/dev/null 2>&1 || "$EVAL_DIR/metrics.sh" "$dir" >/dev/null 2>&1 || true
	jq -e 'has("complexity")' "$dir/metrics.json" >/dev/null 2>&1 || "$EVAL_DIR/complexity.sh" "$dir" >/dev/null 2>&1 || true
	written="$(jq -r '.authored_loc // "?"' "$dir/metrics.json" 2>/dev/null || echo "?")"
	bypassed="$(jq -r '.framework_bypassed | if . == null then "n/a" elif . then "YES" else "no" end' "$dir/metrics.json" 2>/dev/null || echo "?")"
	repoloc="$(jq -r '.repo_loc // "?"' "$dir/metrics.json" 2>/dev/null || echo "?")"
	cc="$(jq -r '.complexity.cc_total // "?"' "$dir/metrics.json" 2>/dev/null || echo "?")"
	mi="$(jq -r '.complexity.mi_avg // "-"' "$dir/metrics.json" 2>/dev/null || echo "?")"
	cfgkeys="$(jq -r '.complexity.config_keys // "?"' "$dir/metrics.json" 2>/dev/null || echo "?")"
	sqlcol="$(jq -r '.complexity | if . == null then "?" else "\(.sql_statements)/\(.sql_joins)" end' "$dir/metrics.json" 2>/dev/null || echo "?")"
	tokens="$(jq -r '.usage | if . == null then "?" else "\((.input_tokens // 0) + (.cache_read_input_tokens // 0) + (.cache_creation_input_tokens // 0)) in / \(.output_tokens // 0) out" end' "$dir/metrics.json" 2>/dev/null || echo "?")"
	score="$(grep -oE 'score: [0-9]+ passed, [0-9]+ failed' "$dir/score.txt" 2>/dev/null | awk '{print $2 "/" $2+$4}')"
	failed="$(grep -E '^FAIL  ' "$dir/score.txt" 2>/dev/null | sed -E 's/^FAIL  //; s/  \(status.*//' | paste -sd ';' - | sed 's/;/; /g')"
	regress="$(grep -oE '^regression: [0-9]+/[0-9]+' "$dir/score.txt" 2>/dev/null | awk '{print $2}')"
	change="$(grep -oE '^change: [0-9]+/[0-9]+' "$dir/score.txt" 2>/dev/null | awk '{print $2}')"
	if [ "$task" = evolve ]; then
		diffcols="$(jq -r '"+\(.changed_lines_added // "?") -\(.changed_lines_removed // "?") (\(.files_touched // "?") files)"' "$dir/metrics.json" 2>/dev/null || echo "?")"
		[ -n "$regress" ] && score="$score (regression $regress, change ${change:--})"
	else
		diffcols="-"
	fi
	langs="$(grep -E '^languages:' "$dir/score.txt" 2>/dev/null | sed 's/^languages: //')"
	result="$(jq -c 'select(.type == "result")' "$dir/transcript.jsonl" 2>/dev/null | tail -1)"
	turns="$(echo "$result" | jq -r '.num_turns // "?"' 2>/dev/null || echo "?")"
	cost="$(echo "$result" | jq -r '.total_cost_usd // "?" | if type == "number" then (. * 100 | round / 100 | tostring) else . end' 2>/dev/null || echo "?")"
	subtype="$(echo "$result" | jq -r '.subtype // "none"' 2>/dev/null || echo "none")"
	wall="$(cat "$dir/wall_seconds" 2>/dev/null || echo "?")"
	[ -f "$dir/timed_out" ] && wall="$wall (timed out)"
	guide="$(used "$dir" framework_guide guide)"
	validate="$(used "$dir" validate validate)"
	rows+=("$model|$stack|$spec|$task|$variant|$name|$score|${failed:--}|${langs:--}|$turns|$wall|$cost|$tokens|$written|$repoloc|$cc|$mi|$cfgkeys|$sqlcol|$diffcols|$subtype|$guide|$validate|$bypassed")
done

{
	echo "# Eval summary"
	echo
	echo "Generated by eval/summarize.sh. Cost is total_cost_usd from the transcript (notional: runs use the subscription token). Tokens are input (incl. cache read and creation) / output. Authored LOC counts the lines of new workdir text files plus the added lines of modified ones, against the pristine scaffold manifest of the stack (eval/scaffolds/<stack>/manifest.tsv, built by eval/scaffold.sh; an agent scaffolding with other versions or flags than the pinned ones gets the differing files counted); Repo LOC is the workdir total without node_modules, vendor, var, .next (metrics.sh). Changed (evolve tasks only, from metrics.json) is the lines added and removed and the files touched against the source run's code (code/ of its run dir). CC, MI, Config keys and SQL stmts/joins come from complexity.sh (metrics.json "complexity", computed on authored content only, see its header): CC is the summed cyclomatic complexity of the authored .ts/.tsx/.js/.mjs/.php/.star files (ast-metrics, modified scaffold files whole), MI the mean per-file maintainability index, Config keys the mapping keys of authored .json/.yaml (added keys only for modified scaffold files), SQL stmts/joins the SQL statements found in string literals by a lexer heuristic and their JOINs. Wall time is the agent container's lifetime in seconds. \"guide\"/\"validate\": whether the agent called them, and how (mcp = MCP tool, cli = caddy resource subcommand, http-mcp = curl to /mcp). \"Bypassed\": YES when the scorer found the :8089 listener of a runtime run was not the workdir caddy binary (framework_bypassed in metrics.json), n/a for baselines."
	echo
	echo "| Model | Stack | Spec | Task | Variant | Run | Score | Failed assertions | Script languages | Turns | Wall (s) | Cost (USD) | Tokens | Authored LOC | Repo LOC | CC | MI | Config keys | SQL stmts/joins | Changed (evolve) | Result | guide | validate | Bypassed |"
	echo "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |"
	printf '%s\n' "${rows[@]}" | sort -t'|' -k1,1 -k2,2 -k3,3 -k4,4 -k5,5r -k6,6 | while IFS='|' read -r model stack spec task variant name score failed langs turns wall cost tokens written repoloc cc mi cfgkeys sqlcol diffcols subtype guide validate bypassed; do
		echo "| $model | $stack | $spec | $task | $variant | [$name]($name/) | $score | $failed | $langs | $turns | $wall | $cost | $tokens | $written | $repoloc | $cc | $mi | $cfgkeys | $sqlcol | $diffcols | $subtype | $guide | $validate | $bypassed |"
	done
	echo
	echo "## Appendix: first actions and error codes received"
	for dir in "${RUNS[@]}"; do
		dir="${dir%/}"
		echo
		echo "### $(basename "$dir") ($(cat "$dir/model" 2>/dev/null))"
		echo
		echo "First 3 actions:"
		echo
		tool_uses "$dir" | head -3 | describe_action | sed 's/^/1. `/; s/$/`/'
		echo
		codes="$(tool_results "$dir" | grep -oE '"code" ?: ?"[a-z_]+"' | sed -E 's/.*"([a-z_]+)"$/\1/' | sort | uniq -c | sort -rn | awk '{printf "%s%s (%s)", sep, $2, $1; sep=", "}')"
		echo "Error codes received (count): ${codes:-none}"
	done
} >"$OUT"
echo "wrote $OUT"
