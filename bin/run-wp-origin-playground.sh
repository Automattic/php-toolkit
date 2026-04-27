#!/usr/bin/env bash
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${WP_ORIGIN_PLAYGROUND_PORT:-${1:-9400}}"
CONTEXT_DIR="$ROOT_DIR/.context"
BLUEPRINT_TEMPLATE="$ROOT_DIR/plugins/wp-origin/blueprint-e2e.json"
BLUEPRINT_FILE="$CONTEXT_DIR/wp-origin-playground-$PORT.json"
CREDENTIALS_FILE="$CONTEXT_DIR/wp-origin-e2e-$PORT.json"

if command -v wp-playground >/dev/null 2>&1; then
	PLAYGROUND_CMD=(wp-playground)
elif command -v wp-playground-cli >/dev/null 2>&1; then
	PLAYGROUND_CMD=(wp-playground-cli)
else
	PLAYGROUND_CMD=(npx @wp-playground/cli@latest)
fi

cleanup() {
	if [ -n "${PLAYGROUND_PID:-}" ] && kill -0 "$PLAYGROUND_PID" 2>/dev/null; then
		kill "$PLAYGROUND_PID" 2>/dev/null || true
		wait "$PLAYGROUND_PID" 2>/dev/null || true
	fi
}
trap cleanup EXIT INT TERM

mkdir -p "$CONTEXT_DIR"
rm -f "$CREDENTIALS_FILE"

php -r '
$template = file_get_contents( $argv[1] );
if ( false === $template ) {
	fwrite( STDERR, "Unable to read blueprint template.\n" );
	exit( 1 );
}
$blueprint = str_replace( "__WP_ORIGIN_CREDENTIALS_FILE__", $argv[2], $template );
if ( false === file_put_contents( $argv[3], $blueprint ) ) {
	fwrite( STDERR, "Unable to write playground blueprint.\n" );
	exit( 1 );
}
' \
	"$BLUEPRINT_TEMPLATE" \
	"/workspace/.context/$(basename "$CREDENTIALS_FILE")" \
	"$BLUEPRINT_FILE"

PLAYGROUND_PHP_VERSION="$(php -r '$config = json_decode(file_get_contents($argv[1]), true); echo $config["preferredVersions"]["php"];' "$BLUEPRINT_FILE")"
PLAYGROUND_WP_VERSION="$(php -r '$config = json_decode(file_get_contents($argv[1]), true); echo $config["preferredVersions"]["wp"];' "$BLUEPRINT_FILE")"

"${PLAYGROUND_CMD[@]}" server \
	--port="$PORT" \
	--php="$PLAYGROUND_PHP_VERSION" \
	--wp="$PLAYGROUND_WP_VERSION" \
	--blueprint="$BLUEPRINT_FILE" \
	--mount="$ROOT_DIR:/workspace" \
	--mount="$ROOT_DIR/vendor:/wordpress/wp-content/vendor" \
	--mount="$ROOT_DIR/components:/wordpress/wp-content/components" \
	--mount="$ROOT_DIR/plugins/wp-origin:/wordpress/wp-content/plugins/wp-origin" &
PLAYGROUND_PID=$!

for _ in $(seq 1 120); do
	if [ -f "$CREDENTIALS_FILE" ]; then
		break
	fi
	if ! kill -0 "$PLAYGROUND_PID" 2>/dev/null; then
		wait "$PLAYGROUND_PID"
		exit $?
	fi
	sleep 1
done

if [ ! -f "$CREDENTIALS_FILE" ]; then
	echo "WP Origin Playground did not produce credentials at $CREDENTIALS_FILE." >&2
	exit 1
fi

USERNAME="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["username"];' "$CREDENTIALS_FILE")"
PASSWORD="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["password"];' "$CREDENTIALS_FILE")"

cat <<EOF
WP Origin Playground is ready.

Site URL: http://127.0.0.1:$PORT
Credentials file: $CREDENTIALS_FILE
Git remote: http://$USERNAME:$PASSWORD@127.0.0.1:$PORT/wp-json/git/v1/md.git

Press Ctrl-C to stop the server.
EOF

wait "$PLAYGROUND_PID"
