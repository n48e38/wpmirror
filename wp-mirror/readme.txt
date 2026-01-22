=== WP Mirror ===
Contributors: wp-mirror-contributors
Tags: static site, export, github pages, deployment, performance, security
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Export your WordPress site as a self-contained static site, optionally create ZIP archives, and deploy to GitHub (GitHub Pages compatible).

== Description ==

WP Mirror generates a static copy of your WordPress site:

* Exports rendered HTML for your homepage, published posts, and published pages.
* Rewrites internal staging URLs to a configured Public Base URL so the exported site does **not** link back to staging.
* Copies only the assets needed for your exported pages (Referenced-only mode), including CSS url() and @import dependencies.
* Optionally creates a ZIP archive for backups (downloadable from the WP Mirror admin screen).
* Optionally deploys the exported directory to GitHub using a background, batched workflow and a local manifest for skip-unchanged deploys.

No tracking. No phone-home. WP Mirror only talks to GitHub when you explicitly click Deploy.

Background processing: exports, ZIP creation, and GitHub deploy run via single WP-Cron ticks. The admin page returns immediately and polls progress via AJAX.

== Installation ==

1. Upload the `wp-mirror` folder to the `/wp-content/plugins/` directory, or install via the WordPress plugin uploader.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to WP Mirror in the admin menu.
4. Configure settings (Public Base URL, Export Directory, asset scope).
5. Click Generate Static Export.

== Frequently Asked Questions ==

= How do I deploy to GitHub Pages? =

1. Create a GitHub repo.
2. In WP Mirror settings, enable GitHub deploy and set Owner, Repo, Branch (default `gh-pages`), and optional path prefix.
3. Create a PAT with repo content write access.
4. Paste the token into settings (or define `WP_MIRROR_GITHUB_TOKEN` in `wp-config.php`).
5. If "Clean remote removed files" is enabled, tick the confirmation checkbox on the Deploy form.
5. Click Deploy to GitHub.

= How do I keep file count small? =

Use Referenced-only mode. WP Mirror will copy only assets referenced by exported HTML and CSS dependencies.

= Does WP Mirror copy PHP files? =

No. WP Mirror never copies PHP files into exports or ZIPs, and skips PHP during deploy.

= Can WP Mirror run without admin timeouts (504)? =

Yes. Jobs are broken into small background ticks via WP-Cron. The admin page starts a job and returns immediately.

== Changelog ==

= 1.0.3.1 =
* Fix: plugin activation error caused by an accidental stray modifier in the background jobs class.

= 1.0.3 =
* Added: Restore export directory from a WP Mirror archive ZIP (background job with progress + logs).

= 1.0.2 =
* Improved URL rewriting to reliably replace staging domain in HTML attributes, srcset, inline CSS, and JSON-escaped URLs.


= 1.0.0 =
* Initial release: static export, ZIP archives, GitHub deploy, background processing, and progress UI.
