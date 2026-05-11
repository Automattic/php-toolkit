# PRD: WP Origin

## 1. Product Overview

### 1.1 Document Title And Version

- PRD: WP Origin
- Version: 0.1 implementation refresh
- Status: Pre-release

### 1.2 Product Summary

WP Origin makes supported WordPress content available as a Git remote. Users and
coding agents can run normal Git commands such as `clone`, `pull`, and `push`
against `/wp-json/git/v1/md.git`, edit content locally, and push those changes
back into WordPress.

WordPress remains the source of truth. The plugin stores the Git object data it
needs in WordPress database tables, exports the current WordPress state into a
repository tree, and imports pushed changes through WordPress post APIs.

The main product rule is content safety. WP Origin should reject unsafe pushes
before any WordPress content is changed. It should prefer a clear Git error over
partial writes, lossy conversion, ambiguous identity, or silent normalization.

## 2. Goals

### 2.1 Product Goals

- Let a WordPress site behave like a Git remote for supported content.
- Make posts and pages readable and editable as Markdown.
- Make block theme entities readable and editable as raw Gutenberg block files
  where WordPress supports safe customization.
- Give coding agents a checkout that includes operational guidance, skills, and
  enough theme context to make safe edits.
- Keep WP-Admin and Git editing compatible by rejecting stale pushes.
- Keep repository identity stable through file paths, not hidden front matter
  IDs.
- Fail closed when a pushed tree cannot be applied safely.

### 2.2 User Goals

- Clone or pull site content into a local editor, vault, or agent workspace.
- Review content changes with normal Git diffs before applying them to
  WordPress.
- Push scoped content changes without visiting WP-Admin.
- Pull recent WP-Admin edits before local work.
- Use Git conflict handling when local and WordPress edits overlap.
- Keep WordPress permissions, publishing behavior, revisions, and rendering as
  the source of truth.

### 2.3 Non-Goals For 0.1

- Do not version PHP code, plugins, theme source, uploads, or arbitrary database
  tables.
- Do not sync WordPress to GitHub, GitLab, Bitbucket, or another external Git
  host. Users may add those as separate local remotes.
- Do not mirror the full media library. Media import/export remains future work.
- Do not support arbitrary custom post types until each content mapping is
  explicit and tested.
- Do not rely on front matter slugs or post types for identity.
- Do not provide reset/delete semantics for template and Global Styles files
  until the product behavior is explicit.
- Do not require Composer packages or PHP extensions beyond this repository's
  constraints.

## 3. Users And Permissions

### 3.1 User Types

- **Site owner**: Installs WP Origin and wants safer backups, review, and
  agent-assisted edits.
- **Content editor**: Writes or reviews posts and pages in local tools.
- **Site builder**: Edits templates, template parts, navigation posts, and Global
  Styles where supported.
- **Coding agent**: Pulls the current checkout, edits files, commits, and pushes
  on behalf of an authenticated user.
- **Developer**: Extends, debugs, and tests the plugin.

### 3.2 Access Model

- Unauthenticated requests cannot clone, pull, or push.
- Git over HTTPS is expected to use HTTP Basic Auth with WordPress application
  passwords, or another REST authentication layer that authenticates the request
  as a WordPress user.
- Clone and pull expose a whole repository snapshot, so the user must be allowed
  to read the exported repository. Users with broad editorial access can read the
  full export; otherwise every exported object must be readable to that user.
- Push checks permissions for each changed object.
- Creating content requires the relevant create capability for the post type.
- Updating or trashing content requires permission to edit or delete that object.
- Publishing, scheduling, or making content private requires the corresponding
  WordPress capabilities.

## 4. Git Endpoint And Protocol Scope

- The canonical remote URL is `/wp-json/git/v1/md.git`.
- The repository branch is `trunk`.
- Pushes may update only one ref at a time.
- Pushes to branches other than `trunk` are rejected.
- Deleting `trunk` is rejected.
- Stale pushes are rejected when WordPress content changed after the client last
  fetched the remote state.
- If a push is rejected, WordPress content must remain unchanged.

## 5. Repository Layout

Current exported paths:

