=== Push MD ===
Contributors: artpi, zieladam
Tags: git, markdown, content, workflow
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 0.6.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Edit WordPress content with Git, Markdown and block files, reviewable diffs, and safe pushes.

== Description ==

Push MD makes a WordPress site available as a Git remote for supported content. Authenticated users can run familiar Git commands such as `clone`, `pull`, and `push` against a WordPress REST API endpoint, then edit content locally in Markdown-friendly tools or coding-agent workspaces.

WordPress remains the source of truth. Push MD stores the Git object data it needs in WordPress database tables, exports the current WordPress state into a repository tree, and imports pushed changes back through WordPress post APIs.

The result is a static-site-generator-like workflow for a dynamic WordPress site: content can be cloned, diffed, edited, committed, and reviewed as files, while WordPress still handles publishing, previews, permissions, revisions, the editor, and the front end.

= Common use cases =

* Edit posts and pages in a local editor, Markdown vault, or coding-agent workspace.
* Review content changes with normal Git diffs before they are applied to WordPress.
* Pull recent WP-Admin edits before local work and use normal Git conflict handling.
* Let an authenticated coding agent pull current site content, make a scoped edit, commit it, and push it back.
* Work with block theme templates, template parts, navigation posts, and Global Styles as files when those WordPress entities are available.
* Pull site content into automation workflows without exporting the whole database or copying plugin/theme code.

= How it works =

The Git endpoint is:

`/wp-json/git/v1/md.git`

The repository branch is `trunk`. Push MD accepts pushes to `trunk` only.

Users authenticate through WordPress REST authentication. The built-in Application Passwords feature is the usual Git-over-HTTPS option when it is available, and other REST authentication plugins can work if they authenticate the request as a WordPress user.

A local clone can contain files such as:

* `post/{slug}.md` for posts.
* `page/{slug}.md` and `page/{parent}/{child}.md` for pages.
* `wp_template/{slug}.html` and `wp_template/{theme}/{slug}.html` for templates.
* `wp_template_part/{theme}/{slug}.html` for template parts.
* `wp_navigation/{slug}.html` for navigation posts.
* `wp_theme/{theme}/theme.json` as read-only theme context.
* `wp_global_styles/{theme}.json` for editable Global Styles overlays.
* `wp_guideline/skills/{slug}/SKILL.md` for Gutenberg Guidelines skills and Push MD's built-in agent skills.
* `AGENTS.md`, `CLAUDE.md`, `.agents/skills`, and `.claude/skills` for agent guidance when available.

Markdown files use a small front matter contract: `title`, `date`, `status`, and optional `description`. Identity comes from the file path, so `id`, `slug`, `type`, and unknown front matter fields are rejected.

Structural block files use raw Gutenberg block markup with no front matter so they can round-trip through WordPress without being forced into Markdown.

Push MD's built-in agent skills are generated only when Gutenberg Guidelines are enabled and no matching Guideline exists. Edit the canonical `wp_guideline/skills/{slug}/SKILL.md` file; generated aliases such as `AGENTS.md` and `.agents/skills` remain read-only.

= Safety model =

Push MD is designed to fail closed. Before applying a push, it validates and plans all changed files across the pushed range. If any file or later commit is unsafe, the whole push is rejected before WordPress content is changed.

Push validation rejects stale remote state, unsupported paths, path traversal, non-canonical slugs, unsupported file modes, user-authored symlinks, generated symlink edits, NUL bytes, malformed front matter, unsupported front matter fields, malformed Gutenberg block markup, invalid Global Styles JSON, edits to read-only theme JSON, and unsafe page hierarchy changes.

Deleted post and page files move the matching WordPress content to trash instead of permanently deleting it. Re-adding the same path restores the trashed object instead of creating a duplicate.

Template, template part, navigation, and Global Styles deletes or renames are rejected until reset semantics are explicit. Theme JSON files are exported only as read-only context.

Pushed updates go through WordPress permissions and post APIs, so users can only change content their WordPress account is allowed to edit. Existing WP-Admin workflows can continue alongside Git-based edits.

