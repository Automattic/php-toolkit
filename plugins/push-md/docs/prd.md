# PRD: Push MD

## 1. Product Overview

### 1.1 Document Title And Version

- PRD: Push MD
- Version: 0.1 implementation refresh
- Status: Pre-release

### 1.2 Product Summary

Push MD makes supported WordPress content available as a Git remote. Users and
coding agents can run normal Git commands such as `clone`, `pull`, and `push`
against `/wp-json/git/v1/md.git`, edit content locally, and push those changes
back into WordPress.

WordPress remains the source of truth. The plugin stores the Git object data it
needs in WordPress database tables, exports the current WordPress state into a
repository tree, and imports pushed changes through WordPress post APIs.

The main product rule is content safety. Push MD should reject unsafe pushes
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

- **Site owner**: Installs Push MD and wants safer backups, review, and
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
- Branch preview URLs require an authenticated administrator. Preview access is
  not tokenized in v1.
- Merging a branch preview requires an authenticated Push MD admin action.

## 4. Git Endpoint And Protocol Scope

- The canonical remote URL is `/wp-json/git/v1/md.git`.
- The primary publishing branch is `trunk`.
- Pushes may update only one ref at a time.
- Pushes to `trunk` are publishing pushes. They validate the pushed range and,
  on success, apply supported content changes to WordPress.
- Pushes to non-`trunk` branches are branch preview pushes. They validate the
  pushed range and store Git objects, refs, and preview metadata, but they must
  not mutate WordPress content.
- Deleting `trunk` is rejected.
- Deleting a preview branch may remove only the Git ref and preview metadata.
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
- `wp_guideline/skills/{slug}/SKILL.md` for Gutenberg Guidelines skills and
  Push MD's built-in agent skills.
- `AGENTS.md`, `CLAUDE.md`, `.agents/skills`, and `.claude/skills` as generated
  or symlinked agent guidance when available.

Paths are part of the content identity. Push MD should reject unsupported
directories, unexpected extensions, path traversal, non-canonical slugs, and
ambiguous mappings.

Push MD's built-in agent skills are generated only when Gutenberg Guidelines
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
- `wp_global_styles/{theme}.json` is editable JSON. Push MD strips WordPress's
  internal Global Styles safety flag on export and restores it on import.
  Global Styles files must contain a JSON object.

Raw block files are validated before import. Malformed Gutenberg delimiters,
malformed JSON attributes, mismatched or unclosed blocks, NUL bytes, and content
that would normalize into surprising block structure are rejected.

## 8. Import Safety

Push MD validates and plans the full pushed range before mutating WordPress.
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
- Multi-ref pushes, invalid or protected branch names, and `trunk` deletion.
- Pushes based on a stale remote state.

Errors should be actionable from a Git client and should explain whether the
user needs to pull/rebase, fix a file, change permissions, or avoid an
unsupported operation.

## 9. Branch Previews

Branch previews let a user push a branch, view the site as that branch would
render, and merge it only when ready. They must be additive to the current
`trunk` workflow: existing `trunk` clone, pull, push, import, revision, and
permission behavior must not change.

### 9.1 Branch Storage

- Preview branches live in the same wpdb-backed Git object store as `trunk`,
  using the existing `{$wpdb->prefix}push_md_files` and
  `{$wpdb->prefix}push_md_directory_entries` tables.
- A branch push may create or update `refs/heads/{branch}` and write Git
  objects needed by that ref.
- A branch push may store lightweight preview metadata, such as branch ref,
  owner user ID, base commit, tip commit, created time, and last pushed time.
- Branch pushes must not call WordPress content mutation APIs, including
  `wp_insert_post()`, `wp_update_post()`, `wp_trash_post()`, template writes,
  Global Styles writes, term writes, or revision-producing updates.
- The Git object store and preview metadata are derived Push MD state, not site
  content. It is acceptable for branch pushes to update that derived state.

### 9.2 Branch Creation And Updates

- A user can create a branch from the current `trunk` tip with normal Git
  commands, for example `git checkout -b my-change` followed by
  `git push origin my-change`.
- When a preview branch is first created, Push MD records the current `trunk`
  tip as that branch's base commit. Validation for the initial branch push
  covers the diff from that base commit to the branch tip, not every historical
  commit already present on `trunk`.
