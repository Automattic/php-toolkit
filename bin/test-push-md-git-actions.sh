#!/usr/bin/env bash
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK_DIR="$(mktemp -d)"
DEFAULT_PORT="${PUSH_MD_E2E_PORT:-}"
PLAYGROUND_LOG="$ROOT_DIR/.context/push-md-playground.log"
BLUEPRINT_TEMPLATE="$ROOT_DIR/plugins/push-md/blueprint-e2e.json"

find_free_port() {
	php -r '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (false === $server) { fwrite(STDERR, $errstr . PHP_EOL); exit(1); } $name = stream_socket_get_name($server, false); fclose($server); echo substr(strrchr($name, ":"), 1);'
}

if [ -n "$DEFAULT_PORT" ]; then
	PORT="$DEFAULT_PORT"
else
	PORT="$(find_free_port)"
fi

CREDENTIALS_FILE="$ROOT_DIR/.context/push-md-e2e-$PORT.json"
BLUEPRINT_FILE="$WORK_DIR/blueprint-e2e.json"
MU_PLUGINS_DIR="$WORK_DIR/mu-plugins"

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
mkdir -p "$MU_PLUGINS_DIR"
rm -f "$PLAYGROUND_LOG" "$CREDENTIALS_FILE"
cp "$ROOT_DIR/plugins/push-md/Tests/ci-mu-test-helper.php" "$MU_PLUGINS_DIR/push-md-ci-test-helper.php"
cat > "$MU_PLUGINS_DIR/push-md-e2e-auth.php" <<'PHP'
<?php
add_filter( 'wp_is_application_passwords_available', '__return_true' );
PHP

cd "$ROOT_DIR"
sed "s|__PUSH_MD_CREDENTIALS_FILE__|/workspace/.context/$(basename "$CREDENTIALS_FILE")|g" "$BLUEPRINT_TEMPLATE" > "$BLUEPRINT_FILE"
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
	--mount="$ROOT_DIR/plugins/push-md:/wordpress/wp-content/plugins/push-md" \
	--mount="$MU_PLUGINS_DIR:/wordpress/wp-content/mu-plugins" \
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
	echo "Push MD e2e setup did not produce credentials." >&2
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
		STATUS_JSON="$(curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/push-md/v1/seed-status" || true)"
		STATE="$(printf '%s' "$STATUS_JSON" | php -r '$state = json_decode(stream_get_contents(STDIN), true); echo is_array($state) && isset($state["state"]) ? $state["state"] : "";')"
		if [ "$STATE" = "done" ]; then
			return
		fi
		sleep 1
	done

	cat "$PLAYGROUND_LOG"
	echo "Push MD seeder did not finish." >&2
	exit 1
}

create_page() {
	SLUG_ARG="$1"
	TITLE_ARG="$2"
	PARENT_ARG="$3"
	CONTENT_ARG="$4"
	RESPONSE="$(
		php -r '
		$payload = array(
			"slug"    => $argv[1],
			"title"   => $argv[2],
			"status"  => "publish",
			"content" => "<!-- wp:paragraph --><p>" . $argv[4] . "</p><!-- /wp:paragraph -->",
		);
		if ("0" !== $argv[3]) {
			$payload["parent"] = (int) $argv[3];
		}
		echo json_encode($payload);
		' "$SLUG_ARG" "$TITLE_ARG" "$PARENT_ARG" "$CONTENT_ARG" |
			curl -sS -f \
				-X POST \
				-H "Authorization: Basic $AUTH_HEADER" \
				-H "Content-Type: application/json" \
				--data-binary @- \
				"$BASE_URL/wp-json/wp/v2/pages?context=edit"
	)"
	printf '%s' "$RESPONSE" | php -r '
	$page = json_decode(stream_get_contents(STDIN), true);
	if (!is_array($page) || !isset($page["id"])) {
		exit(1);
	}
	echo (int) $page["id"];
	'
}

