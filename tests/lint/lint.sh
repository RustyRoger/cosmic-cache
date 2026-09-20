#!/usr/bin/env bash
# Lint every PHP file in the plugin (SPEC §17 CI step).
set -uo pipefail

cd "$(dirname "$0")/../.."
fail=0
total=0

while IFS= read -r file; do
	[[ "$file" == ./vendor/* ]] && continue
	total=$((total + 1))
	if ! php -l "$file" >/dev/null 2>&1; then
		echo "FAIL: $file"
		php -l "$file"
		fail=$((fail + 1))
	fi
done < <(find . -name '*.php' -not -path './vendor/*')

if [ "$fail" -gt 0 ]; then
	echo "Lint failed: $fail of $total files."
	exit 1
fi

echo "Lint OK: $total files."
