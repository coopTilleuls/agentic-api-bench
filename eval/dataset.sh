#!/usr/bin/env bash
# Writes eval/results/dataset.json: one object per scored-or-not run directory (smoke runs and runs without metrics.json skipped).
set -uo pipefail
EVAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESULTS="$EVAL_DIR/results"
OUT="$RESULTS/dataset.json"

mapfile -t RUNS < <(find "$RESULTS" -mindepth 1 -maxdepth 1 -type d -name '2*' ! -name '*smoke*' | sort)

rd() {
	[ -f "$1/$2" ] && tr -d '\n' <"$1/$2"
}

for dir in "${RUNS[@]}"; do
	[ -f "$dir/metrics.json" ] || continue
	run="$(basename "$dir")"
	spec="$(rd "$dir" spec || true)"
	stack="$(rd "$dir" stack || true)"
	model="$(rd "$dir" model || true)"
	provider="$(rd "$dir" provider || true)"
	context="$(rd "$dir" context || true)"
	scaffolded="$(rd "$dir" scaffolded || true)"
	apip_ref="$(rd "$dir" apip_ref || true)"
	score_line="$(grep -m1 '^score: ' "$dir/score.txt" 2>/dev/null || true)"
	aborted=""
	[ -f "$dir/aborted" ] && aborted="$(cat "$dir/aborted")"
	jq -n \
		--arg run "$run" --arg spec "$spec" --arg stack "$stack" --arg model "$model" \
		--arg provider "$provider" --arg context "$context" --arg scaffolded "$scaffolded" --arg apip_ref "$apip_ref" --arg score_line "$score_line" \
		--argjson has_aborted "$([ -f "$dir/aborted" ] && echo true || echo false)" \
		--arg aborted "$aborted" \
		--argjson timed_out "$([ -f "$dir/timed_out" ] && echo true || echo false)" \
		--slurpfile m "$dir/metrics.json" '
		$m[0] as $m
		| (if $spec == "" then "v1" else $spec end) as $spec
		| (if $stack == "" then "runtime" else $stack end) as $stack
		| (if $provider == "" then "anthropic" else $provider end) as $provider
		| ($score_line | capture("^score: (?<p>[0-9]+) passed, (?<f>[0-9]+) failed")) as $s
		| ($s != null) as $scored
		| ($s.p // null | if . == null then null else tonumber end) as $passed
		| ($s.f // null | if . == null then null else tonumber end) as $failed
		| (if $scored then $passed + $failed else null end) as $total
		| {
			run: $run,
			date: ($run | split("-")[0]),
			task: $m.task,
			spec: $spec,
			stack: $stack,
			variant: (if $stack == "runtime" then (if ($run | test("-no-mcp")) then "no-mcp" else "mcp" end) else null end),
			model: $model,
			provider: $provider,
			context: (if $context == "" then "none" else $context end),
			scaffolded: ($scaffolded == "1"),
			apip_ref: (if $apip_ref == "" then null else $apip_ref end),
			score_passed: $passed,
			score_failed: $failed,
			score_total: $total,
			turns: $m.num_turns,
			cost_usd: (if $provider == "anthropic" then $m.total_cost_usd else null end),
			cost_note: (if $provider != "anthropic" then "cli cost invalid for non-Claude models" else null end),
			wall_seconds: $m.wall_seconds,
			authored_loc: $m.authored_loc,
			authored_files: $m.authored_files,
			changed_lines_added: $m.changed_lines_added,
			changed_lines_removed: $m.changed_lines_removed,
			files_touched: $m.files_touched,
			tokens: {
				input: $m.usage.input_tokens,
				cache_read: $m.usage.cache_read_input_tokens,
				cache_creation: $m.usage.cache_creation_input_tokens,
				output: $m.usage.output_tokens
			},
			complexity: (if $m.complexity then {
				cc_total: $m.complexity.cc_total,
				lloc: $m.complexity.lloc,
				config_keys: $m.complexity.config_keys,
				sql_statements: $m.complexity.sql_statements
			} else null end),
			flags: {
				aborted: (if $has_aborted then $aborted else false end),
				timed_out: $timed_out,
				framework_bypassed: $m.framework_bypassed
			},
			framework_rev: null
		}'
done | jq -s 'sort_by(.run)' >"$OUT"

jq length "$OUT"
jq -r 'group_by([.spec, .stack, .model]) | map("\(.[0].spec)\t\(.[0].stack)\t\(.[0].model)\t\(length)") | .[]' "$OUT" | column -t -s $'\t' >&2