wait_for_seed_done
HIERARCHY_SUFFIX="hierarchy-$(date +%s)-$$"
PARENT_A_SLUG="parent-a-$HIERARCHY_SUFFIX"
PARENT_B_SLUG="parent-b-$HIERARCHY_SUFFIX"
SHARED_CHILD_SLUG="shared-child-$HIERARCHY_SUFFIX"
PARENT_A_ID="$(create_page "$PARENT_A_SLUG" "Parent A $HIERARCHY_SUFFIX" 0 "Parent A $HIERARCHY_SUFFIX")"
PARENT_B_ID="$(create_page "$PARENT_B_SLUG" "Parent B $HIERARCHY_SUFFIX" 0 "Parent B $HIERARCHY_SUFFIX")"
create_page "$SHARED_CHILD_SLUG" "Shared Child A $HIERARCHY_SUFFIX" "$PARENT_A_ID" "Shared Child A $HIERARCHY_SUFFIX" >/dev/null
create_page "$SHARED_CHILD_SLUG" "Shared Child B $HIERARCHY_SUFFIX" "$PARENT_B_ID" "Shared Child B $HIERARCHY_SUFFIX" >/dev/null

TRASHED_PARENT_SUFFIX="trashed-parent-$(date +%s)-$$"
TRASHED_PARENT_ID="$(create_page "parent-$TRASHED_PARENT_SUFFIX" "Parent $TRASHED_PARENT_SUFFIX" 0 "Parent $TRASHED_PARENT_SUFFIX")"
create_page "child-$TRASHED_PARENT_SUFFIX" "Child $TRASHED_PARENT_SUFFIX" "$TRASHED_PARENT_ID" "Child $TRASHED_PARENT_SUFFIX" >/dev/null
curl -sS -f \
	-X DELETE \
	-H "Authorization: Basic $AUTH_HEADER" \
	"$BASE_URL/wp-json/wp/v2/pages/$TRASHED_PARENT_ID?context=edit" >/dev/null
if git -c protocol.version=2 ls-remote "$REMOTE_AUTH_URL" >/dev/null 2>&1; then
	echo "Expected clone advertisement to fail while a published page has a trashed parent." >&2
	exit 1
fi
curl -sS -f \
	-X POST \
	-H "Authorization: Basic $AUTH_HEADER" \
	-H "Content-Type: application/json" \
	-d '{"status":"publish"}' \
	"$BASE_URL/wp-json/wp/v2/pages/$TRASHED_PARENT_ID?context=edit" >/dev/null

git -c protocol.version=2 clone "$REMOTE_AUTH_URL" "$CLONE_DIR"
if [ -n "$(git -C "$CLONE_DIR" status --porcelain)" ]; then
	git -C "$CLONE_DIR" status --short >&2
	echo "Expected a clean worktree immediately after clone." >&2
	exit 1
fi

