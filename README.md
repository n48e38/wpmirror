# WP Mirror

WP Mirror exports a dynamic WordPress site into a **self-contained static site**, optionally creates **ZIP archives**, and can **deploy the exported files to GitHub** (GitHub Pages compatible).

- **No tracking. No phone-home.** The plugin only contacts GitHub when you explicitly click **Deploy to GitHub**.
- **No remote executable code. No non‑GPL libraries.**
- **Background jobs**: export, asset copying, ZIP creation, and GitHub deploy are processed in small WP‑Cron ticks to avoid long admin requests / 504s.

## Repo structure

```
wp-mirror/
  wp-mirror.php
  includes/
  admin/
  languages/
  assets/               # wp.org images (banner/icon/screenshots)
  readme.txt            # WordPress.org format
  README.md
  LICENSE
  SECURITY_NOTES.md
  PUBLISHING_NOTES.md
```

## Quick start (local / staging)

1. Install the plugin (`wp-mirror/` folder) into `wp-content/plugins/`.
2. Activate **WP Mirror**.
3. Go to **WP Mirror** (top-level menu).
4. Set:
   - **Public Base URL** (your production/static base URL)
   - **Export Directory** (absolute server path)
   - Asset scope (use **Referenced-only** to keep file counts small)
5. Click **Generate Static Export**.
6. (Optional) Enable GitHub deploy settings and click **Deploy to GitHub**.

## GitHub PAT permissions (recommended)

Minimal scope depends on whether your repo is public/private and your org policies. Typical working option:

- Fine-grained PAT:
  - **Repository access**: only the target repo
  - **Permissions**: Contents **Read and write** (and Metadata Read)

Store it as a constant (preferred):

```php
define('WP_MIRROR_GITHUB_TOKEN', 'YOUR_TOKEN_HERE');
```

If you don't set the constant, WP Mirror can store the token in WP options (database) via Settings.

## GitHub Pages extras

- Writes `.nojekyll` (optional)
- Writes `CNAME` if you set a custom domain
- Supports a **path prefix** (e.g., `docs/`)

## Screenshots placeholders

- `assets/banner-1544x500.png`
- `assets/icon-256x256.png`
- `assets/screenshot-1.png` (Settings screen)
- `assets/screenshot-2.png` (Progress/logs screen)
- `assets/screenshot-3.png` (ZIP archives list)

## Developer notes

- Export/ZIP/deploy runs via `wp_schedule_single_event()` on the `wp_mirror_run_jobs` hook.
- UI polls status via `admin-ajax.php` every ~3 seconds.
- Deploy uses GitHub **Git Data API** (blobs → tree → commit → update ref) and skips unchanged files using `.wp-mirror-manifest.json`.