- Updating an existing preview branch validates the newly pushed range from the
  previous branch tip to the new branch tip.
- Push MD accepts only safe `refs/heads/*` preview branch names. Reserved refs
  such as `trunk`, `_push_md_seed`, internal remote refs, path traversal, empty
  segments, and ambiguous ref names are rejected.
- Branch pushes run the same content validation as `trunk` pushes, including
  path rules, front matter rules, block markup validation, Global Styles JSON
  validation, symlink and executable mode rejection, read-only theme JSON
  rejection, and page hierarchy checks.
- Permission checks still run for the content changes represented by the branch
  so a user cannot stage a preview they would be forbidden to publish.
- If validation fails, the branch ref must remain at its previous value and
  WordPress content must remain unchanged.
- On success, Git output includes an admin preview URL for the branch.

### 9.3 Admin Preview URLs

- Each preview branch is available at a deterministic URL that uses the branch
  query parameter, for example `https://example.com/?branch=my-change`.
- The `branch` query value must be a branch name, not a ref path. Push MD maps
  it to `refs/heads/{branch}` after validating it with the same safe branch-name
  rules used for branch pushes.
- Branch names must be URL-encoded when needed.
- Preview requests require a logged-in administrator. Users who are not logged
  in, or who cannot administer Push MD, must see the normal live site or an
  authorization failure rather than branch content.
- Push MD must not issue, store, or require preview tokens for v1 branch
  previews.
- Preview URLs must send no-cache headers and should make preview mode visible
  to administrators so the branch view is not confused with live content.

### 9.4 Request-Scoped Rendering Overlay

- A preview request renders the public frontend through a request-scoped overlay
  derived from the selected branch tip.
- The overlay can replace, add, or hide supported posts and pages for that
  request without saving any `WP_Post` rows.
- The overlay can replace supported block templates, template parts,
  navigation posts, and Global Styles for that request without creating
  customizations or revisions.
- The overlay should use WordPress rendering APIs and filters where possible so
  themes, blocks, shortcodes, and normal frontend behavior still run.
- Preview rendering must never persist branch content as canonical WordPress
  content. If a preview request crashes or exits early, the live site state must
  remain unchanged.

### 9.5 Merge To WordPress

- Publishing a preview branch requires an explicit authenticated Push MD admin
  action, exposed through REST and optionally the Tools > Push MD UI.
- Merge is fast-forward-only in v1. Before merging, Push MD refreshes `trunk`
  from current WordPress content and verifies that the preview branch descends
  from the current `trunk` tip.
- If WordPress or `trunk` changed since the branch was based, merge is rejected.
  The user must pull or rebase `trunk`, update the branch, and push it again.
- A merge validates the branch again immediately before applying it.
- Only after validation succeeds may Push MD apply the branch diff through
  WordPress APIs and create normal WordPress revisions.
- If any branch change cannot be applied, no earlier branch change may remain
  applied to WordPress.
- After a successful merge, `trunk` should point at the merged branch tip or an
  equivalent merge commit that represents the applied WordPress state.

### 9.6 Out Of Scope For Branch Preview v1

- No GitHub-style pull request system, comments, reviews, required checks, or
  external Git host integration.
- No WP-Admin editor or Site Editor preview overlay in v1; the target preview
  surface is the public frontend.
- No preview support for media uploads, plugin/theme PHP code, arbitrary
  database tables, or unsupported post types.
- No automatic publishing from a non-`trunk` branch push.

## 10. Conflict Model

Before accepting a publishing push to `trunk`, Push MD refreshes the remote view
from WordPress. If WordPress changed since the client's base commit, the push is
rejected. The user or agent must pull, rebase or merge locally, resolve
conflicts, and push again.

Before merging a preview branch, Push MD performs the same freshness check
against current `trunk`. A stale branch can still exist as a preview, but it
cannot be merged until it is rebased or otherwise updated on top of current
`trunk`.

This keeps WordPress as the source of truth and avoids overwriting WP-Admin
edits with stale local content.

## 11. Core User Flows

### 11.1 Clone

1. A user authenticates with Basic Auth and an application password.
2. The user runs `git clone https://example.com/wp-json/git/v1/md.git`.
3. The clone checks out `trunk`.
4. The working tree contains supported WordPress content and agent guidance.

