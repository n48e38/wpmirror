# WP Mirror

WP Mirror is a production-grade WordPress plugin that exports a dynamic WordPress site into a **self-contained static site**, optionally creates **ZIP archives**, and can **deploy exported files to GitHub** (GitHub Pages compatible).

- ✅ Static export (HTML + required assets)
- ✅ Asset modes: **Referenced-only** (recommended) and **Balanced**
- ✅ ZIP archive creation + secure download/delete in WP Admin
- ✅ GitHub deploy (Git Data API: blobs → tree → commit → ref update)
- ✅ Background processing via WP-Cron ticks (avoids long admin requests / 504s)
- ✅ AJAX progress UI + timestamped logs
- ✅ Manifest-based “skip unchanged” deploys
- ✅ No tracking. No phone-home. No non-GPL libraries.

---

## Download / Install

WP Mirror is distributed via **GitHub Releases**.

1. Open the **Releases** page of this repository.
2. Download the plugin ZIP for the version you want:
   - `wp-mirror-plugin-1.0.1.zip`
   - `wp-mirror-plugin-1.0.2.zip` (recommended)
3. In WordPress Admin: **Plugins → Add New → Upload Plugin**
4. Upload the ZIP and activate **WP Mirror**
5. Go to **WP Mirror** (top-level admin menu) to configure.

> Tip: Use **1.0.2** if you need correct domain rewriting (exported site links should point to your new public domain).

---

## Configuration (important)

In **WP Mirror → Settings** configure:

- **Public Base URL**  
  The domain used in the exported static site (links will be rewritten to this).
  Example: `https://new.domain.com`

- **Export Directory (absolute path)**  
  Where static files and archives will be stored.
  Example: `/var/www/html/wp-mirror-export`

- **Asset scope mode**
  - **Referenced-only** (default, recommended): copies only assets referenced by exported HTML + CSS dependencies
  - **Balanced**: uploads + active theme assets + essential wp-includes static assets (still avoids copying everything)

- **ZIP archives**
  - Enable/disable ZIP creation
  - Archives are stored under `<export-dir>/_archives/` and are downloadable/deletable via the admin UI

- **GitHub deploy**
  - Owner / Repo / Branch (default: `gh-pages`)
  - Optional path prefix (e.g. `docs/`)
  - Optional `.nojekyll` and `CNAME`
  - Token handling:
    - Prefer `WP_MIRROR_GITHUB_TOKEN` constant in `wp-config.php`
    - Or store in plugin settings (stored in plain text, as WP core has no built-in encryption-at-rest)

---

## Typical workflow

1. Click **Generate Static Export**
2. Optional: generate a ZIP archive
3. Click **Deploy to GitHub** (manual action, never automatic)
4. Monitor progress and logs (AJAX polling)
5. Use **Cancel** for deploy or **Retry failed items** if needed

---

## GitHub Pages notes

---

## GitHub token permissions (PAT)

Recommended: define a token in `wp-config.php`:

```php
define('WP_MIRROR_GITHUB_TOKEN', 'YOUR_TOKEN_HERE');
