---
name: most-important-details-for-kornsw-plugingallery
description: Repository-specific knowledge for WP-PluginGallery (WordPress plugin "KornSW Plugin Gallery", slug kornsw-plugingallery) - config.php favorites format, the three catalog tiers (favorite / additional / related WP-* repositories), GitHub rate-limit strategy (token, ETag and pushed_at caches), WordPress.org installation via the core installer, option and transient names, standalone tests. Load when changing, reviewing or debugging this plugin.
metadata:
  version: "2026-11-23"
---

# Most Important Details for kornsw-plugingallery

General plugin rules come from `wordpress-plugin-development-guidelines` (AI-SKILLS-SMART); release and self-update from `nuget-semantic-versioning-kvu` (`references/wordpress-plugins.md`). This skill adds only what is specific to this repository. Human documentation (German): `README.md`, `doc/`.

## Identity

- Slug / folder / text domain `kornsw-plugingallery`, namespace `KornSW\PluginGallery`, short prefix `kornsw_pg_`, constants `KORNSW_PG_*`.
- Project language German (UI, `README.md`, `doc/`); code, comments and skills English.
- Minimum versions stay at WordPress 6.0 / PHP 7.4 until the user decides otherwise: write PHP 7.4 compatible code (no `match`, union types, constructor promotion, `str_contains`, nullsafe operator, enums).

## Catalog model

- `config.php` returns a list of strings: GitHub repository URLs, WordPress.org plugin URLs (`https://wordpress.org/plugins/<slug>/`) or direct `*.update.json` URLs. These are the **favorites** (star, highlighted).
- **additional**: sources stored per installation in option `kornsw_pg_extra_sources` (`[['type' => 'repository'|'json'|'wporg', 'url' => ...]]`, format kept compatible with v1.0.x). Input may also be a bare WordPress.org slug.
- **related**: every non-archived repository named `WP-*` (case-insensitive) of the distinct GitHub owners of the favorites, if it contains `doc/*.update.json`. Errors of related repositories are never shown. Visual style: calm, set back, but not "beta".
- One entry per key (`json:<update.json url>` or `wporg:<slug>`); precedence favorite > additional > related.
- "Search for more plugins" scans only the owners' non-`WP-*` repositories (WP-* ones are listed automatically).

## GitHub access (rate limit)

- Token: constant `KORNSW_PG_GITHUB_TOKEN` (wp-config) wins over option `kornsw_pg_github_token` (AES-256-GCM via `Security\SecretCipher`, key from `wp_salt('auth')`). Sent only to `api.github.com`, validated via `/rate_limit` before saving.
- `GitHubClient::getApiJson()` caches a reduced form of each API response with its ETag (transients `kornsw_pg_gh_<md5>`); never cache raw repository listings (too large).
- `RepositoryScanner` caches per repository with `pushed_at` (option `kornsw_pg_repository_cache`); call `flush()` after a scan run.
- When the last response reported `remaining = 0`, no API request is sent until the reset; cached data is used.
- Regression guard: an unchanged landscape must cost exactly one API request per owner (covered by `tests/run-tests.php`).

## Private repositories

- `GitHub\PrivateRepositoryFallback` hooks `http_response` on every request (registered in `Plugin::boot()` before the `is_admin()` return, because auto-updates run from WP-Cron). It only replaces a **404** GET on `raw.githubusercontent.com/<owner>/<repo>/<ref>/<path>` or `github.com/<owner>/<repo>/releases/(download/<tag>|latest/download)/<asset>` when `<owner>` is a favorite owner and a token exists; content is refetched via the contents API (raw media type) or releases API (asset with `application/octet-stream`, `redirection => 0`, signed `Location` downloaded **without** token, streaming args kept).
- Public repositories must stay untouched (no extra request); the token goes only to `api.github.com`. Covered by `tests/run-tests.php`.
- `listUpdateJsonUrls()` strips the temporary `?token=` of private raw URLs.
- Private repositories are not auto-discovered (would break the one-request-per-owner guard).
- Manual update: action `kornsw_pg_update_plugin` (cap `update_plugins`, nonce `kornsw_pg_update_<md5(package)>`) runs `Plugin_Upgrader::install(..., ['overwrite_package' => true])`.

## Installation

- GitHub / update.json plugins: `Plugin_Upgrader` + `Automatic_Upgrader_Skin` via `admin-post.php` (nonce bound to `md5(package url)`).
- WordPress.org plugins: link to core `update.php?action=install-plugin` (nonce `install-plugin_<slug>`) and core details thickbox; do not replace with a background install.

## Testing

- `php tests/run-tests.php` runs the domain logic with WordPress stubs (`tests/bootstrap.php`, fake HTTP). Run it and `php -l` on changed files; UI changes need a live check in WordPress.
- There is usually no PHP on the developer machine; a portable PHP (windows.php.net) in a scratch folder works.

## Do not touch

`kornsw-plugingallery/self-update.php`, the SELF-UPDATE block and headers `Version` / `Plugin URI` / `Update URI` in the entry file, `doc/*.update.json`, `doc/*.updater-prefix.txt`, `doc/versioninfo.json`, `.kvu/`, `.github/workflows/release.yml`.
