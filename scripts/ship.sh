#!/usr/bin/env bash
# Ships a new version of SMC Locations from the zip Claude hands over.
#
#   scripts/ship.sh "What changed"
#
# 1. Finds the newest ~/Downloads/smc-location-generator-repo*.zip (or ZIP=/path/to.zip).
# 2. Mirrors it into this repo: changed files updated, new files added, files that are
#    no longer in the zip removed (.git is never touched).
# 3. Stops if the version's tag already exists here or on GitHub, if nothing changed,
#    or if a PHP file has a syntax error (when php is installed).
# 4. Shows the version and the changed files, and asks before doing anything.
# 5. Commits, pushes, tags v<Version> and pushes the tag. The GitHub Action builds the release.
# 6. Deletes the zip, so an old one is never shipped twice.
#
# The commit message is "<Version>: <What changed>". Without a message it's just the version.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT=$(pwd)
red() { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
bold() { printf '\033[1m%s\033[0m\n' "$*"; }
die() { red "Stopped: $*"; exit 1; }

[ -f smc-location-generator.php ] && [ -d .git ] || die "run this from the smc-location-generator repo."

# --- The zip ---------------------------------------------------------------
if [ -z "${ZIP:-}" ]; then
	ZIPS=$(ls -t "$HOME"/Downloads/smc-location-generator-repo*.zip 2>/dev/null || true)
	[ -n "$ZIPS" ] || die "no smc-location-generator-repo*.zip in ~/Downloads. Download the new one first."
	ZIP=$(printf '%s\n' "$ZIPS" | head -1)
	COUNT=$(printf '%s\n' "$ZIPS" | wc -l | tr -d ' ')
	if [ "$COUNT" -gt 1 ]; then
		red "$COUNT zips in ~/Downloads. Using the newest: $(basename "$ZIP")"
	fi
fi
[ -f "$ZIP" ] || die "$ZIP not found."

git fetch -q origin --tags
if [ -n "$(git status --porcelain)" ]; then
	git status --short
	die "the repo has uncommitted changes. Commit or discard them first."
fi
if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
	die "this copy isn't the same as GitHub's main. Run: git pull"
fi

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
unzip -q "$ZIP" -d "$TMP/zip"
[ -f "$TMP/zip/smc-location-generator.php" ] || die "$(basename "$ZIP") isn't the repo zip (no smc-location-generator.php at its top level)."
rsync -a --delete --exclude '.git' "$TMP/zip/" "$ROOT/"

# --- Checks ------------------------------------------------------------------
VERSION=$(grep -m1 -E '^\s*\*\s*Version:' smc-location-generator.php | awk '{print $NF}')
TAG="v$VERSION"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-beta\.[0-9]+)?$ ]] || { git checkout -q -- . && git clean -fdq; die "Version \"$VERSION\" isn't like 1.30.0 or 1.30.0-beta.1."; }

undo_zip() { git checkout -q -- . && git clean -fdq; }

if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null 2>&1; then
	undo_zip
	die "$TAG already exists. Ask Claude for the next version number; never reuse one."
fi

if [ -z "$(git status --porcelain)" ]; then
	die "the zip is the same as what's already on GitHub. Nothing to ship."
fi

if command -v php >/dev/null 2>&1; then
	BAD=$(find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v '^No syntax errors' || true)
	if [ -n "$BAD" ]; then
		echo "$BAD"
		undo_zip
		die "PHP syntax error. Nothing was committed."
	fi
fi

# --- Confirm -----------------------------------------------------------------
MSG="$VERSION${1:+: $1}"
echo
bold "Version:  $VERSION  ($( [[ "$VERSION" == *-* ]] && echo 'Beta: staging only' || echo 'Stable: every site' ))"
bold "Zip:      $(basename "$ZIP")"
bold "Commit:   $MSG"
echo
git status --short
echo
read -r -p "Ship it? [y/N] " OK
if [ "$OK" != "y" ] && [ "$OK" != "Y" ]; then
	undo_zip
	echo "Cancelled. The repo is back as it was."
	exit 0
fi

# --- Ship --------------------------------------------------------------------
git add -A
git commit -q -m "$MSG"
git push -q origin HEAD:main
git tag "$TAG"
git push -q origin "$TAG"
rm -f "$ZIP"
green "Shipped $TAG."

if command -v gh >/dev/null 2>&1; then
	echo "Waiting for the release build..."
	sleep 5
	RUN=$(gh run list --workflow release.yml --limit 1 --json databaseId --jq '.[0].databaseId' 2>/dev/null || true)
	if [ -z "$RUN" ]; then
		echo "The release appears under GitHub > Releases in a minute or two."
	elif gh run watch "$RUN" --exit-status >/dev/null 2>&1; then
		green "Release published: $(gh release view "$TAG" --json url --jq .url 2>/dev/null || echo "$TAG")"
	else
		red "The release build failed. See why: gh run view $RUN --log-failed"
	fi
else
	echo "The release appears under GitHub > Releases in a minute or two."
fi
