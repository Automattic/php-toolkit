#!/usr/bin/env bash
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK_DIR="$(mktemp -d)"
DEFAULT_PORT="${WP_ORIGIN_E2E_PORT:-}"
PLAYGROUND_LOG="$ROOT_DIR/.context/wp-origin-playground.log"
BLUEPRINT_TEMPLATE="$ROOT_DIR/plugins/wp-origin/blueprint-e2e.json"

find_free_port() {
	php -r '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (false === $server) { fwrite(STDERR, $errstr . PHP_EOL); exit(1); } $name = stream_socket_get_name($server, false); fclose($server); echo substr(strrchr($name, ":"), 1);'
}

if [ -n "$DEFAULT_PORT" ]; then
	PORT="$DEFAULT_PORT"
else
	PORT="$(find_free_port)"
fi

CREDENTIALS_FILE="$ROOT_DIR/.context/wp-origin-e2e-$PORT.json"
BLUEPRINT_FILE="$WORK_DIR/blueprint-e2e.json"

if command -v wp-playground >/dev/null 2>&1; then
	PLAYGROUND_CMD="wp-playground"
elif command -v wp-playground-cli >/dev/null 2>&1; then
	PLAYGROUND_CMD="wp-playground-cli"
else
	PLAYGROUND_CMD="npx --no-install @wp-playground/cli"
fi

cleanup() {
	if [ -n "${PLAYGROUND_PID:-}" ] && kill -0 "$PLAYGROUND_PID" 2>/dev/null; then
		kill "$PLAYGROUND_PID" 2>/dev/null || true
		wait "$PLAYGROUND_PID" 2>/dev/null || true
	fi
	rm -f "$CREDENTIALS_FILE"
	rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

mkdir -p "$ROOT_DIR/.context"
rm -f "$PLAYGROUND_LOG" "$CREDENTIALS_FILE"

cd "$ROOT_DIR"
sed "s|__WP_ORIGIN_CREDENTIALS_FILE__|/workspace/.context/$(basename "$CREDENTIALS_FILE")|g" "$BLUEPRINT_TEMPLATE" > "$BLUEPRINT_FILE"
PLAYGROUND_PHP_VERSION="$(php -r '$config = json_decode(file_get_contents($argv[1]), true); echo $config["preferredVersions"]["php"];' "$BLUEPRINT_FILE")"
PLAYGROUND_WP_VERSION="$(php -r '$config = json_decode(file_get_contents($argv[1]), true); echo $config["preferredVersions"]["wp"];' "$BLUEPRINT_FILE")"

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
	>"$PLAYGROUND_LOG" 2>&1 &
PLAYGROUND_PID=$!

for _ in $(seq 1 120); do
	if [ -f "$CREDENTIALS_FILE" ]; then
		break
	fi
	sleep 1
done

if [ ! -f "$CREDENTIALS_FILE" ]; then
	cat "$PLAYGROUND_LOG"
	echo "WP Origin e2e setup did not produce credentials." >&2
	exit 1
fi

USERNAME="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["username"];' "$CREDENTIALS_FILE")"
PASSWORD="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["password"];' "$CREDENTIALS_FILE")"

AUTH_HEADER="$(php -r 'echo base64_encode($argv[1] . ":" . $argv[2]);' "$USERNAME" "$PASSWORD")"
BASE_URL="http://127.0.0.1:$PORT"
REMOTE_AUTH_URL="http://$USERNAME:$PASSWORD@127.0.0.1:$PORT/wp-json/git/v1/md.git"
CLONE_DIR="$WORK_DIR/clone"

assert_push_summary_contains() {
	OUTPUT="$1"
	NEEDLE="$2"
	if ! printf '%s' "$OUTPUT" | grep -Fq "$NEEDLE"; then
		printf '%s\n' "$OUTPUT"
		echo "Expected push output to contain: $NEEDLE" >&2
		exit 1
	fi
}

wait_for_seed_done() {
	for _ in $(seq 1 120); do
		STATUS_JSON="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp-origin/v1/seed-status" || true)"
		STATE="$(printf '%s' "$STATUS_JSON" | php -r '$state = json_decode(stream_get_contents(STDIN), true); echo is_array($state) && isset($state["state"]) ? $state["state"] : "";')"
		if [ "$STATE" = "done" ]; then
			return
		fi
		sleep 1
	done

	cat "$PLAYGROUND_LOG"
	echo "WP Origin seeder did not finish." >&2
	exit 1
}

wait_for_seed_done
git -c protocol.version=2 clone "$REMOTE_AUTH_URL" "$CLONE_DIR"

