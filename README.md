# WP Mirror (wpmirror)

WP Mirror is a production-grade WordPress plugin that exports a dynamic WordPress site into a **self-contained static site**, optionally creates **ZIP archives**, and can **deploy exported files to GitHub** (GitHub Pages compatible).

- ✅ Static export (HTML + required assets)
- ✅ Asset modes: **Referenced-only** (recommended) and **Balanced**
- ✅ ZIP archive creation + download/delete in admin UI
- ✅ GitHub deploy using the Git Data API (blobs → tree → commit → ref update)
- ✅ Background processing via WP-Cron ticks (prevents long admin requests / 504s)
- ✅ AJAX progress UI + detailed logs
- ✅ Manifest-based “skip unchanged” deploys
- ✅ No tracking. No phone-home. GPL-compatible

## Downloads

Packaged builds are stored in [`dist/`](./dist):

- `wp-mirror-plugin-1.0.1-fixed.zip`
- `wp-mirror-plugin-1.0.2.zip` 
- `wp-mirror-plugin-1.0.3.zip` (recommended)
- 'wp-mirror-plugin-1.0.4' to do

> Tip: For a nicer UX, also publish these as **GitHub Releases** and attach the ZIPs there.

## Installation

### Option A: Install from ZIP
1. Download a ZIP from `dist/`
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate **WP Mirror**
4. WP Admin → **WP Mirror** (top-level menu)

### Option B: Install from source
Copy the `wp-mirror/` folder into:
`wp-content/plugins/wp-mirror/`  
Then activate it in WP Admin.

## Configuration (important)

In **WP Mirror → Settings** set:

- **Public Base URL** (this is what exported links will be rewritten to)
  - Example: `https://new.domain.com`
- **Export Directory** (absolute server path, writable)
  - Example: `/var/www/html/wp-mirror-export`
- **Asset scope mode**
  - Use **Referenced-only** to keep file counts small

## Usage

1. Click **Generate Static Export**
2. (Optional) Enable ZIP and generate archive
3. (Optional) Configure GitHub and click **Deploy to GitHub**
4. Watch progress + logs (UI polls status via AJAX)

## GitHub Pages notes

- Use branch `gh-pages` (default) or a `/docs` prefix if you prefer
- Optional:
  - `.nojekyll` to prevent Jekyll processing
  - `CNAME` if using a custom domain

## GitHub Token (PAT)

Recommended: define it in `wp-config.php` so it is not stored in the database:

```php
define('WP_MIRROR_GITHUB_TOKEN', 'YOUR_TOKEN_HERE');