test -f "$CLONE_DIR/post/hello-world.md"
test -f "$CLONE_DIR/page/sample-page.md"
test -f "$CLONE_DIR/page/$PARENT_A_SLUG/$SHARED_CHILD_SLUG.md"
test -f "$CLONE_DIR/page/$PARENT_B_SLUG/$SHARED_CHILD_SLUG.md"
grep -Fq "Shared Child A $HIERARCHY_SUFFIX" "$CLONE_DIR/page/$PARENT_A_SLUG/$SHARED_CHILD_SLUG.md"
grep -Fq "Shared Child B $HIERARCHY_SUFFIX" "$CLONE_DIR/page/$PARENT_B_SLUG/$SHARED_CHILD_SLUG.md"
test -f "$CLONE_DIR/wp_template/blog-home.html"
find "$CLONE_DIR/wp_template" -mindepth 2 -name '*.html' | grep -q .
find "$CLONE_DIR/wp_template_part" -mindepth 2 -name '*.html' | grep -q .
find "$CLONE_DIR/wp_theme" -mindepth 2 -maxdepth 2 -name theme.json | grep -q .
find "$CLONE_DIR/wp_global_styles" -maxdepth 1 -name '*.json' | grep -q .
test -f "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md"
test -f "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
test -L "$CLONE_DIR/.agents/skills"
test -L "$CLONE_DIR/.claude/skills"
test -L "$CLONE_DIR/AGENTS.md"
test -L "$CLONE_DIR/CLAUDE.md"
grep -q 'Hello from WordPress' "$CLONE_DIR/post/hello-world.md"
grep -q 'Template from WordPress' "$CLONE_DIR/wp_template/blog-home.html"
git -C "$CLONE_DIR" log --format=%s --reverse | sed -n '1p' | grep -Fxq 'Initial theme base from WordPress'
git -C "$CLONE_DIR" log --format=%s --reverse | sed -n '2p' | grep -Fxq 'Initial import from WordPress'
grep -Fq 'title: "Hello World"' "$CLONE_DIR/post/hello-world.md"
grep -Fq 'status: "published"' "$CLONE_DIR/post/hello-world.md"
grep -Fq 'description: "Hand-authored summary."' "$CLONE_DIR/post/hello-world.md"
grep -Eq '^date: "[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z"$' "$CLONE_DIR/post/hello-world.md"
grep -Eq '^id: "[1-9][0-9]*"$' "$CLONE_DIR/post/hello-world.md"
! grep -Eq '^(type|slug|date_gmt|modified_gmt):' "$CLONE_DIR/post/hello-world.md"
head -n 1 "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md" | grep -Fxq -- '---'
grep -Fq 'name: "push-md"' "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md"
grep -Fq 'description: "Guide for coding agents working in a Push MD checkout of a WordPress site."' "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md"
grep -Fq '# Push MD AGENTS.md' "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md"
grep -Fq '`wp_global_styles/{theme}.json` contains the editable Global Styles overlay' "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md"
grep -Fq 'do not create flattened files such as `wp_template_part/twentytwentyfive-footer.html`' "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md"
grep -Fq 'The customized database post keeps the slug `footer` and stores `twentytwentyfive` in the `wp_theme` taxonomy.' "$CLONE_DIR/wp_guideline/skills/push-md/SKILL.md"
head -n 1 "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md" | grep -Fxq -- '---'
grep -Fq 'name: "push-md-template-editor"' "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
grep -Fq 'description: "Edit Push MD block theme templates and template parts as raw Gutenberg HTML while preserving Site Editor compatibility."' "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
grep -Fq '# Push MD Template Editor' "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
grep -Fq 'maps to the template-part ID `twentytwentyfive//footer`' "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
grep -Fq 'Do not flatten theme-scoped paths into files such as `wp_template_part/twentytwentyfive-footer.html`' "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
grep -Fq 'Edit `wp_global_styles/{theme}.json` when the user asks for site-wide theme.json-style changes.' "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
grep -Fq 'Prefer editable core blocks' "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
grep -Fq 'Run `git status --short` before committing or pushing' "$CLONE_DIR/wp_guideline/skills/push-md-template-editor/SKILL.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/.agents/skills/push-md/SKILL.md"
grep -Fq '# Push MD Template Editor' "$CLONE_DIR/.agents/skills/push-md-template-editor/SKILL.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/.claude/skills/push-md/SKILL.md"
grep -Fq '# Push MD Template Editor' "$CLONE_DIR/.claude/skills/push-md-template-editor/SKILL.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/AGENTS.md"
grep -Fq 'This repository is a Git checkout of a WordPress site' "$CLONE_DIR/CLAUDE.md"
[ "$(readlink "$CLONE_DIR/.agents/skills")" = "../wp_guideline/skills" ]
[ "$(readlink "$CLONE_DIR/.claude/skills")" = "../wp_guideline/skills" ]
[ "$(readlink "$CLONE_DIR/AGENTS.md")" = "wp_guideline/skills/push-md/SKILL.md" ]
[ "$(readlink "$CLONE_DIR/CLAUDE.md")" = "wp_guideline/skills/push-md/SKILL.md" ]

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
git config user.name "Push MD E2E"
git config user.email "push-md-e2e@example.com"