- `post/{slug}.md` for posts.
- `page/{slug}.md` for top-level pages.
- `page/{parent}/{child}.md` for hierarchical pages.
- `wp_template/{slug}.html` and `wp_template/{theme}/{slug}.html` for templates.
- `wp_template_part/{theme}/{slug}.html` for template parts.
- `wp_navigation/{slug}.html` for navigation posts.
- `wp_theme/{theme}/theme.json` for read-only theme JSON context.
- `wp_global_styles/{theme}.json` for editable Global Styles overlays.
- `wp_guideline/skills/{slug}/SKILL.md` for Gutenberg Guidelines skills and WP
  Origin's built-in agent skills.
- `AGENTS.md`, `CLAUDE.md`, `.agents/skills`, and `.claude/skills` as generated
  or symlinked agent guidance when available.

Paths are part of the content identity. WP Origin should reject unsupported
directories, unexpected extensions, path traversal, non-canonical slugs, and
ambiguous mappings.

WP Origin's built-in agent skills are generated only when Gutenberg Guidelines
are enabled and no matching Guideline exists. Editing the canonical
`wp_guideline/skills/{slug}/SKILL.md` file can create or update the matching
Guideline, while generated aliases such as `AGENTS.md` and `.agents/skills`
remain read-only.

## 6. Posts And Pages

### 6.1 Markdown Format

Posts and pages are Markdown files with a small YAML-style front matter block.
Supported front matter fields are:

- `title`
- `id`
- `date`
- `status`
- `description`

Unsupported front matter is rejected. This includes `slug`, `type`, unknown
keys, arrays, booleans, and null values. The optional `id` field preserves
WordPress identity across file renames.

`date_gmt` and `modified_gmt` are not exported. WordPress remains responsible
for canonical timestamps and revisions.

### 6.2 Status Values

Exports use human-facing values where useful:

- `published` for WordPress `publish`
- `scheduled` for WordPress `future`
- `draft`
- `pending`
- `private`

Imports accept both human-facing and WordPress-native values for published and
scheduled content: `published` or `publish`, and `scheduled` or `future`.

Scheduled/future content must have a future date. Published content with a
future date is rejected.

### 6.3 Identity And Page Hierarchy

Post identity comes from front matter `id` when present, otherwise from
`post/{slug}.md`.

Page identity comes from front matter `id` when present, otherwise from the
page path:

- `page/about.md` maps to the top-level `about` page.
- `page/company/about.md` maps to the child page `about` under parent `company`.

The same child slug may exist under different page parents because the full path
distinguishes them. Creating or updating a nested page requires the exported
parent page to exist. Export fails closed if a published/exported page has a
trashed, non-exported, invalid, or cyclic parent.

Deleting a post or page file moves the matching WordPress object to trash.
Re-adding the same path restores the trashed object instead of creating a
duplicate.

Deleting a parent page while descendant page files remain is rejected.

## 7. Structural Block Entities

Structural block entities are stored as raw Gutenberg block markup, not
Markdown, and do not use front matter.

Supported files:

- `wp_template/*.html`
- `wp_template/{theme}/*.html`
- `wp_template_part/{theme}/*.html`
- `wp_navigation/*.html`

Push behavior:

- Creates and updates are allowed where WordPress supports the corresponding
  customization.
- Editing a theme-provided template or template part creates or updates the
  WordPress customization for that theme and slug.
- Deletes and renames are rejected for template, template part, navigation, and
  Global Styles files until reset semantics are explicit.
- `wp_theme/{theme}/theme.json` is read-only context and cannot be edited.
- `wp_global_styles/{theme}.json` is editable JSON. WP Origin strips WordPress's
  internal Global Styles safety flag on export and restores it on import.
  Global Styles files must contain a JSON object.

Raw block files are validated before import. Malformed Gutenberg delimiters,
malformed JSON attributes, mismatched or unclosed blocks, NUL bytes, and content
that would normalize into surprising block structure are rejected.

## 8. Import Safety

WP Origin validates and plans the full pushed range before mutating WordPress.
This is required for single-commit and multi-commit pushes: if any changed file
or later commit is invalid, no earlier WordPress writes may remain.

Push validation rejects:

- Unsupported paths or extensions.
- Path traversal and non-canonical slugs.
- User-authored symlinks, except generated checkout guidance that remains
  unchanged.
- Executable file modes.
- NUL bytes and binary content in text formats.
- Malformed Markdown front matter.
- Unsupported front matter fields or scalar types.
- Malformed Gutenberg block markup.
- Edits to read-only theme JSON files.
- Invalid Global Styles JSON, including JSON arrays.
- Deletes or renames for template, template part, navigation, and Global Styles
  files.
