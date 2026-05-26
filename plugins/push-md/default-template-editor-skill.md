# Push MD Template Editor

Use this skill when editing `wp_template/*.html`, `wp_template_part/*.html`, or `wp_navigation/*.html` in a Push MD checkout.

## What These Files Are

Template HTML files are WordPress structural block entities exported as raw serialized Gutenberg block markup. WordPress remains the source of truth for IDs, titles, status, dates, theme ownership, and other administrative metadata. The file path is the working identity in Git.

Theme-provided templates and template parts appear in this checkout even before they have been customized in WordPress. Editing and pushing one of those files creates or updates the WordPress customization for that path. Theme-provided `wp_theme/{theme}/theme.json` files are exported for context and are read-only. Use `wp_global_styles/{theme}.json` for editable site-wide style and settings changes.

Theme-scoped paths are a filesystem view of WordPress template IDs. For example, `wp_template_part/twentytwentyfive/footer.html` maps to the template-part ID `twentytwentyfive//footer`. When customized, WordPress stores this as a `wp_template_part` post whose slug remains `footer` and whose `wp_theme` taxonomy term is `twentytwentyfive`.

## Hard Rules

- Keep files as raw Gutenberg block HTML. Do not add Markdown or YAML front matter.
- Create and update template HTML files only. Do not delete or rename them.
- Treat the path as identity. A nested path such as `wp_template_part/{theme}/header.html` maps back to a theme-qualified WordPress slug.
- Keep the theme as a directory segment. Do not flatten theme-scoped paths into files such as `wp_template_part/twentytwentyfive-footer.html` or `wp_template/twentytwentyfive-index.html`.
- Edit theme-provided templates in place. Do not copy them to top-level files unless the user explicitly wants a different non-theme-scoped entity.
- Do not edit `wp_theme/{theme}/theme.json`; use it to understand theme colors, spacing, typography, and layout settings. Edit `wp_global_styles/{theme}.json` when the user asks for site-wide theme.json-style changes.
- Preserve unknown blocks, custom blocks, and existing block attributes unless the user explicitly asks to change them.
- Preserve Gutenberg block comments. They are the block schema, not decorative comments.
- Do not create theme, plugin, upload, or database files. Push MD exposes content entities only.
- Use forward slashes in paths.

## Block Theme Markup Rules

- Prefer editable core blocks such as `core/group`, `core/columns`, `core/heading`, `core/paragraph`, `core/image`, `core/query`, `core/post-title`, `core/post-content`, `core/navigation`, and `core/template-part`.
- Avoid adding `core/html` blocks for normal layout, text, or visual wrappers. Use `core/html` only when the user explicitly needs opaque custom HTML.
- Reference reusable areas with `core/template-part` blocks instead of duplicating header, footer, or navigation markup across templates.
- Keep template parts focused: headers in `wp_template_part/header.html` or `wp_template_part/{theme}/header.html`, footers in `wp_template_part/footer.html` or `wp_template_part/{theme}/footer.html`, and navigation in `wp_navigation/*.html` when navigation entities are available.
- For full-width sections, use WordPress-native alignment attributes instead of CSS-only breakout tricks.

## Full-Width Section Pattern

Use an outer full-width group and an inner wide content shell:

```html
<!-- wp:group {"align":"full","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull">
	<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:heading -->
		<h2 class="wp-block-heading">Section heading</h2>
		<!-- /wp:heading -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
```

Use the site's existing markup style when it differs, but keep alignment in block attributes so the Site Editor and front end agree.

## Editing Workflow

- Pull before editing if the user has not already done so.
- Make the smallest template change that satisfies the request.
- Check nearby templates and parts before duplicating structure.
- Run `git status --short` before committing or pushing, and verify template edits stayed on the intended `.html` path with no deletes, renames, flattened theme files, or `wp_theme` changes.
- After a successful push, pull again if WordPress normalizes or rewrites the exported markup.
- If a change would require theme files, plugin code, CSS assets, or database settings that are not represented in this checkout, tell the user instead of inventing files.