grep -Fq "id: \"$PAGE_ID\"" "$CLONE_DIR/page/sample-page.md"
git mv page/sample-page.md page/renamed-sample-page.md
php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents = str_replace("Page from WordPress", "Renamed page from Git", $contents);
file_put_contents($path, $contents);
' "$CLONE_DIR/page/renamed-sample-page.md"
git add -A
git commit -m "Rename page from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'Push MD applied 1 content change:'
assert_push_summary_contains "$PUSH_OUTPUT" '/renamed-sample-page/'
curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=renamed-sample-page&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
if (!is_array($pages) || empty($pages)) {
	exit(1);
}
if ((int) $argv[1] !== (int) $pages[0]["id"]) {
	exit(1);
}
if (false === strpos($pages[0]["content"]["raw"], "Renamed page from Git")) {
	exit(1);
}
' "$PAGE_ID"
curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=sample-page&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
if (array() !== $pages) {
	exit(1);
}
'
git pull --rebase origin trunk

GIT_CHILD_SLUG="git-child-$HIERARCHY_SUFFIX"
cat > "$CLONE_DIR/page/$PARENT_A_SLUG/$GIT_CHILD_SLUG.md" <<MARKDOWN
---
status: "publish"
title: "Git Child $HIERARCHY_SUFFIX"
---

Nested child from Git.
MARKDOWN
git add "page/$PARENT_A_SLUG/$GIT_CHILD_SLUG.md"
git commit -m "Create nested child page from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'Push MD applied 1 content change:'
assert_push_summary_contains "$PUSH_OUTPUT" "$GIT_CHILD_SLUG"
curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=$GIT_CHILD_SLUG&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
if (!is_array($pages) || empty($pages)) {
	exit(1);
}
if ((int) $argv[1] !== (int) $pages[0]["parent"]) {
	exit(1);
}
if (false === strpos($pages[0]["content"]["raw"], "Nested child from Git")) {
	exit(1);
}
' "$PARENT_A_ID"
git pull --rebase origin trunk

rm "$CLONE_DIR/page/$PARENT_A_SLUG.md"
git add -A
git commit -m "Reject parent page delete with children"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected parent page deletion with remaining children to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because deleting a parent page while keeping nested child page files would move child content.'
git reset --hard HEAD~1 >/dev/null

THEME_JSON_PATH="$(find "$CLONE_DIR/wp_theme" -mindepth 2 -maxdepth 2 -name theme.json | head -n 1)"
printf '\n' >> "$THEME_JSON_PATH"
git add "$THEME_JSON_PATH"
git commit -m "Edit theme base JSON from Git"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected theme base JSON push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because theme base files are read-only in Push MD.'
git reset --hard HEAD~1 >/dev/null

GLOBAL_STYLES_PATH="$(find "$CLONE_DIR/wp_global_styles" -maxdepth 1 -name '*.json' | head -n 1)"
GLOBAL_STYLES_RELATIVE="${GLOBAL_STYLES_PATH#$CLONE_DIR/}"
cat > "$GLOBAL_STYLES_PATH" <<'JSON'
{
	"version": 3,
	"styles": {
		"color": {
			"background": "#123456",
			"text": "#ffffff"
		}
	}
}
JSON
git add "$GLOBAL_STYLES_RELATIVE"
git commit -m "Customize global styles from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'Push MD applied 1 content change:'
assert_push_summary_contains "$PUSH_OUTPUT" 'wp_global_styles'
curl -sS -f "$BASE_URL/" | grep -Fq '#123456'
git pull --rebase origin trunk
grep -Fq '#123456' "$GLOBAL_STYLES_PATH"
! grep -Fq 'isGlobalStylesUserThemeJSON' "$GLOBAL_STYLES_PATH"

