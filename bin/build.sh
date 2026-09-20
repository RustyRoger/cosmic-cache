#!/usr/bin/env bash
#
# Build the distributable cosmic-cache.zip.
#
# Steps:
#   1. bash tests/lint/lint.sh         — PHP lint gate
#   2. vendor/bin/phpunit --no-coverage— unit-test gate
#   3. php bin/extract-pot.php         — regenerate languages/cosmic-cache.pot
#   4. zip the release tree            — cosmic-cache.zip in the repo root
#
# The archive contains only files WordPress needs at runtime: plugin entry,
# src/, dropins/ (drop-in templates compiled on activation), views/,
# languages/, assets/, readme.txt and uninstall.php. Dev tooling (tests/,
# bin/, composer files, PHPUnit config) is excluded.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> Linting PHP files"
bash tests/lint/lint.sh

echo "==> Running unit tests"
vendor/bin/phpunit --do-not-cache-result --no-coverage >/dev/null

echo "==> Regenerating language template"
php bin/extract-pot.php

if command -v msgfmt >/dev/null 2>&1; then
	echo "==> Validating .pot compiles"
	msgfmt --check --output-file=/dev/null languages/cosmic-cache.pot

	echo "==> Compiling .po translations (.mo)"
	for po in languages/*.po; do
		[ -e "$po" ] || continue
		base="${po%.po}"
		msgfmt --check --output-file="$base.mo" "$po"
		echo "   $po -> $base.mo"
	done
	# Drop stale .mo files whose source translation was removed.
	for mo in languages/*.mo; do
		[ -e "$mo" ] || continue
		base="${mo%.mo}"
		[ -f "$base.po" ] || { rm -f "$mo"; echo "   removed stale $mo"; }
	done
else
	echo "==> msgfmt not found — skipping .pot validation and .po compilation"
fi

echo "==> Packaging cosmic-cache.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/cosmic-cache"
cp -R src dropins views languages assets "$TMP/cosmic-cache/"
cp cosmic-cache.php readme.txt uninstall.php "$TMP/cosmic-cache/"

rm -f cosmic-cache.zip
( cd "$TMP" && zip -qr "$ROOT/cosmic-cache.zip" cosmic-cache )
echo "==> Built $(ls -lh "$ROOT/cosmic-cache.zip" | awk '{print $5}') cosmic-cache.zip"

echo "==> Verifying release tree (fail-closed packaging)"
for f in cosmic-cache.php uninstall.php readme.txt \
	src/Plugin.php src/Config.php \
	dropins/advanced-cache.php dropins/object-cache.php \
	views/page-cache.php views/optimization.php; do
	[ -f "$TMP/cosmic-cache/$f" ] || { echo "MISSING FROM ZIP: $f"; exit 1; }
done
echo "    release tree OK"