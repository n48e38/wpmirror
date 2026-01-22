# Publishing notes

## WordPress.org submission (SVN)

1. Create a WordPress.org account.
2. Submit the plugin via the developer add flow (wp.org):
   - Provide a unique plugin slug (`wp-mirror`)
   - Upload the ZIP
3. Once approved, you will get SVN credentials and a repository URL.

Typical SVN structure:

- `trunk/` → development version
- `tags/1.0.0/` → release snapshot
- `assets/` → wp.org banners/icons/screenshots

Release flow:

1. Commit plugin code to `trunk/`
2. Tag a release:
   - copy `trunk/` to `tags/1.0.0/`
3. Add/update `readme.txt` and `assets/`

## Mirroring to GitHub

- Use GitHub as the primary development repo.
- When releasing to wp.org, export `trunk/` contents to SVN (or use a release script / CI).

Suggested approach:

- `main` branch for development
- Git tags for releases (`v1.0.0`)
- Keep `readme.txt` as the wp.org canonical readme.

## Notes for reviewers

- No tracking, analytics, or phone-home calls
- GitHub API is only invoked when the admin explicitly triggers deploy or test connection
- No obfuscation / encoded payloads
- GPLv2+ licensed