test -f "$CLONE_DIR/post/hello-world.md"
test -f "$CLONE_DIR/page/sample-page.md"
test -f "$CLONE_DIR/wp_template/blog-home.html"
test -f "$CLONE_DIR/wp_guideline/skills/wp-origin/SKILL.md"
test -L "$CLONE_DIR/.agents/skills"
test -L "$CLONE_DIR/.claude/skills"
test -L "$CLONE_DIR/AGENTS.md"
test -L "$CLONE_DIR/CLAUDE.md"
grep -q 'Hello from WordPress' "$CLONE_DIR/post/hello-world.md"
grep -q 'Template from WordPress' "$CLONE_DIR/wp_template/blog-home.html"
grep -Fq 'title: "Hello World"' "$CLONE_DIR/post/hello-world.md"
grep -Fq 'status: "published"' "$CLONE_DIR/post/hello-world.md"
grep -Fq 'description: "Hand-authored summary."' "$CLONE_DIR/post/hello-world.md"
grep -Eq '^date: "[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z"$' "$CLONE_DIR/post/hello-world.md"
! grep -Eq '^(id|type|slug|date_gmt|modified_gmt):' "$CLONE_DIR/post/hello-world.md"
head -n 1 "$CLONE_DIR/wp_guideline/skills/wp-origin/SKILL.md" | grep -Fxq -- '---'
grep -Fq 'name: "wp-origin"' "$CLONE_DIR/wp_guideline/skills/wp-origin/SKILL.md"
grep -Fq 'description: "Guide for coding agents working in a WP Origin checkout of a WordPress site."' "$CLONE_DIR/wp_guideline/skills/wp-origin/SKILL.md"
grep -Fq '# WP Origin AGENTS.md' "$CLONE_DIR/wp_guideline/skills/wp-origin/SKILL.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/wp_guideline/skills/wp-origin/SKILL.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/.agents/skills/wp-origin/SKILL.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/.claude/skills/wp-origin/SKILL.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/AGENTS.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/CLAUDE.md"
[ "$(readlink "$CLONE_DIR/.agents/skills")" = "../wp_guideline/skills" ]
[ "$(readlink "$CLONE_DIR/.claude/skills")" = "../wp_guideline/skills" ]
[ "$(readlink "$CLONE_DIR/AGENTS.md")" = "wp_guideline/skills/wp-origin/SKILL.md" ]
[ "$(readlink "$CLONE_DIR/CLAUDE.md")" = "wp_guideline/skills/wp-origin/SKILL.md" ]

POST_ID="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts?slug=hello-world&context=edit" | php -r '
$posts = json_decode(stream_get_contents(STDIN), true);
echo $posts[0]["id"];
')"
PAGE_ID="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=sample-page&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
echo $pages[0]["id"];
')"
REVISION_COUNT_BEFORE="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts/$POST_ID/revisions?context=edit" | php -r '
$revisions = json_decode(stream_get_contents(STDIN), true);
echo count($revisions);
')"

cd "$CLONE_DIR"
git config user.name "WP Origin E2E"
git config user.email "wp-origin-e2e@example.com"

php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents = str_replace("Hello from WordPress", "Updated from Git", $contents);
file_put_contents($path, $contents);
' "$CLONE_DIR/post/hello-world.md"

git add post/hello-world.md
git commit -m "Update hello world from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'WP Origin applied 1 content change:'
assert_push_summary_contains "$PUSH_OUTPUT" '/hello-world/'

UPDATED_CONTENT="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts/$POST_ID?context=edit" | php -r '
$post = json_decode(stream_get_contents(STDIN), true);
echo $post["content"]["raw"];
')"
printf '%s' "$UPDATED_CONTENT" | grep -q 'Updated from Git'

REVISION_COUNT_AFTER_UPDATE="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts/$POST_ID/revisions?context=edit" | php -r '
$revisions = json_decode(stream_get_contents(STDIN), true);
echo count($revisions);
')"
[ "$REVISION_COUNT_AFTER_UPDATE" -gt "$REVISION_COUNT_BEFORE" ]
git pull --rebase origin trunk

php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents = str_replace("Template from WordPress", "Template updated from Git", $contents);
file_put_contents($path, $contents);
' "$CLONE_DIR/wp_template/blog-home.html"

printf '%s' '<!-- wp:paragraph --><p>Created template from Git.</p><!-- /wp:paragraph -->' > "$CLONE_DIR/wp_template/custom-blog-card.html"
git add wp_template/blog-home.html wp_template/custom-blog-card.html
git commit -m "Update template HTML from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'WP Origin applied 2 content changes:'
assert_push_summary_contains "$PUSH_OUTPUT" 'wp_template'

rm "$CLONE_DIR/wp_template/custom-blog-card.html"
git add -A
git commit -m "Delete template HTML from Git"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected template deletion push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because template HTML files cannot be deleted or renamed.'
git reset --hard HEAD~1 >/dev/null

git mv wp_template/custom-blog-card.html wp_template/renamed-blog-card.html
git commit -m "Rename template HTML from Git"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected template rename push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because template HTML files cannot be deleted or renamed.'
git reset --hard HEAD~1 >/dev/null

php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents .= "\nEscaped path literal: C:\\\\Temp\\\\wp-origin\nQuoted JSON literal: {\"windows\":\"C:\\\\\\\\Temp\\\\\\\\wp-origin\"}\n";
file_put_contents($path, $contents);
' "$CLONE_DIR/post/hello-world.md"

