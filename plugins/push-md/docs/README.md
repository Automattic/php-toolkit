# Push MD Developer README

This document is for developers, maintainers, and WordPress.org plugin reviewers
who need to understand or build Push MD from source. The public plugin listing
copy lives in `plugins/push-md/readme.txt`; this README explains
implementation choices, release packaging, lifecycle behavior, and
review-sensitive details.

## What Push MD Does

Push MD exposes supported WordPress content as a Git Smart HTTP remote at:

```text
/wp-json/git/v1/md.git
```

Authenticated users can clone, pull, and push the `trunk` branch. WordPress
remains the source of truth. Push MD exports current WordPress content into a
Git tree, stores Git objects in WordPress database tables, and imports pushed
changes back through WordPress post APIs.

Supported exported content includes:

- Posts and pages as Markdown files.
- Block theme templates, template parts, and navigation posts as raw Gutenberg
  block HTML.
- Active theme `theme.json` files as read-only context.
- Global Styles overlays as editable JSON.
- Gutenberg Guidelines and generated agent guidance when those WordPress
  features are available.

Push MD does not deploy PHP code, plugin code, theme source, uploads, media
files, arbitrary custom post types, or arbitrary database tables.

## Privacy And Security Model

Push MD does not send site content to GitHub, Automattic, WordPress.org, or
any other third-party service. It creates a Git endpoint on the WordPress site
where the plugin is installed. Git clients connect directly to that site's REST
API.

Unauthenticated requests cannot clone, pull, or push. Git over HTTPS is expected
to use WordPress Application Passwords through HTTP Basic Auth, or another REST
authentication layer that authenticates the request as a WordPress user before
Push MD permission checks run.

Clone and pull expose a complete repository view to the authenticated user, so
the plugin requires that user to be able to read the full exported repository.
Users with broad editorial access can read the full export. Otherwise, every
exported WordPress object must be readable to that user.

Pushes are checked object by object:

- Updating existing content requires permission to edit that object.
- Trashing content requires permission to delete that object.
- Creating content requires the relevant create capability for the post type.
- Publishing, scheduling, or making content private requires the relevant
  WordPress capability.

Because supported WordPress content is represented as Git history, authorized
local clones can contain private, draft, pending, or future content and prior
Git revisions of supported files. Clone URLs, Application Passwords, and local
clones should be treated as sensitive site access.

## Fail-Closed Import Design

Push MD validates and plans the pushed range before mutating WordPress
content. If any changed file or later commit is unsafe, the whole push is
rejected before WordPress content is changed.

Validation rejects unsupported paths, path traversal, non-canonical slugs,
unsupported file modes, user-authored symlinks, generated symlink edits, NUL
bytes, malformed front matter, unsupported front matter fields, malformed
Gutenberg block markup, invalid Global Styles JSON, edits to read-only theme
JSON, unsafe page hierarchy changes, non-`trunk` pushes, `trunk` deletion, and
stale pushes based on an older WordPress state.

The Git Smart HTTP discovery `service` query is whitelisted to
`git-upload-pack` and `git-receive-pack`.

## Data Storage

Push MD stores its derived Git object store in two per-site database tables:

```text
{$wpdb->prefix}pmd_files
{$wpdb->prefix}pmd_directory_entries
```

Seeder/import progress is stored in:

```text
pmd_seed_state
pmd_seed_progress
pmd_seed_lock
pmd_seed_tick
```

The database tables contain derived Git repository data and Push MD Git
history. WordPress posts, pages, templates, Global Styles, navigation posts, and
Guidelines remain in their normal WordPress storage.

## Lifecycle Behavior

Activation starts or resumes the async seeder. On first activation, the seeder
builds the initial Git repository from current WordPress content in batches.
The Git endpoint returns a clear "repository is being prepared" response until
the seeder reaches `done`.

Deactivation is non-destructive. It leaves repository tables, seed state, and
WordPress content intact so a site can disable and re-enable the plugin without
losing Push MD Git history.

Uninstall is destructive for Push MD's derived data only. `uninstall.php`
removes the Push MD Git object-store tables, seed progress options, transient
import lock, and scheduled seed task. It does not delete WordPress posts, pages,
templates, navigation posts, Global Styles, Guidelines, or other WordPress
content.

On multisite, uninstall iterates through sites and removes each site's per-site
Push MD tables and options. Reinstalling can seed a new repository from the
current WordPress content, but it cannot restore the previous Push MD Git
history unless the user kept a clone or database backup.

## Why A PHAR Is Bundled

