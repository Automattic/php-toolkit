#!/usr/bin/env bash
# Verify WP Origin Git history survives a server restart.
#
# Phase 1: boot Playground with a host-mounted WordPress database
# directory, push a commit through the wpdb-backed filesystem, kill
# the server.
# Phase 2: boot a fresh Playground process pointed at the same
# database directory, clone, and assert the commit pushed in phase 1
# is still present with the same hash. That proves the GitRepository's
# wpdb-backed object store is the actual persistence layer — nothing
# was held in process memory.
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK_DIR="$(mktemp -d)"
DB_DIR="$WORK_DIR/wp-database"
PHASE1_LOG="$ROOT_DIR/.context/wp-origin-persistence-phase1.log"
PHASE2_LOG="$ROOT_DIR/.context/wp-origin-persistence-phase2.log"
BLUEPRINT_TEMPLATE="$ROOT_DIR/plugins/wp-origin/blueprint-e2e.json"

mkdir -p "$ROOT_DIR/.context" "$DB_DIR"

find_free_port() {
	php -r '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (false === $server) { fwrite(STDERR, $errstr . PHP_EOL); exit(1); } $name = stream_socket_get_name($server, false); fclose($server); echo substr(strrchr($name, ":"), 1);'
}

PORT="${WP_ORIGIN_PERSISTENCE_PORT:-$(find_free_port)}"
CREDENTIALS_FILE="$ROOT_DIR/.context/wp-origin-persistence-$PORT.json"
BLUEPRINT_FILE="$WORK_DIR/blueprint-persistence.json"

if command -v wp-playground >/dev/null 2>&1; then
	PLAYGROUND_CMD="wp-playground"
elif command -v wp-playground-cli >/dev/null 2>&1; then
	PLAYGROUND_CMD="wp-playground-cli"
else
	PLAYGROUND_CMD="npx --no-install @wp-playground/cli"
fi

PLAYGROUND_PID=""

cleanup() {
	if [ -n "$PLAYGROUND_PID" ] && kill -0 "$PLAYGROUND_PID" 2>/dev/null; then
		kill "$PLAYGROUND_PID" 2>/dev/null || true
		wait "$PLAYGROUND_PID" 2>/dev/null || true
	fi
	rm -f "$CREDENTIALS_FILE"
	rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

sed "s|__WP_ORIGIN_CREDENTIALS_FILE__|/workspace/.context/$(basename "$CREDENTIALS_FILE")|g" "$BLUEPRINT_TEMPLATE" > "$BLUEPRINT_FILE"
PLAYGROUND_PHP_VERSION="$(php -r '$config = json_decode(file_get_contents($argv[1]), true); echo $config["preferredVersions"]["php"];' "$BLUEPRINT_FILE")"
PLAYGROUND_WP_VERSION="$(php -r '$config = json_decode(file_get_contents($argv[1]), true); echo $config["preferredVersions"]["wp"];' "$BLUEPRINT_FILE")"

start_playground() {
	local log_file="$1"
	rm -f "$CREDENTIALS_FILE"
	cd "$ROOT_DIR"
	export GIT_TERMINAL_PROMPT=0
	$PLAYGROUND_CMD server \
		--port="$PORT" \
		--php="$PLAYGROUND_PHP_VERSION" \
		--wp="$PLAYGROUND_WP_VERSION" \
		--blueprint="$BLUEPRINT_FILE" \
		--mount="$ROOT_DIR:/workspace" \
		--mount="$ROOT_DIR/vendor:/wordpress/wp-content/vendor" \
		--mount="$ROOT_DIR/components:/wordpress/wp-content/components" \
		--mount="$ROOT_DIR/plugins/wp-origin:/wordpress/wp-content/plugins/wp-origin" \
		--mount="$DB_DIR:/wordpress/wp-content/database" \
		>"$log_file" 2>&1 &
	PLAYGROUND_PID=$!

	for _ in $(seq 1 120); do
		if [ -f "$CREDENTIALS_FILE" ]; then
			return 0
		fi
		sleep 1
	done

	cat "$log_file"
	echo "Playground did not produce credentials in time." >&2
	return 1
}

stop_playground() {
	if [ -n "$PLAYGROUND_PID" ] && kill -0 "$PLAYGROUND_PID" 2>/dev/null; then
		kill "$PLAYGROUND_PID" 2>/dev/null || true
		wait "$PLAYGROUND_PID" 2>/dev/null || true
	fi
	PLAYGROUND_PID=""
}

# ---- Phase 1: push a commit, capture its hash, shut down --------------------

start_playground "$PHASE1_LOG"

USERNAME="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["username"];' "$CREDENTIALS_FILE")"
PASSWORD="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["password"];' "$CREDENTIALS_FILE")"
REMOTE_AUTH_URL="http://$USERNAME:$PASSWORD@127.0.0.1:$PORT/wp-json/git/v1/md.git"
PHASE1_CLONE="$WORK_DIR/phase1-clone"

git -c protocol.version=2 clone "$REMOTE_AUTH_URL" "$PHASE1_CLONE"
cd "$PHASE1_CLONE"
git config user.name "WP Origin Persistence"
git config user.email "wp-origin-persistence@example.com"

php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents = str_replace("Hello from WordPress", "Survives restart", $contents);
file_put_contents($path, $contents);
' "$PHASE1_CLONE/post/hello-world.md"

git add post/hello-world.md
git commit -m "Persistence marker commit"
git push origin trunk

PHASE1_HEAD="$(git -C "$PHASE1_CLONE" rev-parse HEAD)"
[ -n "$PHASE1_HEAD" ]

stop_playground

# Sanity: the SQLite DB the server wrote into must exist on the host.
test -d "$DB_DIR"
find "$DB_DIR" -type f -name '*.sqlite' | grep -q .

# ---- Phase 2: restart with the same DB, assert history survived -------------

start_playground "$PHASE2_LOG"

PHASE2_CLONE="$WORK_DIR/phase2-clone"
git -c protocol.version=2 clone "$REMOTE_AUTH_URL" "$PHASE2_CLONE"

# The phase-1 commit must be reachable in the fresh server's history.
git -C "$PHASE2_CLONE" cat-file -e "$PHASE1_HEAD"
git -C "$PHASE2_CLONE" log --format=%H | grep -q "^$PHASE1_HEAD$"

# And the file content the phase-1 push produced must be served on
# `git clone` after the restart.
grep -q 'Survives restart' "$PHASE2_CLONE/post/hello-world.md"

echo "Persistence test passed. Phase 1 head $PHASE1_HEAD survived restart."
