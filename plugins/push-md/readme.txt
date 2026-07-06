=== Push MD - Git Sync for WordPress Content ===
Contributors: artpi, zieladam
Tags: git, markdown, content, workflow
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 0.6.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use Git to edit WordPress content as Markdown while WordPress keeps roles, revisions, and rendering.

== Description ==

Push MD makes WordPress available as a Git remote for supported content. Posts and pages can be cloned as readable Markdown, edited in a local editor or coding-agent workspace, committed, and pushed back through WordPress.

WordPress stays in charge. Push MD reads and writes through WordPress APIs, checks the current user's capabilities, creates WordPress revisions for accepted content changes, and rejects stale pushes before they can overwrite newer WP-Admin edits.

This gives Markdown-first teams and coding agents a Git-shaped workflow without moving the source of truth out of WordPress.

**Beta:** Push MD is an early release for experimentation. Try it on a staging site first and keep your normal WordPress backups in place.

= What you can do =

* Clone supported WordPress content with a normal Git client.
* Edit posts and pages as Markdown.
* Review changes with standard Git diffs before pushing.
* Pull WP-Admin edits before continuing local work.
* Let authenticated coding agents work in an ordinary checkout.
* Keep WordPress roles, revisions, previews, publishing, and rendering in the loop.
* Work with block theme files, Global Styles, and Gutenberg Guidelines when available.

= How it works =

The Git endpoint on a site with Push MD active is:

`/wp-json/git/v1/md.git`

The repository branch is `trunk`. Push MD accepts pushes to `trunk` only.

Users authenticate through WordPress REST authentication. The built-in WordPress Application Passwords feature is the usual Git-over-HTTPS option, and other REST authentication plugins can work when they authenticate the request as a WordPress user before Push MD's permission checks run.

A checkout can include:

* `post/{slug}.md` for posts.
* `page/{slug}.md` and `page/{parent}/{child}.md` for pages.
* `wp_template/{slug}.html` and `wp_template/{theme}/{slug}.html` for templates.
* `wp_template_part/{theme}/{slug}.html` for template parts.
* `wp_navigation/{slug}.html` for navigation posts.
* `wp_theme/{theme}/theme.json` as read-only context.
* `wp_global_styles/{theme}.json` for editable Global Styles overlays.
* `wp_guideline/skills/{slug}/SKILL.md` for Gutenberg Guidelines skills and Push MD's built-in agent skills.
* `AGENTS.md`, `CLAUDE.md`, `.agents/skills`, and `.claude/skills` for agent guidance when available.

Markdown files use a small front matter contract: `title`, `date`, `status`, and optional `description`. File paths identify content.

Structural block files use raw Gutenberg block markup with no front matter so they can round-trip through WordPress without being forced into Markdown.

= Safety model =

Push MD is designed to fail closed. Before applying a push, it validates the changed files across the pushed range. If any file or later commit is unsafe, the whole push is rejected before WordPress content is changed.

Push validation rejects stale remote state, unsupported paths, path traversal, malformed front matter, invalid block markup, invalid Global Styles JSON, and edits to read-only theme JSON.

Deleted post and page files move the matching WordPress content to trash instead of permanently deleting it. Re-adding the same path restores the trashed object instead of creating a duplicate.

Template, template part, navigation, and Global Styles deletes or renames are rejected until reset semantics are explicit.

= Try a read-only demo =

You can clone the public read-only demo remote before installing Push MD:

`git clone https://pushmd.blog/wp-json/git/v1/md.git my-site`

That demo lets you inspect the file tree and Markdown shape. Pushes require Push MD on your own authenticated WordPress site.

== Installation ==

1. Upload the plugin files to the `wp-content/plugins/push-md` directory, or install Push MD from the WordPress Plugin Directory.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Tools > Push MD and wait for the initial import to finish.
4. Create a WordPress Application Password for your user if you want to use Git over HTTPS.
5. Clone your site's endpoint, replacing `example.com` with your site URL:

`git clone https://example.com/wp-json/git/v1/md.git my-site`

Use the `trunk` branch for pulls and pushes.

== Frequently Asked Questions ==

= Is WordPress still the source of truth? =

Yes. Your WordPress database remains authoritative. Git clients read and write through Push MD, so there is no separate content repository to reconcile.

= Which content types are exported? =

Posts, pages, supported block theme templates, template parts, navigation posts, read-only theme JSON context, Global Styles overlays, Gutenberg Guidelines when available, and built-in agent guidance.

= Which branch should I use? =

Use `trunk`. Push MD rejects pushes to other branches and rejects attempts to delete `trunk`.

= Who can clone or pull? =

Users must be authenticated and allowed to read the exported repository. Users with broad editorial access can clone the full export; otherwise every exported WordPress object must be readable to that user. This avoids exposing private, draft, pending, or future content through Git history.

= Who can push changes? =

Push MD checks permissions for each changed object. Updating, trashing, creating, publishing, scheduling, or making content private requires the matching WordPress capability.

= What happens when someone edits in WP-Admin? =

WP-Admin edits become Git-visible on pull. If a local checkout is stale, Push MD rejects the push before writing over newer WordPress content.

= Do pushes create revisions? =

Yes. Accepted content updates go through WordPress post APIs and create normal WordPress revisions.

= How do agent skills become available? =

Push MD stores agent skills as WordPress Guidelines. Today, that means the site needs the Gutenberg plugin installed and active, with the Guidelines experiment enabled from the Gutenberg Experiments page. After that, skills appear in the checkout under `wp_guideline/skills/{slug}/SKILL.md`, with generated aliases such as `AGENTS.md` for agent discovery.

= Is this a static site generator? =

No. Push MD gives you the local-file workflow people like in static site generators, while WordPress still handles previews, rendering, publishing, roles, and revisions.

= Does Push MD sync my site to GitHub or another Git host? =

No. Push MD makes WordPress itself behave like a Git remote for supported content. You can add GitHub, GitLab, Bitbucket, or another host as a separate remote in your local clone if your workflow needs that.

= Does Push MD export plugin or theme source code? =

No. The checkout is scoped to supported WordPress content. Theme-provided files such as `wp_theme/{theme}/theme.json` may appear as read-only context, but Push MD does not deploy theme or plugin code.

= Does Push MD export media files? =

No. Media mirroring is planned future work. The current release does not mirror uploads or import attachment binaries.

= Does Push MD send site content to another service? =

No. Push MD does not send content to GitHub, Automattic, WordPress.org, or any other third-party service. It exposes a Git endpoint on the WordPress site where the plugin is installed, and authenticated users connect directly to that site's REST API.

Authorized clones can include supported content and prior Git revisions. Private, draft, pending, and future content may appear when the authenticated user is allowed to read the full export. Treat clone URLs, Application Passwords, and local clones as sensitive site access.

= What happens when I uninstall Push MD? =

Uninstalling Push MD removes its Git object-store database tables, seed progress options, transient import lock, and scheduled seed task. It does not delete WordPress posts, pages, templates, navigation posts, Global Styles, Guidelines, or other WordPress content.

The removed tables contain Push MD's derived Git repository history. Reinstalling Push MD can seed a new repository from the current WordPress content, but it cannot restore the previous Push MD Git history unless you kept a clone or database backup.

== Changelog ==

= 0.6.7 =

Initial WordPress.org release.