= What this is not =

Push MD is not a full database backup, theme deployment tool, plugin sync tool, media-library mirror, or arbitrary custom post type exporter. It exposes supported content entities only. PHP code, plugins, theme source, uploads, and arbitrary database tables are outside the current release scope.

== Installation ==

1. Upload the plugin files to the `wp-content/plugins/push-md` directory.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Tools > Push MD to monitor the initial import.

== Frequently Asked Questions ==

= Which content types are exported? =

Posts, pages, supported block theme templates, template parts, navigation posts, read-only theme JSON context, Global Styles overlays, Gutenberg Guidelines when available, and Push MD's built-in agent guidance.

= Which branch should I use? =

Use `trunk`. Push MD rejects pushes to other branches and rejects attempts to delete `trunk`.

= Who can clone or pull? =

Users must be authenticated and allowed to read the exported repository. Users with broad editorial access can clone the full export; otherwise every exported WordPress object must be readable to that user. This avoids exposing private, draft, pending, or future content through Git history.

= Who can push changes? =

Push MD checks permissions for each changed object. Updating or trashing existing content requires permission to edit or delete that specific post. Creating new files requires permission to create that post type, and publishing, scheduling, or making content private requires the relevant WordPress capability.

= Can I change a post or page slug in front matter? =

No. File paths are the identity model. Push MD rejects `id`, `slug`, `type`, and unknown front matter fields. Rename or move files only when the corresponding path operation is supported and safe.

= Can I still edit content in WP-Admin? =

Yes. WordPress remains the source of truth. Pull before editing locally to pick up recent WP-Admin changes, and Push MD will reject stale pushes that could overwrite newer WordPress edits.

= Does Push MD enable Application Passwords? =

No. Push MD does not enable or force any authentication method. It works with WordPress users that are already authenticated for REST requests and have the required capabilities.

The built-in WordPress Application Passwords feature is the default way to use Git over HTTPS when it is available on the site. Other REST authentication plugins may also work if they authenticate the request as a WordPress user before Push MD's permission checks run.

= Does Push MD require Composer or a PHAR archive? =

No. Push MD includes the readable PHP Toolkit runtime files it needs under `php-toolkit/` in the plugin package. The plugin does not install Composer dependencies on the site and does not load an opaque PHAR archive.

= Does this sync my site to GitHub or another Git host? =

No. Push MD makes WordPress itself behave like a Git remote for supported content. You can add GitHub, GitLab, Bitbucket, or another host as a separate remote in your local clone if your workflow needs that.

= Does this export plugin or theme source code? =

No. The checkout is scoped to supported WordPress content. Theme-provided files such as `wp_theme/{theme}/theme.json` may appear as read-only context, but Push MD does not deploy theme or plugin code.

= Does this export media files? =

No. Media mirroring is planned future work. The current release does not mirror uploads or import attachment binaries.

= Does Push MD send site content to another service? =

No. Push MD does not send content to GitHub, Automattic, WordPress.org, or any other third-party service. It exposes a Git endpoint on the WordPress site where the plugin is installed, and authenticated users connect directly to that site's REST API.

Because Push MD represents supported WordPress content as Git history, authorized clones can include posts, pages, templates, navigation posts, Global Styles, Guidelines, and their prior Git revisions. Private, draft, pending, and future content may appear in the Git repository when the authenticated user is allowed to read the full export. Treat clone URLs, Application Passwords, and local clones as sensitive site access.

= What happens when I uninstall Push MD? =

Uninstalling Push MD removes its Git object-store database tables, seed progress options, transient import lock, and scheduled seed task. It does not delete WordPress posts, pages, templates, navigation posts, Global Styles, Guidelines, or other WordPress content.

The removed tables contain Push MD's derived Git repository history. Reinstalling Push MD can seed a new repository from the current WordPress content, but it cannot restore the previous Push MD Git history unless you kept a clone or database backup.

== Changelog ==

= 0.5.0 =

Initial release.
