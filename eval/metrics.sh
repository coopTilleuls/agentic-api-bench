#!/usr/bin/env bash
# Writes <run dir>/metrics.json from the run's transcript and code (<run dir>/code,
# else the directory of its workdir file when it still exists):
#   num_turns, total_cost_usd, usage (final result event);
#   wall_seconds (file written by run.sh);
#   project_root: where the stack's project sits (project_root in lib.sh: the
#     shallowest directory with composer.json, package.json, go.mod or Cargo.toml,
#     "." for the workdir itself); every root-anchored rule below (public/bundles,
#     config/reference.php, config/bundles.php, .env.dev, the scaffold manifest)
#     applies relative to it, files outside it are authored as usual;
#   authored_loc, authored_files, scaffold_files_modified: workdir text files
#     whose path is absent from eval/scaffolds/<stack>/manifest.tsv (built by
#     scaffold.sh; matched relative to the project root) or whose
#     sha256 differs. New files count all lines, modified scaffold files only
#     their added lines (git diff --no-index --numstat against scaffolds/<stack>/files).
#     scaffold_files_modified counts the modified ones. The runtime binary (./caddy) is not counted;
#   scaffold_command: scaffold command the agent ran (create-next-app,
#     composer create-project, symfony new, go mod init, cargo new|init), null if none;
#   tool_written_loc: lines of the files created or edited through Write/Edit/
#     MultiEdit calls only (misses Bash heredocs; kept for reference);
#   framework_bypassed: from the "framework_bypassed:" line of score.txt
#     (framework_guard.sh, runtime stack only; null for baselines or unscored runs);
#   repo_loc: lines of code in the workdir (scc, else a find|wc fallback).
#   task (build|evolve, from the run's task file). For task evolve only:
#     changed_lines_added, changed_lines_removed, files_touched: from
#     git diff --no-index --numstat between the source run's code (source_run
#     file, then its code/ directory, else its workdir) and this run's code, with the authored_loc
#     exclusions (node_modules, vendor, var, .next, .git, lock files, the caddy
#     binary, binary files). A file only in the result counts all its lines as
#     added, a file only in the source all its lines as removed. null when the
#     source code no longer exists.
#   complexity: static complexity of the authored content (imperative code via
#     ast-metrics, config keys and leaves, embedded SQL), computed by complexity.sh
#     (see its header) after the rest; also written to <run dir>/complexity.json.
# Limitation: an agent scaffolding with other versions or flags than the pinned
# ones gets the files differing only because of that counted as authored.
# node_modules, vendor, var, .next, .git, target (Rust), .gopath, .cache and
# .cargo-home (Go/Cargo caches) are excluded everywhere; authored_* and
# repo_loc also exclude <project root>/public/bundles, *.lock, package-lock.json and go.sum; for laravel
# storage/ and bootstrap/cache/, for django .venv, venv, __pycache__, *.pyc and staticfiles (lib.sh) are dropped from the
# authored and repo counts too; authored_*
# and the evolve counts also drop the generated files of AUTH_EXCL_RE and PROJ_EXCL_RE (lib.sh).
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
RUN="${1:?usage: metrics.sh <run dir>}"
RUN="${RUN%/}"
T="$RUN/transcript.jsonl"
[ -f "$T" ] || { echo "no transcript in $RUN" >&2; exit 1; }
WORK="$(source_code_dir "$RUN" 2>/dev/null || true)"

EXCL_RE='(^|/)(node_modules|vendor|var|\.next|target)(/|$)|^(\.gopath|\.cache|\.cargo-home)(/|$)'
STACK="$(cat "$RUN/stack" 2>/dev/null || echo runtime)"
[ -f "$RUN/context_files" ] && export CONTEXT_FILES_SKIPPED="$(cat "$RUN/context_files")"
MANIFEST="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/scaffolds/$STACK/manifest.tsv"

result="$(jq -c 'select(.type == "result")' "$T" 2>/dev/null | tail -1)"
[ -n "$result" ] || result='{}'
wall="$(cat "$RUN/wall_seconds" 2>/dev/null || echo null)"

