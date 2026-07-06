#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"

ZIP_PATH="${1:-$PROJECT_DIR/dist/plugins/push-md.zip}"
PLUGIN_SLUG="${WPORG_PLUGIN_SLUG:-push-md}"
SVN_URL="${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/$PLUGIN_SLUG/}"
COMMIT_MESSAGE="${WPORG_COMMIT_MESSAGE:-}"
DRY_RUN="${WPORG_DRY_RUN:-0}"
SVN_DIR="${WPORG_SVN_DIR:-}"
USERNAME="${WPORG_USERNAME:-}"
PASSWORD="${WPORG_PASSWORD:-}"

TEMP_SVN_DIR=""
TEMP_EXTRACT_DIR=""

usage() {
	cat <<USAGE
Usage: bin/deploy-push-md-wporg-svn.sh [dist/plugins/push-md.zip]

Deploys the Push MD release zip to the WordPress.org plugin SVN repository.

Environment:
  WPORG_PLUGIN_SLUG       Plugin slug. Default: push-md
  WPORG_SVN_URL           SVN URL. Default: https://plugins.svn.wordpress.org/\$WPORG_PLUGIN_SLUG/
  WPORG_SVN_DIR           Optional existing SVN checkout path.
  WPORG_USERNAME          WordPress.org SVN username.
  WPORG_PASSWORD          WordPress.org SVN password.
  WPORG_COMMIT_MESSAGE    Optional SVN commit message.
  WPORG_DRY_RUN=1         Prepare the working copy and print status without committing.
USAGE
}

cleanup() {
	if [ -n "$TEMP_EXTRACT_DIR" ] && [ -d "$TEMP_EXTRACT_DIR" ]; then
		rm -rf "$TEMP_EXTRACT_DIR"
	fi
	if [ "$DRY_RUN" != "1" ] && [ -n "$TEMP_SVN_DIR" ] && [ -d "$TEMP_SVN_DIR" ]; then
		rm -rf "$TEMP_SVN_DIR"
	fi
}
trap cleanup EXIT

fail() {
	echo "$1" >&2
	exit 1
}

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		fail "Missing required command: $1"
	fi
}

trim_carriage_returns() {
	tr -d '\r'
}

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
	usage
	exit 0
fi

require_command svn
require_command unzip
require_command zipinfo
require_command rsync

if [ ! -f "$ZIP_PATH" ]; then
	fail "Missing Push MD release zip: $ZIP_PATH"
fi

ZIP_CONTENTS="$( zipinfo -1 "$ZIP_PATH" )"

TOP_LEVEL_DIRS="$( printf '%s\n' "$ZIP_CONTENTS" | sed 's#/.*##' | sort -u )"
TOP_LEVEL_DIR_COUNT="$( printf '%s\n' "$TOP_LEVEL_DIRS" | grep -c . || true )"
if [ "$TOP_LEVEL_DIR_COUNT" -ne 1 ]; then
	fail "Expected exactly one top-level directory in $ZIP_PATH."
fi

PACKAGE_ROOT="$TOP_LEVEL_DIRS"
if [ "$PACKAGE_ROOT" != "$PLUGIN_SLUG" ]; then
	fail "Expected zip top-level directory '$PLUGIN_SLUG', found '$PACKAGE_ROOT'."
fi

README_PATH="$PACKAGE_ROOT/readme.txt"
PLUGIN_FILE_PATH="$PACKAGE_ROOT/$PLUGIN_SLUG.php"

if ! grep -Fxq "$README_PATH" <<< "$ZIP_CONTENTS"; then
	fail "Missing $README_PATH in $ZIP_PATH"
fi
if ! grep -Fxq "$PLUGIN_FILE_PATH" <<< "$ZIP_CONTENTS"; then
	fail "Missing $PLUGIN_FILE_PATH in $ZIP_PATH"
fi

