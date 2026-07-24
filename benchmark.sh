#!/usr/bin/env bash
#
# Speed benchmark: classify the same fixed-size slice of the corpus through both
# environments (DDEV and wp-env) with the same plugin build, and print a
# head-to-head comparison. Reports both the driver's own classification time
# (pure in-process work) and the end-to-end wall time of the run (which includes
# each environment's per-command container overhead).
#
# Prerequisites: ./clone-asb.sh has produced <label>, and BOTH ./ddev/setup.sh
# and ./wp-env/setup.sh have run.
#
# Usage:
#   ./benchmark.sh <label> [count]
#
#   label   Cloned version to classify (a directory under versions/).
#   count   Comments to replay per environment (default: 10000).
set -euo pipefail

LABEL="${1:?usage: ./benchmark.sh <label> [count]}"
COUNT="${2:-10000}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
mkdir -p "$LOG_DIR"

ENVS=(ddev wp-env)
declare -A CLASSIFY_S RATE WALL_S

for env in "${ENVS[@]}"; do
	log="$LOG_DIR/bench-${env}.log"
	echo
	echo "############################################################"
	echo "# benchmark: $env — $COUNT comments, version '$LABEL'"
	echo "############################################################"

	start="$(date +%s.%N)"
	set +e
	"$SCRIPT_DIR/$env/run-version.sh" "$LABEL" "$COUNT" 2>&1 | tee "$log"
	status="${PIPESTATUS[0]}"
	set -e
	end="$(date +%s.%N)"

	if [ "$status" -ne 0 ]; then
		echo "!! $env run failed (exit $status); see $log" >&2
		CLASSIFY_S["$env"]="ERR"; RATE["$env"]="ERR"; WALL_S["$env"]="ERR"
		continue
	fi

	WALL_S["$env"]="$(awk -v a="$start" -v b="$end" 'BEGIN{printf "%.1f", b-a}')"
	# Driver final line: "Done. worker 0/1: ... in 12.3s (48780/min)."
	done_line="$(grep -oE 'in [0-9.]+s \([0-9]+/min\)' "$log" | tail -1 || true)"
	CLASSIFY_S["$env"]="$(sed -nE 's/^in ([0-9.]+)s .*/\1/p' <<<"$done_line")"
	RATE["$env"]="$(sed -nE 's/.*\(([0-9]+)\/min\).*/\1/p' <<<"$done_line")"
	: "${CLASSIFY_S[$env]:=?}" "${RATE[$env]:=?}"
done

echo
echo "================================================================"
echo " Benchmark — version '$LABEL', $COUNT comments per environment"
echo "================================================================"
printf '%-10s | %14s | %16s | %12s\n' "env" "classify (s)" "rate (/min)" "wall (s)"
printf '%-10s-+-%14s-+-%16s-+-%12s\n' "----------" "--------------" "----------------" "------------"
for env in "${ENVS[@]}"; do
	printf '%-10s | %14s | %16s | %12s\n' \
		"$env" "${CLASSIFY_S[$env]:-?}" "${RATE[$env]:-?}" "${WALL_S[$env]:-?}"
done
echo
echo "classify = driver-reported in-process time; wall = full run-version.sh."