### 11.2 Edit A Post Or Page

1. The user edits `post/{slug}.md`, `page/{slug}.md`, or a nested page path.
2. The user commits locally.
3. The user pushes to `trunk`.
4. Push MD validates the whole push, checks permissions, updates WordPress,
   and lets WordPress create revisions.

### 11.3 Create Content

1. The user adds a new `post/{slug}.md` or page file.
2. Front matter is optional except where WordPress behavior requires a value.
3. Push MD creates the matching post or page using safe WordPress defaults.
4. Nested pages require an existing exported parent path.

### 11.4 Delete And Restore Content

1. Deleting a post or page file trashes the matching WordPress object.
2. Re-adding the same file path restores that trashed object.
3. The plugin rejects deletes that would leave exported child pages orphaned.

### 11.5 Edit Block Theme Content

1. The user edits a supported `.html` block entity file or
   `wp_global_styles/{theme}.json`.
2. Push MD validates raw block markup or JSON before writing.
3. Theme source files remain read-only.
4. Deletes and renames are rejected.

### 11.6 Resolve A Stale Push

1. A user edits content in WP-Admin after another user cloned.
2. The stale local clone attempts to push.
3. Push MD rejects the push before writing WordPress content.
4. The local user pulls, rebases or merges, resolves conflicts, and pushes the
   resolved tree.

### 11.7 Create A Branch Preview

1. The user creates a local branch from current `trunk`.
2. The user edits files, commits locally, and pushes the branch.
3. Push MD validates the pushed commits and permissions.
4. Push MD stores the branch ref and preview metadata without changing
   WordPress content or revisions.
5. Git output returns an admin preview URL such as
   `https://example.com/?branch=my-change`.
6. A logged-in administrator can view the public frontend rendered from the
   branch overlay.

### 11.8 Merge A Branch Preview

1. An admin opens the branch in Push MD and chooses merge.
2. Push MD refreshes `trunk` from current WordPress content.
3. Push MD rejects the merge if the branch is stale, invalid, or unauthorized.
4. Push MD applies the branch diff through WordPress APIs.
5. WordPress creates the normal revisions and canonical content changes.
6. A fresh pull from `trunk` includes the merged content.

## 12. Testing And Reliability

The plugin should keep a mix of focused unit tests and end-to-end Git flow tests.

Required coverage:

- Clone, pull, commit, push, and fresh clone verification.
- Basic Auth for clone and push.
- REST/WP-Admin edits flowing back through Git.
- Stale push rejection.
- Branch push validation without WordPress content mutation.
- Branch preview URLs use `?branch=<branch_name>` and require an authenticated
  administrator.
- Branch preview metadata does not include token values or token hashes.
- Preview overlay renders branch posts, pages, templates, template parts,
  navigation, and Global Styles without creating revisions.
- Branch merge freshness checks, successful application, and stale merge
  rejection.
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

## 13. Future Work

- Media mirroring for referenced attachments, including relative Markdown links,
  binary hashing, and safe import of new media.
- Explicit reset semantics for templates, template parts, navigation, and Global
  Styles.
- Additional post types with explicit path and permission rules.
- Better large-site pagination, streaming, and memory limits.
- Optional mapping between Git commits and WordPress revision sets.
- Branch preview collaboration features such as comments, review state,
  approvals, and external Git host links.
- WP-Admin editor and Site Editor branch preview support.
- WordPress.com or Jetpack transport once the standalone plugin behavior is
  stable.

## 14. Success Metrics

- A user can clone a test site and see supported content as files.
- A user can edit an existing post/page locally and push it without data loss.
- A user can create a new post/page from a pushed file.
- A user can safely trash and restore content through file deletion/re-addition.
- Block theme files and Global Styles overlays round-trip through supported
  create/update flows.
- A user can push a preview branch and receive an admin preview URL without
  changing WordPress content or creating revisions.
- A logged-in administrator can see branch content on the public frontend.
- An admin can merge a fresh preview branch and see the expected WordPress
  content and revisions afterward.
- Unsafe pushes fail before any WordPress content changes.
- Stale pushes are rejected and recoverable through normal Git pull/rebase
  workflows.
- End-to-end tests can prove the repository view and WordPress state agree after
  each supported flow.
