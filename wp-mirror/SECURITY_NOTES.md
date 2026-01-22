# Security notes (WP Mirror)

This document explains how WP Mirror applies WordPress security best practices.

## Capabilities

All privileged operations require:

- `current_user_can('manage_options')`

This covers:
- Starting export jobs
- Starting deploy jobs
- Downloading/deleting ZIP archives
- AJAX polling endpoints and deploy controls (cancel/retry/test)

## Nonces

All admin forms use:

- `wp_nonce_field('wp_mirror_action', 'wp_mirror_nonce')`
- and are verified with `check_admin_referer('wp_mirror_action', 'wp_mirror_nonce')`

All AJAX calls use:

- `wp_create_nonce('wp_mirror_admin')`
- and are verified with `check_ajax_referer('wp_mirror_admin', 'nonce')`

## Sanitizing inputs

Settings are saved via `register_setting()` with a sanitize callback:

- Text: `sanitize_text_field()`
- Textareas: `sanitize_textarea_field()`
- URLs: `esc_url_raw()`
- Integers: `absint()`

Admin-post actions sanitize incoming fields using `sanitize_text_field()`, `wp_unslash()`, and `wp_normalize_path()` where relevant.

## Escaping outputs

All user-visible outputs are escaped:

- `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`

## Export safety

- WP Mirror **never copies PHP files** (explicit checks during asset mapping, balanced scans, ZIP creation, and deploy).
- Referenced-only mode limits copied assets to those referenced by exported HTML and CSS dependencies, reducing risk and size.

## GitHub token handling

- Prefer defining `WP_MIRROR_GITHUB_TOKEN` in `wp-config.php` so the token is not stored in the database.
- If stored in settings, it is stored as plain text in WP options (WordPress core provides no built-in encryption-at-rest).

## Future hardening ideas

- Optional integration with a secrets manager (host-specific) or WP encrypted options (if/when a standard exists).
- More granular capabilities (e.g., a custom capability instead of `manage_options`).
- Additional URL discovery strategies (sitemaps, custom post types) with strict allowlists.