BASE_FOOTER_PATH="$(find "$CLONE_DIR/wp_template_part" -mindepth 2 -maxdepth 2 -name footer.html | head -n 1)"
BASE_FOOTER_RELATIVE="${BASE_FOOTER_PATH#$CLONE_DIR/}"
BASE_FOOTER_THEME="$(printf '%s' "$BASE_FOOTER_RELATIVE" | cut -d/ -f2)"
cat > "$BASE_FOOTER_PATH" <<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#ff0000"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="background-color:#ff0000">
	<!-- wp:paragraph {"style":{"color":{"text":"#ffffff"}}} -->
	<p style="color:#ffffff">Theme footer customized from Git</p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
HTML
git add "$BASE_FOOTER_RELATIVE"
git commit -m "Customize theme footer from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'Push MD applied 1 content change:'
assert_push_summary_contains "$PUSH_OUTPUT" 'wp_template_part'

curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/template-parts/$BASE_FOOTER_THEME//footer?context=edit" | php -r '
$part = json_decode(stream_get_contents(STDIN), true);
if (!is_array($part) || "custom" !== $part["source"] || 0 === strpos((string) $part["wp_id"], "0")) {
	exit(1);
}
if (false === strpos($part["content"]["raw"], "Theme footer customized from Git")) {
	exit(1);
}
'
curl -sS -f "$BASE_URL/" | grep -Fq 'Theme footer customized from Git'
git pull --rebase origin trunk
test ! -f "$CLONE_DIR/wp_template_part/$BASE_FOOTER_THEME-footer.html"

php -r '
$path = $argv[1];
$contents = file_get_contents($path);
$contents = str_replace("Hello from WordPress", "Updated from Git", $contents);
file_put_contents($path, $contents);
' "$CLONE_DIR/post/hello-world.md"

git add post/hello-world.md
git commit -m "Update hello world from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'Push MD applied 1 content change:'
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

EDITOR_UPDATE_PAYLOAD='{"content":"<!-- wp:heading --><h2 class=\"wp-block-heading\">Updated from Git <s>from editor</s></h2><!-- /wp:heading -->"}'
curl -sS \
	-X POST \
	-H "Authorization: Basic $AUTH_HEADER" \
	-H "Content-Type: application/json" \
	-d "$EDITOR_UPDATE_PAYLOAD" \
	-f \
	"$BASE_URL/wp-json/wp/v2/posts/$POST_ID?context=edit" >/dev/null

git pull --rebase origin trunk
grep -Fq '## Updated from Git ~~from editor~~' "$CLONE_DIR/post/hello-world.md"

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
assert_push_summary_contains "$PUSH_OUTPUT" 'Push MD applied 2 content changes:'
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
$contents .= "\nEscaped path literal: C:\\\\Temp\\\\push-md\nQuoted JSON literal: {\"windows\":\"C:\\\\\\\\Temp\\\\\\\\push-md\"}\n";
file_put_contents($path, $contents);
' "$CLONE_DIR/post/hello-world.md"

EXACT_COMMIT_MESSAGE='Preserve C:\\Temp\\push-md in commit metadata'
git add post/hello-world.md
git commit -m "$EXACT_COMMIT_MESSAGE"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'Push MD applied 1 content change:'
assert_push_summary_contains "$PUSH_OUTPUT" '/hello-world/'

EXACT_CLONE_DIR="$WORK_DIR/exact-clone"
git -c protocol.version=2 clone "$REMOTE_AUTH_URL" "$EXACT_CLONE_DIR"
EXACT_COMMIT_HASH="$(git -C "$EXACT_CLONE_DIR" log --grep='Preserve' --format=%H -n 1)"
[ -n "$EXACT_COMMIT_HASH" ]
git -C "$EXACT_CLONE_DIR" show "$EXACT_COMMIT_HASH:post/hello-world.md" > "$WORK_DIR/exact-blob.md"
grep -Fq 'Escaped path literal: C:\\Temp\\push-md' "$WORK_DIR/exact-blob.md"
grep -Fq 'Quoted JSON literal: {"windows":"C:\\\\Temp\\\\push-md"}' "$WORK_DIR/exact-blob.md"
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
	. "status: \"publish\"\n"
	. "title: \"Page From Git\"\n"
	. "---\n\n"
	. "Page created from Git.\n";
