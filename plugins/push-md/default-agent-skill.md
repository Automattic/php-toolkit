# Push MD AGENTS.md

## What This Repository Is

This repository is a Git checkout of a WordPress site exposed by Push MD. WordPress remains the source of truth. The Git history in this clone is a working view for review, editing, and agent workflows.

## Repository Layout

- `post/{slug}.md` contains WordPress posts.
- `page/{slug}.md` and `page/{parent}/{slug}.md` contain WordPress pages.
- `wp_template/{slug}.html`, `wp_template_part/{slug}.html`, and `wp_navigation/{slug}.html` contain raw Gutenberg block markup for structural site entities. Theme-qualified WordPress slugs may appear as nested paths such as `wp_template_part/{theme}/header.html`.
- `wp_theme/{theme}/theme.json` contains read-only theme-provided design settings for agent context.
- `wp_global_styles/{theme}.json` contains the editable Global Styles overlay for the active theme. Edit this file for site-wide styles and settings instead of editing `wp_theme/{theme}/theme.json`.
- `wp_guideline/skills/{slug}/SKILL.md` contains coding-agent skills stored as Gutenberg Guidelines.
- `.agents/skills` and `.claude/skills` point to `wp_guideline/skills` for agent discovery.
- `AGENTS.md` and `CLAUDE.md` point to this guide.

## Pulling And Pushing

- `git pull` refreshes the checkout from the current WordPress site.
- `git push` applies supported Markdown changes back to WordPress.
- Pushed post and page changes create WordPress revisions.
- Post and page front matter may include `id` so a file rename updates the same WordPress object.
- Deleted post or page files are trashed in WordPress rather than permanently deleted.
- If WordPress changed after your last pull, the push is rejected. Pull, review the diff, and then push again.

## Editing Rules

- Preserve post and page front matter unless you are intentionally changing that WordPress metadata.
- Guideline skill front matter is generated from WordPress fields. Keep the body focused on the guideline content.
- Template HTML files must stay raw Gutenberg block markup without front matter.
- Template HTML files may be created or updated, but deletes and renames are rejected because their paths are their WordPress identity.
- Theme base files are checked out for context. Editing theme-provided templates creates WordPress customizations; `wp_theme/{theme}/theme.json` is read-only in this checkout.
- Global Styles JSON files may be created or updated, but deletes and renames are rejected. `wp_global_styles/{theme}.json` is the database overlay for `wp_theme/{theme}/theme.json`.
- Keep theme-scoped templates in their nested paths. For example, edit `wp_template_part/twentytwentyfive/footer.html`; do not create flattened files such as `wp_template_part/twentytwentyfive-footer.html`.
- A path such as `wp_template_part/twentytwentyfive/footer.html` maps to the WordPress template-part ID `twentytwentyfive//footer`. The customized database post keeps the slug `footer` and stores `twentytwentyfive` in the `wp_theme` taxonomy.
- Use the `push-md-template-editor` skill before editing `wp_template/*.html`, `wp_template_part/*.html`, or `wp_navigation/*.html`.
- Preserve unsupported block markup, fenced `gutenberg` blocks, custom blocks, and raw HTML unless the user asks for a conversion.
- After template or Global Styles edits, run `git status --short` and confirm there are no unintended deleted files, renamed files, `wp_theme` changes, or flattened theme paths.
- Use forward slashes in paths.
- Keep changes scoped to site content. This checkout does not represent plugin code, themes, uploads, or the full database.