written=0
if [ -n "$WORK" ]; then
	while IFS= read -r f; do
		rel="${f#/work/}"
		[[ "$f" == /work/* ]] || continue
		[[ "$rel" =~ $EXCL_RE ]] && continue
		[ "$STACK" = django ] && [[ "$rel" =~ $DJANGO_AUTH_EXCL_RE ]] && continue
		[ "$STACK" = nestjs ] && [[ "$rel" =~ (^|/)dist(/|$) ]] && continue
		[ "$STACK" = laravel ] && [[ "$rel" =~ (^|/)(storage|bootstrap/cache)/ ]] && continue
		[ -f "$WORK/$rel" ] && written=$((written + $(wc -l <"$WORK/$rel")))
	done < <(jq -r 'select(.type == "assistant") | .message.content[]? | select(.type == "tool_use" and (.name == "Write" or .name == "Edit" or .name == "MultiEdit")) | .input.file_path // empty' "$T" 2>/dev/null | sort -u)
fi

authored_loc=null authored_files=null modified=null
PROJECT_ROOT=""
if [ -n "$WORK" ]; then
	PROJECT_ROOT="$(project_root "$WORK" "$STACK")"
	if [ -f "$MANIFEST" ]; then
		authored_loc=0 authored_files=0 modified=0
		while IFS=$'\t' read -r abs rel status old; do
			if [ "$status" = modified ]; then
				modified=$((modified + 1))
				added="$(git diff --no-index --numstat -- "$old" "$abs" 2>/dev/null | awk '{print $1}')"
				[[ "$added" =~ ^[0-9]+$ ]] || added="$(grep -c '' "$abs")"
			else
				added="$(grep -c '' "$abs")"
			fi
			authored_files=$((authored_files + 1))
			authored_loc=$((authored_loc + added))
		done < <(list_authored "$WORK" "$STACK")
	fi
fi

TASK="$(cat "$RUN/task" 2>/dev/null || echo build)"
ch_added=null ch_removed=null ch_files=null
if [ "$TASK" = evolve ] && [ -n "$WORK" ]; then
	SRC_WORK="$(source_code_dir "$(cat "$RUN/source_run" 2>/dev/null)" 2>/dev/null || true)"
	if [ -n "$SRC_WORK" ]; then
		SRC_ROOT="$(project_root "$SRC_WORK" "$STACK")"
		ch_added=0 ch_removed=0 ch_files=0
		while IFS= read -r rel; do
			authored_excluded "$rel" "$PROJECT_ROOT" && continue
			authored_excluded "$rel" "$SRC_ROOT" && continue
			a="$SRC_WORK/$rel" b="$WORK/$rel"
			[ -f "$a" ] && ! grep -Iq . "$a" 2>/dev/null && [ -s "$a" ] && continue
			[ -f "$b" ] && ! grep -Iq . "$b" 2>/dev/null && [ -s "$b" ] && continue
			if [ -f "$a" ] && [ -f "$b" ]; then
				cmp -s "$a" "$b" && continue
				read -r add del _ < <(git diff --no-index --numstat -- "$a" "$b" 2>/dev/null)
			elif [ -f "$b" ]; then
				add="$(grep -c '' "$b")" del=0
			else
				add=0 del="$(grep -c '' "$a")"
			fi
			[[ "$add" =~ ^[0-9]+$ && "$del" =~ ^[0-9]+$ ]] || continue
			ch_added=$((ch_added + add))
			ch_removed=$((ch_removed + del))
			ch_files=$((ch_files + 1))
		done < <(for d in "$SRC_WORK" "$WORK"; do (cd "$d" && find . \( -name node_modules -o -name vendor -o -name var -o -name .next -o -name .git -o -name target -o -name .gopath -o -name .cache -o -name .cargo-home $(stack_prune_names "$STACK") \) -prune -o -type f -print); done | sed 's|^\./||' | sort -u)
	fi
fi

scaffold_cmd="$(jq -r 'select(.type == "assistant") | .message.content[]? | select(.type == "tool_use" and .name == "Bash") | .input.command // empty' "$T" 2>/dev/null |
	grep -oE '(create-next-app[^|;&]*|composer +create-project[^|;&]*|symfony +new[^|;\&]*|go +mod +init[^|;\&]*|django-admin +startproject[^|;\&]*|cargo +(new|init)[^|;\&]*)' | head -1 | tr -s ' \n' ' ' | sed 's/ *$//' || true)"

repo=null
tool=none
case "$STACK" in
nestjs) SCC_EXTRA=,dist; FIND_EXTRA=('!' -path '*/dist/*') ;;
laravel) SCC_EXTRA=,storage; FIND_EXTRA=('!' -path '*/storage/*' '!' -path '*/bootstrap/cache/*') ;;
django) SCC_EXTRA=,.venv,venv,__pycache__,staticfiles; FIND_EXTRA=('!' -path '*/.venv/*' '!' -path '*/venv/*' '!' -path '*/__pycache__/*' '!' -path '*/staticfiles/*' '!' -name '*.pyc') ;;
*) SCC_EXTRA=; FIND_EXTRA=() ;;
esac
if [ -n "$WORK" ]; then
	if command -v scc >/dev/null 2>&1; then
		tool=scc
		repo="$(scc --format json --no-cocomo --exclude-dir node_modules,vendor,var,.next,bundles,target,.gopath,.cache,.cargo-home$SCC_EXTRA --not-match '(\.lock|package-lock\.json|go\.sum)$' "$WORK" 2>/dev/null | jq '[.[].Lines] | add // 0')"
	else
		tool=find-wc
		repo="$(cd "$WORK" && find . -type f ! -path '*/node_modules/*' ! -path '*/vendor/*' ! -path '*/var/*' ! -path '*/.next/*' ! -path '*/target/*' ! -path './.gopath/*' ! -path './.cache/*' ! -path './.cargo-home/*' ! -path "./$PROJECT_ROOT/public/bundles/*" ! -name '*.lock' ! -name package-lock.json ! -name go.sum ! -path ./caddy "${FIND_EXTRA[@]}" -print0 | xargs -0 -r cat 2>/dev/null | wc -l)"
	fi
fi

bypassed=null
if [ -f "$RUN/score.txt" ]; then
	case "$(grep -E '^framework_bypassed:' "$RUN/score.txt" | tail -1 | awk '{print $2}')" in
	true) bypassed=true ;;
	false) bypassed=false ;;
	esac
fi

jq -n --argjson bypassed "$bypassed" --argjson r "$result" --argjson wall "$wall" --argjson written "$written" --argjson repo "${repo:-null}" --arg tool "$tool" --argjson al "$authored_loc" --argjson af "$authored_files" --argjson mod "$modified" --arg root "$PROJECT_ROOT" --arg sc "$scaffold_cmd" --arg task "$TASK" --argjson cha "$ch_added" --argjson chr "$ch_removed" --argjson chf "$ch_files" '{
	task: $task,
	num_turns: ($r.num_turns // null),
	total_cost_usd: ($r.total_cost_usd // null),
	usage: {
		input_tokens: ($r.usage.input_tokens // null),
		output_tokens: ($r.usage.output_tokens // null),
		cache_read_input_tokens: ($r.usage.cache_read_input_tokens // null),
		cache_creation_input_tokens: ($r.usage.cache_creation_input_tokens // null)
	},
	wall_seconds: $wall,
	project_root: (if $root == "" then null else $root end),
	authored_loc: $al,
	authored_files: $af,
	scaffold_files_modified: $mod,
	scaffold_command: (if $sc == "" then null else $sc end),
	tool_written_loc: $written,
	repo_loc: $repo,
	repo_loc_tool: $tool,
	framework_bypassed: $bypassed,
	changed_lines_added: $cha,
	changed_lines_removed: $chr,
	files_touched: $chf
}' | tee "$RUN/metrics.json"
"$(dirname "${BASH_SOURCE[0]}")/complexity.sh" "$RUN" >/dev/null || echo "complexity.sh failed for $RUN" >&2