file_put_contents($path, $markdown);
' "$CLONE_DIR/page/page-from-git.md"

rm "$CLONE_DIR/page/renamed-sample-page.md"
git add post/created-from-git.md page/page-from-git.md page/renamed-sample-page.md
git commit -m "Create and delete content from Git"
PUSH_OUTPUT="$(git push origin trunk 2>&1)"
assert_push_summary_contains "$PUSH_OUTPUT" 'Push MD applied 3 content changes:'
assert_push_summary_contains "$PUSH_OUTPUT" '/created-from-git/'
assert_push_summary_contains "$PUSH_OUTPUT" '/page-from-git/'
assert_push_summary_contains "$PUSH_OUTPUT" '/renamed-sample-page/'

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
$markdown = "---\n"
	. "id: \"0\"\n"
	. "status: \"publish\"\n"
	. "title: \"Rejected ID Front Matter\"\n"
	. "---\n\n"
	. "This push must be rejected.\n";
file_put_contents($path, $markdown);
' "$CLONE_DIR/post/rejected-id-frontmatter.md"
git add post/rejected-id-frontmatter.md
git commit -m "Reject post id front matter"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected id front matter push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown front matter id must be a positive integer.'
git reset --hard HEAD~1 >/dev/null

php -r '
$path = $argv[1];
$markdown = "---\n"
	. "slug: \"rejected-slug-frontmatter\"\n"
	. "status: \"publish\"\n"
	. "title: \"Rejected Slug Front Matter\"\n"
	. "---\n\n"
	. "This push must be rejected.\n";
file_put_contents($path, $markdown);
' "$CLONE_DIR/post/rejected-slug-frontmatter.md"
git add post/rejected-slug-frontmatter.md
git commit -m "Reject post slug front matter"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected slug front matter push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown front matter must not include a slug.'
git reset --hard HEAD~1 >/dev/null

php -r '
$path = $argv[1];
$markdown = "---\n"
	. "type: \"post\"\n"
	. "status: \"publish\"\n"
	. "title: \"Rejected Type Front Matter\"\n"
	. "---\n\n"
	. "This push must be rejected.\n";
file_put_contents($path, $markdown);
' "$CLONE_DIR/post/rejected-type-frontmatter.md"
git add post/rejected-type-frontmatter.md
git commit -m "Reject post type front matter"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected type front matter push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown front matter must not include a type.'
git reset --hard HEAD~1 >/dev/null

mkdir -p "$CLONE_DIR/post/nested-path"
php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected Nested Path\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/nested-path/rejected-nested-path.md"
git add post/nested-path/rejected-nested-path.md
git commit -m "Reject nested post path"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected nested post path push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because post Markdown files must use post/<slug>.md paths.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected Upper Slug\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/Rejected Upper Slug.md"
git add "post/Rejected Upper Slug.md"
git commit -m "Reject noncanonical post slug"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected noncanonical post slug push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown file slugs must already match WordPress slug formatting.'
git reset --hard HEAD~1 >/dev/null

mkdir -p "$CLONE_DIR/wp_template/Bad Theme"
printf '%s' '<!-- wp:paragraph --><p>This push must be rejected.</p><!-- /wp:paragraph -->' > "$CLONE_DIR/wp_template/Bad Theme/rejected-raw-path.html"
git add "wp_template/Bad Theme/rejected-raw-path.html"
git commit -m "Reject noncanonical template theme path"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected noncanonical template theme path push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because template theme path segments must already match WordPress slug formatting.'
git reset --hard HEAD~1 >/dev/null

printf '%s' '<!-- wp:paragraph --><p>This push must be rejected.</p><!-- /wp:paragraph -->' > "$CLONE_DIR/wp_template/Rejected Raw Slug.html"
git add "wp_template/Rejected Raw Slug.html"
git commit -m "Reject noncanonical template slug path"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected noncanonical template slug path push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because template file slugs must already match WordPress slug formatting.'
git reset --hard HEAD~1 >/dev/null

