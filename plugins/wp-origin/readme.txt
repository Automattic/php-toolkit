=== WP Origin ===
Contributors: zieladam, artpi
Tags: git, markdown, content, workflow
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use Git to read, review, and edit WordPress content as Markdown and block files.

== Description ==

WP Origin makes a WordPress site available as a Git remote for content. Authenticated users can run familiar Git commands such as `clone`, `pull`, and `push` against a WordPress REST API endpoint, then edit supported content locally in Markdown-friendly tools, even while offline.

WordPress remains the source of truth. WP Origin stores the Git object data it needs in WordPress database tables, exports supported WordPress entities into a working tree, and imports pushed changes back into WordPress posts and related content.

The result is a static-site-generator-like workflow for a dynamic WordPress site: content can be cloned, diffed, edited, committed, and reviewed as files, while WordPress still handles publishing, previews, permissions, revisions, the editor, and the front end.

The goal is to make site content easier to review, version, automate, and edit with developer tools while keeping normal WP-Admin editing available.

= Common use cases =

* Edit posts and pages in a local editor, Markdown vault, or coding-agent workspace.
* Work offline after cloning or pulling content, then push changes back when a connection is available.
* Review content changes with normal Git diffs before they are applied to WordPress.
* Let an authenticated coding agent pull current site content, make a scoped edit, commit it, and push it back.
* Keep local Git history for content review and rollback while WordPress continues to manage publishing, permissions, and revisions.
* Work with block theme templates, template parts, navigation posts, and Global Styles as files when those WordPress entities are available.
* Pull site content into automation workflows without exporting the whole database or copying plugin/theme code.

= How it works =

After activation, WP Origin prepares an initial Git view of supported site content. The Git endpoint is:

`/wp-json/git/v1/md.git`

Users authenticate through WordPress REST authentication. The built-in Application Passwords feature is the usual Git-over-HTTPS option when it is available, and other REST authentication plugins can work if they authenticate the request as a WordPress user. A local clone contains files such as:

* `post/{slug}.md` for posts.
* `page/{slug}.md` for pages.
* `wp_template/{slug}.html` and `wp_template_part/{theme}/{slug}.html` for supported block theme entities.
* `wp_global_styles/{theme}.json` for editable Global Styles overlays.
* `wp_guideline/skills/{slug}/SKILL.md` for Gutenberg Guidelines skills when available.

Markdown files include front matter for WordPress metadata. Structural block files use raw Gutenberg block markup so they can round-trip through WordPress without being forced into Markdown.

= Safety model =

WP Origin is designed to fail closed. It rejects pushes that would overwrite newer WordPress edits, rejects unsupported file modes, and trashes deleted post/page files instead of permanently deleting WordPress content.

Pushed updates go through WordPress permissions and post APIs, so users can only change content their WordPress account is allowed to edit. Existing WP-Admin workflows can continue alongside Git-based edits.

= What this is not =

WP Origin is not a full database backup, theme deployment tool, plugin sync tool, or media-library mirror. It exposes supported content entities only. PHP code, plugins, themes, uploads, and arbitrary database tables are outside the release scope.

== Installation ==

1. Upload the plugin files to the `wp-content/plugins/wp-origin` directory.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Tools > WP Origin to monitor the initial import.

== Frequently Asked Questions ==

= Which content types are exported? =

Posts, pages, supported block theme templates, template parts, navigation posts, Global Styles overlays, and Gutenberg Guidelines when available.

= Who can push changes? =

Users must be authenticated and able to read every exported WordPress object to clone or pull the Git repository. This avoids exposing private, draft, pending, or future content through Git history.

For pushes, WP Origin checks permissions for each changed object. Updating or trashing existing content requires permission to edit that specific post. Creating new files requires permission to create that post type, and publishing or scheduling content requires the relevant publish capability.

Administrators can also use the seeder status screen.

= Does WP Origin enable Application Passwords? =

No. WP Origin does not enable or force any authentication method. It works with WordPress users that are already authenticated for REST requests and have the required capabilities.

The built-in WordPress Application Passwords feature is the default way to use Git over HTTPS when it is available on the site. Other REST authentication plugins may also work if they authenticate the request as a WordPress user before WP Origin's permission checks run.

= Can I still edit content in WP-Admin? =

Yes. WordPress remains the source of truth. Pull before editing locally to pick up recent WP-Admin changes, and WP Origin will reject stale pushes that could overwrite newer WordPress edits.

= Does this sync my site to GitHub or another Git host? =

No. WP Origin makes WordPress itself behave like a Git remote for supported content. You can add GitHub, GitLab, Bitbucket, or another host as a separate remote in your local clone if your workflow needs that.

= Does this export plugin or theme source code? =

No. The checkout is scoped to supported WordPress content. Theme-provided files such as `wp_theme/{theme}/theme.json` may appear as read-only context, but WP Origin does not deploy theme or plugin code.

== Changelog ==

= 0.1.0 =

Initial release.