STABLE_TAG="$(
	unzip -p "$ZIP_PATH" "$README_PATH" |
		sed -n 's/^Stable tag:[[:space:]]*//p' |
		trim_carriage_returns |
		head -n 1
)"
PLUGIN_VERSION="$(
	unzip -p "$ZIP_PATH" "$PLUGIN_FILE_PATH" |
		sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' |
		trim_carriage_returns |
		head -n 1
)"

if [ -z "$STABLE_TAG" ]; then
	fail "Unable to read Stable tag from $README_PATH"
fi
if [ -z "$PLUGIN_VERSION" ]; then
	fail "Unable to read Version from $PLUGIN_FILE_PATH"
fi
if [ "$STABLE_TAG" != "$PLUGIN_VERSION" ]; then
	fail "Version mismatch: readme Stable tag is $STABLE_TAG, plugin Version is $PLUGIN_VERSION"
fi
if [[ ! "$STABLE_TAG" =~ ^[0-9]+(\.[0-9]+)+$ ]]; then
	fail "WordPress.org SVN release tags must use numbers and periods only. Refusing to deploy: $STABLE_TAG"
fi

VERSION="$STABLE_TAG"
if [ -z "$COMMIT_MESSAGE" ]; then
	COMMIT_MESSAGE="Release Push MD $VERSION"
fi

SVN_AUTH_ARGS=()
if [ -n "$USERNAME" ]; then
	SVN_AUTH_ARGS+=( --username "$USERNAME" )
fi
if [ -n "$PASSWORD" ]; then
	if [ -z "$USERNAME" ]; then
		fail "WPORG_PASSWORD was provided without WPORG_USERNAME."
	fi
	SVN_AUTH_ARGS+=( --password "$PASSWORD" --no-auth-cache )
fi
if [ "${CI:-}" = "true" ] || [ -n "$PASSWORD" ]; then
	SVN_AUTH_ARGS+=( --non-interactive )
fi

if [ -z "$SVN_DIR" ]; then
	TEMP_SVN_DIR="$(mktemp -d "${TMPDIR:-/tmp}/push-md-wporg-svn.XXXXXX")"
	SVN_DIR="$TEMP_SVN_DIR"
fi
TEMP_EXTRACT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/push-md-wporg-zip.XXXXXX")"

if [ -d "$SVN_DIR/.svn" ]; then
	echo "Updating existing SVN checkout: $SVN_DIR"
	svn update "${SVN_AUTH_ARGS[@]}" "$SVN_DIR"
else
	echo "Checking out WordPress.org SVN repository: $SVN_URL"
	rm -rf "$SVN_DIR"
	svn checkout "${SVN_AUTH_ARGS[@]}" "$SVN_URL" "$SVN_DIR"
fi

mkdir -p "$SVN_DIR/trunk" "$SVN_DIR/tags" "$SVN_DIR/assets"

if [ -e "$SVN_DIR/tags/$VERSION" ]; then
	fail "SVN tag already exists: tags/$VERSION"
fi

echo "Unpacking $ZIP_PATH for Push MD $VERSION"
unzip -q "$ZIP_PATH" -d "$TEMP_EXTRACT_DIR"

find "$SVN_DIR/trunk" -mindepth 1 -maxdepth 1 ! -name '.svn' -exec rm -rf {} +
rsync -a --delete "$TEMP_EXTRACT_DIR/$PACKAGE_ROOT/" "$SVN_DIR/trunk/"

cd "$SVN_DIR"

svn add --force trunk tags assets --quiet

svn status trunk | awk '$1 == "!" { print substr($0, 9) }' | while IFS= read -r missing_path; do
	if [ -n "$missing_path" ]; then
		svn delete --force "$missing_path" >/dev/null
	fi
done

svn copy trunk "tags/$VERSION" >/dev/null

echo "Prepared WordPress.org SVN release:"
svn status

if [ "$DRY_RUN" = "1" ]; then
	echo "WPORG_DRY_RUN=1; not committing."
	if [ -n "$TEMP_SVN_DIR" ]; then
		echo "SVN working copy left at: $SVN_DIR"
	fi
	exit 0
fi

svn commit "${SVN_AUTH_ARGS[@]}" -m "$COMMIT_MESSAGE"