printf '%s\n' '<div>This push must be rejected.</div>' > "$CLONE_DIR/wp_template/rejected-plain-html.html"
git add wp_template/rejected-plain-html.html
git commit -m "Reject plain template HTML"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected plain template HTML push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because template HTML files must contain serialized Gutenberg block markup.'
git reset --hard HEAD~1 >/dev/null

printf '%s\n' '{"version":3,"settings":{},"styles":{}}' > "$CLONE_DIR/wp_global_styles/Bad-Theme.json"
git add wp_global_styles/Bad-Theme.json
git commit -m "Reject noncanonical Global Styles path"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected noncanonical Global Styles path push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because the Global Styles theme filename must already match WordPress slug formatting.'
git reset --hard HEAD~1 >/dev/null

ln -s ../post/hello-world.md "$CLONE_DIR/post/rejected-symlink.md"
git add post/rejected-symlink.md
git commit -m "Reject arbitrary symlink"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected arbitrary symlink push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because symlink files are generated by Push MD and cannot be created or modified.'
git reset --hard HEAD~1 >/dev/null

if [ -L "$CLONE_DIR/AGENTS.md" ]; then
	git rm AGENTS.md
	git commit -m "Reject generated symlink deletion"
	if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
		echo "Expected generated symlink deletion push to fail." >&2
		exit 1
	fi
	assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because symlink files are generated by Push MD and cannot be deleted or modified.'
	git reset --hard HEAD~1 >/dev/null
fi

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected Executable\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-executable.md"
git add post/rejected-executable.md
git update-index --chmod=+x post/rejected-executable.md
git commit -m "Reject executable content file"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected executable content file push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because executable file modes are not supported by Push MD content exports.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected Multi Ref\"\n---\n\nThis push must be rejected before WordPress writes.\n"
);
' "$CLONE_DIR/post/rejected-multi-ref.md"
git add post/rejected-multi-ref.md
git commit -m "Reject multi-ref push"
if PUSH_OUTPUT="$(git push origin HEAD:trunk HEAD:refs/heads/rejected-multi-ref 2>&1)"; then
	echo "Expected multi-ref push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Push MD only accepts one ref update at a time.'
curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/posts?slug=rejected-multi-ref&context=edit" | php -r '
$posts = json_decode(stream_get_contents(STDIN), true);
if (array() !== $posts) {
	exit(1);
}
'
git reset --hard HEAD~1 >/dev/null

