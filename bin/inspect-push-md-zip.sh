#!/bin/bash

set -euo pipefail

ZIP_PATH="${1:-dist/plugins/push-md.zip}"

if [ ! -f "$ZIP_PATH" ]; then
	echo "Missing Push MD zip: $ZIP_PATH" >&2
	exit 1
fi

CONTENTS_FILE="$(mktemp)"
trap 'rm -f "$CONTENTS_FILE"' EXIT

zipinfo -1 "$ZIP_PATH" > "$CONTENTS_FILE"

require_file() {
	local path="$1"
	if ! grep -Fxq "$path" "$CONTENTS_FILE"; then
		echo "Push MD zip is missing required file: $path" >&2
		exit 1
	fi
}

reject_pattern() {
	local pattern="$1"
	local message="$2"
	local matches

	matches="$(grep -E "$pattern" "$CONTENTS_FILE" || true)"
	if [ -n "$matches" ]; then
		echo "$message" >&2
		echo "$matches" >&2
		exit 1
	fi
}

reject_content_pattern() {
	local pattern="$1"
	local message="$2"
	local matches

	matches="$(
		while IFS= read -r path; do
			case "$path" in
				*.php|*/readme.txt)
					unzip -p "$ZIP_PATH" "$path" | grep -En "$pattern" | sed "s#^#$path:#" || true
					;;
			esac
		done < "$CONTENTS_FILE"
	)"
	if [ -n "$matches" ]; then
		echo "$message" >&2
		echo "$matches" >&2
		exit 1
	fi
}

require_file 'push-md/push-md.php'
require_file 'push-md/readme.txt'
require_file 'push-md/skills/default-agent-skill.md'
require_file 'push-md/skills/default-template-editor-skill.md'
require_file 'push-md/php-toolkit/vendor/composer/ClassLoader.php'
require_file 'push-md/php-toolkit/components/Markdown/class-markdownconsumer.php'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/league/commonmark/LICENSE'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/league/config/LICENSE.md'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/dflydev/dot-access-data/LICENSE'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/nette/schema/license.md'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/nette/utils/license.md'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/psr/event-dispatcher/LICENSE'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/symfony/deprecation-contracts/LICENSE'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/symfony/polyfill-ctype/LICENSE'
require_file 'push-md/php-toolkit/components/Markdown/vendor-patched/symfony/polyfill-php80/LICENSE'

reject_pattern '(^|/)\.DS_Store$' 'Push MD zip must not contain macOS metadata files.'
reject_pattern '\.phar$' 'Push MD zip must not contain PHAR archives.'
reject_pattern '^push-md/[^/]+\.md$' 'Push MD zip must not contain unexpected Markdown files in the plugin root.'
reject_pattern '^push-md/(Tests|docker-demo|docs)/' 'Push MD zip must not contain development-only plugin directories.'
reject_pattern '^push-md/(blueprint-e2e\.json|push-md-dev-bootstrap\.php|push-md-phar-bootstrap\.php)$' 'Push MD zip contains development or PHAR bootstrap files.'
reject_pattern '(^|/)(composer\.(json|lock)|package(-lock)?\.json|phpunit\.xml(\.dist)?|phpcs\.xml|rector\.php)$' 'Push MD zip contains source-only project metadata.'
reject_pattern 'components/Markdown/class-markdownimporter\.php$' 'Push MD zip contains the unused Markdown importer.'
reject_pattern 'vendor-patched/(bin/|composer/|webuni/|symfony/yaml/)' 'Push MD zip contains pruned vendor support files or front matter/YAML dependencies.'
reject_pattern 'vendor-patched/league/commonmark/src/Extension/(Attributes|DefaultAttributes|DescriptionList|Embed|Footnote|FrontMatter|HeadingPermalink|Mention|SmartPunct|TableOfContents)/' 'Push MD zip contains pruned CommonMark extensions.'
reject_pattern 'vendor-patched/nette/utils/src/Iterators/' 'Push MD zip contains pruned Nette iterator utilities.'
reject_content_pattern '<<<' 'Push MD zip must not contain HEREDOC or NOWDOC syntax.'
reject_content_pattern 'error_reporting[[:space:]]*\(' 'Push MD zip must not call error_reporting().'
reject_content_pattern 'utf8_decode[[:space:]]*\(' 'Push MD zip must not call deprecated utf8_decode().'
reject_content_pattern "define[[:space:]]*\\([[:space:]]*['\"]FILTER_VALIDATE_BOOL['\"]" 'Push MD zip must not define the unprefixed FILTER_VALIDATE_BOOL constant.'
reject_content_pattern 'namespace[[:space:]]+Artpi\\PushMD' 'Push MD zip should use reviewer-friendly prefixes rather than the temporary namespace-only approach.'
reject_content_pattern 'namespace[[:space:]]+(WordPress|League|Nette|Symfony|Dflydev|Psr|Composer)(\\|[[:space:]]*[{;])' 'Push MD zip must scope bundled runtime namespaces.'
reject_content_pattern "['\"](PhpToken|ValueError|Attribute|UnhandledMatchError|Stringable)['\"][[:space:]]*=>" 'Push MD autoload metadata must not register unprefixed PHP 8 polyfill stubs.'
reject_content_pattern 'class[[:space:]]+PMD_|interface[[:space:]]+PMD_|trait[[:space:]]+PMD_|function[[:space:]]+PMD_|define[[:space:]]*\([[:space:]]*['\''"]PMD_' 'Push MD zip must not declare old three-letter PMD globals.'
reject_content_pattern '['\''"]pmd_(seed|auth|required|forbidden|error|invalid|files|directory_entries)' 'Push MD zip must not use old three-letter pmd_ storage or error identifiers.'
reject_content_pattern '['\''"]guideline_source['\''"]' 'Push MD zip must not use the unprefixed guideline_source post meta key.'
reject_content_pattern '^Contributors:.*automattic' 'Push MD readme lists the unusual automattic contributor.'

echo "Push MD zip contents look submission-ready: $ZIP_PATH"