- Creates, edits, or deletes of generated guidance symlinks.
- Parent page deletes while child page files still exist.
- Multi-ref pushes, non-`trunk` pushes, and `trunk` deletion.
- Pushes based on a stale remote state.

Errors should be actionable from a Git client and should explain whether the
user needs to pull/rebase, fix a file, change permissions, or avoid an
unsupported operation.

## 9. Conflict Model

Before accepting a push, WP Origin refreshes the remote view from WordPress. If
WordPress changed since the client's base commit, the push is rejected. The user
or agent must pull, rebase or merge locally, resolve conflicts, and push again.

This keeps WordPress as the source of truth and avoids overwriting WP-Admin
edits with stale local content.

## 10. Core User Flows

### 10.1 Clone

1. A user authenticates with Basic Auth and an application password.
2. The user runs `git clone https://example.com/wp-json/git/v1/md.git`.
3. The clone checks out `trunk`.
4. The working tree contains supported WordPress content and agent guidance.

### 10.2 Edit A Post Or Page

1. The user edits `post/{slug}.md`, `page/{slug}.md`, or a nested page path.
2. The user commits locally.
3. The user pushes to `trunk`.
4. WP Origin validates the whole push, checks permissions, updates WordPress,
   and lets WordPress create revisions.

### 10.3 Create Content

1. The user adds a new `post/{slug}.md` or page file.
2. Front matter is optional except where WordPress behavior requires a value.
3. WP Origin creates the matching post or page using safe WordPress defaults.
4. Nested pages require an existing exported parent path.

### 10.4 Delete And Restore Content

1. Deleting a post or page file trashes the matching WordPress object.
2. Re-adding the same file path restores that trashed object.
3. The plugin rejects deletes that would leave exported child pages orphaned.

### 10.5 Edit Block Theme Content

1. The user edits a supported `.html` block entity file or
   `wp_global_styles/{theme}.json`.
2. WP Origin validates raw block markup or JSON before writing.
3. Theme source files remain read-only.
4. Deletes and renames are rejected.

### 10.6 Resolve A Stale Push

1. A user edits content in WP-Admin after another user cloned.
2. The stale local clone attempts to push.
3. WP Origin rejects the push before writing WordPress content.
4. The local user pulls, rebases or merges, resolves conflicts, and pushes the
   resolved tree.

## 11. Testing And Reliability

The plugin should keep a mix of focused unit tests and end-to-end Git flow tests.

Required coverage:

- Clone, pull, commit, push, and fresh clone verification.
- Basic Auth for clone and push.
- REST/WP-Admin edits flowing back through Git.
- Stale push rejection.
- Multi-file and multi-commit push rejection without partial WordPress writes.
- Malformed front matter rejection.
- Rejection of `id`, `slug`, `type`, and unknown front matter fields.
- Malformed Gutenberg block markup rejection.
- Template/global-style delete and rename rejection.
- Read-only theme JSON rejection.
- Nested page export, creation, update, conflict, and parent delete behavior.
- Delete-to-trash and restore-from-trash behavior.
- Editor/admin permission boundaries for clone and push.
- Symlink, executable mode, unsupported path, binary/NUL, and path traversal
  rejection.

The smoke-test script should remain usable by agents as an acceptance harness
against a local WordPress playground or sandbox site.

## 12. Future Work

- Media mirroring for referenced attachments, including relative Markdown links,
  binary hashing, and safe import of new media.
- Explicit reset semantics for templates, template parts, navigation, and Global
  Styles.
- Additional post types with explicit path and permission rules.
- Better large-site pagination, streaming, and memory limits.
- Optional mapping between Git commits and WordPress revision sets.
- WordPress.com or Jetpack transport once the standalone plugin behavior is
  stable.

## 13. Success Metrics

- A user can clone a test site and see supported content as files.
- A user can edit an existing post/page locally and push it without data loss.
- A user can create a new post/page from a pushed file.
- A user can safely trash and restore content through file deletion/re-addition.
- Block theme files and Global Styles overlays round-trip through supported
  create/update flows.
- Unsafe pushes fail before any WordPress content changes.
- Stale pushes are rejected and recoverable through normal Git pull/rebase
  workflows.
- End-to-end tests can prove the repository view and WordPress state agree after
  each supported flow.