Push MD depends on reusable PHP Toolkit components from this monorepo,
including Git, Filesystem, Markdown, Data Liberation, Encoding, Polyfill, and
ByteStream code. The plugin is packaged with `php-toolkit.phar` so it can ship
as a standalone WordPress plugin without Composer install steps on production
sites.

The PHAR is built from source in this repository. The release package includes
the PHAR plus a small plugin wrapper:

- `push-md.php`
- `push-md-phar-bootstrap.php`
- `functions.php`
- `class-pmd-*.php`
- `admin-shell.js`
- `admin-shell.css`
- `readme.txt`
- `uninstall.php`
- `php-toolkit.phar`

For development and review, the PHAR should be treated as a bundled build
artifact. The source for the bundled code is in this same repository under
`components/`, and the build configuration is in:

```text
phar-libraries.json
bin/build-libraries-phar.sh
bin/build-phar/
```

## Build Dependencies

Install PHP dependencies first:

```bash
composer install
```

The PHAR build expects Box, the PHP PHAR compiler, to be available as `box` on
`PATH`. One local installation option is:

```bash
brew tap box-project/box
brew install box
box -v
```

The GitHub Actions release workflow installs Box with `shivammathur/setup-php`.
See `.github/workflows/publish.yml`.

## Build The Plugin Zip

Build the toolkit PHAR first:

```bash
composer build-php-toolkit-phar
```

Then build plugin zips:

```bash
bash bin/build-plugins.sh
```

The Push MD package is written to:

```text
dist/plugins/push-md.zip
```

`bin/build-plugins.sh` copies `plugins/push-md/`, excludes development-only
paths, adds `dist/php-toolkit.phar`, and zips the result. It excludes:

- `Tests/`
- `docker-demo/`
- `docs/`
- `blueprint-e2e.json`
- `push-md-dev-bootstrap.php`

Inspect the release zip before submission:

```bash
zipinfo -1 dist/plugins/push-md.zip
```

The zip should include `readme.txt`, `uninstall.php`, admin assets, plugin PHP
files, `push-md-phar-bootstrap.php`, and `php-toolkit.phar`.

## Local Verification

Run scoped Push MD checks:

```bash
vendor/bin/phpcs -d memory_limit=1G plugins/push-md
node --check plugins/push-md/admin-shell.js
vendor/bin/phpunit -c phpunit.xml plugins/push-md/Tests/
wp i18n make-pot plugins/push-md /tmp/push-md.pot --domain=push-md --include='*.php,*.js,readme.txt,uninstall.php'
```

The PHPUnit E2E test class skips unless these environment variables point to a
prepared WordPress install:

```text
PUSH_MD_E2E_BASE_URL
PUSH_MD_E2E_USERNAME
PUSH_MD_E2E_PASSWORD
```

Run full repository checks before release when practical:

```bash
composer lint
composer test
```

If `dist/` contains stale built plugin files, repo-wide PHPCS may report
duplicate class warnings because ignored build artifacts duplicate source
classes. Remove stale build artifacts or rebuild them before interpreting
repo-wide lint results.

## Manual Smoke Test

For a release candidate, test the zip on a clean WordPress install:

1. Install and activate `dist/plugins/push-md.zip`.
2. Open Tools > Push MD and wait for the seeder to finish.
3. Create a WordPress Application Password.
4. Clone `/wp-json/git/v1/md.git`.
5. Confirm `git status`, `git pull`, and `git log` work.
6. Edit a post Markdown file, commit, and push to `trunk`.
7. Confirm the post changed in WordPress and revisions/permissions behave as
   expected.
8. Test stale push rejection by editing in WP-Admin after cloning, then pushing
   from the older clone.
9. Test private, draft, pending, and future content visibility with users that
   should and should not read the full export.
10. Uninstall the plugin and confirm `pmd_*` tables and seed state are
    removed while WordPress content remains.
11. Reinstall and confirm a fresh repository can be seeded from current content.

For multisite, test per-site activation, network activation if supported by the
target workflow, endpoint behavior for each site, and uninstall cleanup across
sites.

## WordPress.org Submission Notes

The plugin display name is "Push MD". It is intended to be submitted from an
official Automattic account.

Suggested reviewer note:

```text
Push MD is submitted by Automattic. The bundled php-toolkit.phar is built from
source in this repository; see plugins/push-md/docs/README.md for build,
packaging, lifecycle, privacy, and uninstall details.
```

Because `plugins/push-md/docs/` is excluded from the release zip, send a link
to this README in the WordPress.org submission notes or PR/release notes.