EXACT_COMMIT_MESSAGE='Preserve C:\\Temp\\wp-origin in commit metadata'
git add post/hello-world.md
git commit -m "$EXACT_COMMIT_MESSAGE"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'WP Origin applied 1 content change:'
assert_push_summary_contains "$PUSH_OUTPUT" '/hello-world/'

EXACT_CLONE_DIR="$WORK_DIR/exact-clone"
git -c protocol.version=2 clone "$REMOTE_AUTH_URL" "$EXACT_CLONE_DIR"
EXACT_COMMIT_HASH="$(git -C "$EXACT_CLONE_DIR" log --grep='Preserve' --format=%H -n 1)"
[ -n "$EXACT_COMMIT_HASH" ]
git -C "$EXACT_CLONE_DIR" show "$EXACT_COMMIT_HASH:post/hello-world.md" > "$WORK_DIR/exact-blob.md"
grep -Fq 'Escaped path literal: C:\\Temp\\wp-origin' "$WORK_DIR/exact-blob.md"
grep -Fq 'Quoted JSON literal: {"windows":"C:\\\\Temp\\\\wp-origin"}' "$WORK_DIR/exact-blob.md"
ACTUAL_COMMIT_MESSAGE="$(git -C "$EXACT_CLONE_DIR" show -s --format=%B "$EXACT_COMMIT_HASH" | php -r 'echo rtrim(stream_get_contents(STDIN), "\r\n");')"
[ "$ACTUAL_COMMIT_MESSAGE" = "$EXACT_COMMIT_MESSAGE" ]
git pull --rebase origin trunk

php -r '
$path = $argv[1];
$markdown = "---\n"
	. "title: \"Created From Git\"\n"
	. "date: \"2024-01-15T10:00:00Z\"\n"
	. "status: \"published\"\n"
	. "description: \"Created from Git summary.\"\n"
	. "---\n\n"
	. "Created from Git.\n";
file_put_contents($path, $markdown);
' "$CLONE_DIR/post/created-from-git.md"

php -r '
$path = $argv[1];
$markdown = "---\n"
	. "type: \"page\"\n"
	. "slug: \"page-from-git\"\n"
	. "status: \"publish\"\n"
	. "title: \"Page From Git\"\n"
	. "---\n\n"
	. "Page created from Git.\n";
file_put_contents($path, $markdown);
' "$CLONE_DIR/page/page-from-git.md"

rm "$CLONE_DIR/page/sample-page.md"
git add post/created-from-git.md page/page-from-git.md page/sample-page.md
git commit -m "Create and delete content from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'WP Origin applied 3 content changes:'
assert_push_summary_contains "$PUSH_OUTPUT" '/created-from-git/'
assert_push_summary_contains "$PUSH_OUTPUT" '/page-from-git/'
assert_push_summary_contains "$PUSH_OUTPUT" '/sample-page/'

CREATED_POST_CONTENT="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts?slug=created-from-git&context=edit" | php -r '
$posts = json_decode(stream_get_contents(STDIN), true);
echo $posts[0]["content"]["raw"];
')"
printf '%s' "$CREATED_POST_CONTENT" | grep -q 'Created from Git'
CREATED_POST="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts?slug=created-from-git&context=edit")"
printf '%s' "$CREATED_POST" | php -r '
$posts = json_decode(stream_get_contents(STDIN), true);
if ("publish" !== $posts[0]["status"]) {
	exit(1);
}
if ("Created from Git summary." !== $posts[0]["excerpt"]["raw"]) {
	exit(1);
}
if ("2024-01-15T10:00:00" !== $posts[0]["date_gmt"]) {
	exit(1);
}
'

CREATED_PAGE_CONTENT="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=page-from-git&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
echo $pages[0]["content"]["raw"];
')"
printf '%s' "$CREATED_PAGE_CONTENT" | grep -q 'Page created from Git'
CREATED_PAGE_STATUS="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=page-from-git&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
echo $pages[0]["status"];
')"
[ "$CREATED_PAGE_STATUS" = "publish" ]

TRASHED_PAGE_STATUS="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages/$PAGE_ID?context=edit" | php -r '
$page = json_decode(stream_get_contents(STDIN), true);
echo $page["status"];
')"
[ "$TRASHED_PAGE_STATUS" = "trash" ]
git pull --rebase origin trunk

php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents = str_replace("Updated from Git", "Stale local edit", $contents);
file_put_contents($path, $contents);
' "$CLONE_DIR/post/hello-world.md"

git add post/hello-world.md
git commit -m "Create stale local edit"

UPDATE_PAYLOAD='{"content":"<!-- wp:paragraph --><p>Updated in WordPress</p><!-- /wp:paragraph -->"}'
curl -sS \
	-X POST \
	-H "Authorization: Basic $AUTH_HEADER" \
	-H "Content-Type: application/json" \
	-d "$UPDATE_PAYLOAD" \
	-f \
	"$BASE_URL/wp-json/wp/v2/posts/$POST_ID?context=edit" >/dev/null

if git push origin trunk; then
	echo "Expected stale push to fail." >&2
	exit 1
fi

git fetch origin trunk
git reset --hard FETCH_HEAD >/dev/null
grep -q 'Updated in WordPress' "$CLONE_DIR/post/hello-world.md"