if PUSH_OUTPUT="$(git push origin :trunk 2>&1)"; then
	echo "Expected trunk deletion push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because deleting trunk is not supported.'

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Atomic Valid Page\"\n---\n\nThis page must not be written if the push is rejected.\n"
);
file_put_contents(
	$argv[2],
	"---\nstatus: \"invalid-status\"\ntitle: \"Atomic Invalid Status\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/page/atomic-valid-page.md" "$CLONE_DIR/post/atomic-invalid-status.md"
git add page/atomic-valid-page.md post/atomic-invalid-status.md
git commit -m "Reject atomic partial writes"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected atomic invalid status push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because "invalid-status" is not a supported post status.'
curl -sS -f -H "Authorization: Basic $AUTH_HEADER" "$BASE_URL/wp-json/wp/v2/pages?slug=atomic-valid-page&context=edit" | php -r '
$pages = json_decode(stream_get_contents(STDIN), true);
if (array() !== $pages) {
	exit(1);
}
'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected Front Matter\"\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-frontmatter.md"
git add post/rejected-frontmatter.md
git commit -m "Reject malformed front matter"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected malformed front matter push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown front matter is missing its closing --- fence.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ndate: \"not a date\"\ntitle: \"Rejected Invalid Date\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-invalid-date.md"
git add post/rejected-invalid-date.md
git commit -m "Reject invalid front matter date"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected invalid date front matter push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown front matter date is invalid.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"scheduled\"\ntitle: \"Rejected Scheduled No Date\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-scheduled-no-date.md"
git add post/rejected-scheduled-no-date.md
git commit -m "Reject scheduled post without date"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected scheduled post without date push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because scheduled posts must include a future date.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"scheduled\"\ndate: \"2000-01-01T00:00:00Z\"\ntitle: \"Rejected Scheduled Past Date\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-scheduled-past-date.md"
git add post/rejected-scheduled-past-date.md
git commit -m "Reject scheduled post with past date"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected scheduled post with past date push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because scheduled posts must include a date in the future.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ndate: \"2099-01-01T00:00:00Z\"\ntitle: \"Rejected Published Future Date\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-published-future-date.md"
git add post/rejected-published-future-date.md
git commit -m "Reject published post with future date"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected published post with future date push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because published posts must not include a future date.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected NUL Byte\"\n---\n\nBefore " . "\0" . " after.\n"
);
' "$CLONE_DIR/post/rejected-nul-byte.md"
git add post/rejected-nul-byte.md
git commit -m "Reject NUL byte content"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected NUL byte content push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because content files must not contain NUL bytes.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ndate: \"2024-02-31\"\ntitle: \"Rejected Impossible Date\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-impossible-date.md"
git add post/rejected-impossible-date.md
git commit -m "Reject impossible front matter date"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected impossible date front matter push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown front matter date is invalid.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle:\n  - \"Array Title\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-array-title.md"
git add post/rejected-array-title.md
git commit -m "Reject array front matter title"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected array title front matter push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown front matter field "title" must be a scalar string or number.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\nauthor: \"admin\"\ntitle: \"Rejected Unknown Front Matter\"\n---\n\nThis push must be rejected.\n"
);
' "$CLONE_DIR/post/rejected-unknown-frontmatter.md"
git add post/rejected-unknown-frontmatter.md
git commit -m "Reject unknown front matter"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected unknown front matter push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown front matter field "author" is not supported.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected Block Markup\"\n---\n\n<!-- wp:group {bad json} -->\nBroken block markup.\n<!-- /wp:group -->\n"
);
' "$CLONE_DIR/post/rejected-block-markup.md"
git add post/rejected-block-markup.md
git commit -m "Reject malformed block markup"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected malformed block markup push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because the content contains malformed Gutenberg block'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected Mismatched Blocks\"\n---\n\n<!-- wp:paragraph -->\n<p>Broken block markup.</p>\n<!-- /wp:group -->\n"
);
' "$CLONE_DIR/post/rejected-mismatched-blocks.md"
git add post/rejected-mismatched-blocks.md
git commit -m "Reject mismatched block markup"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected mismatched block markup push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because the content contains mismatched Gutenberg block delimiters.'
git reset --hard HEAD~1 >/dev/null

php -r '
file_put_contents(
	$argv[1],
	"---\nstatus: \"publish\"\ntitle: \"Rejected Raw Block In Markdown\"\n---\n\n<!-- wp:acme/custom-block-2 {\"flag\":true} /-->\n"
);
' "$CLONE_DIR/post/rejected-raw-block-in-markdown.md"
git add post/rejected-raw-block-in-markdown.md
git commit -m "Reject raw block in Markdown"
if PUSH_OUTPUT="$(git push origin trunk 2>&1)"; then
	echo "Expected raw block in Markdown push to fail." >&2
	exit 1
fi
assert_push_summary_contains "$PUSH_OUTPUT" 'Push rejected because Markdown content must not embed raw Gutenberg block delimiters inside HTML blocks.'
git reset --hard HEAD~1 >/dev/null

printf '%s' '<!-- wp:acme/custom-block-2 {"flag":true} /-->' > "$CLONE_DIR/wp_template/custom-acme-block.html"
git add wp_template/custom-acme-block.html
git commit -m "Accept custom block markup"
git push origin trunk

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
