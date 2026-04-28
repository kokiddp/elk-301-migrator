# ELK 301 Migrator

ELK 301 Migrator is a WordPress admin tool for building a migration redirect table from the public URLs currently known to a site.

It scans public posts, pages, custom post types, taxonomy terms, post type archives, author archives, the front/blog pages, and media attachments. You can then fill target URLs, import/export mappings, and download redirect outputs for CSV, JSON, Apache `.htaccess`, or Nginx.

When WPML or Polylang is active, the scan also expands supported sources into language-specific public URLs so you can build redirects per locale instead of only from the current language view.

## Requirements

- WordPress 5.2 or newer
- PHP 7.4 or newer
- A WordPress administrator account with `manage_options`

## Installation

1. Place this directory at `wp-content/plugins/elk-301-migrator`.
2. Activate **ELK 301 Migrator** in WordPress.
3. Open **Tools > 301 Migrator**.

## Usage

1. Run a scan.
2. If you use a translation plugin, re-run the scan after changing languages, translated content, or translated media.
3. Optionally limit attachments by upload month and file extension.
4. Review the scan results. When multilingual URLs are present, rows are grouped by language inside each content section.
5. Fill the target URL column for rows that should redirect.
6. Mark rows as ignored when they intentionally do not need a target.
7. Save targets.
8. Export the result in the format needed by your deployment.

Targets can be either site-relative paths such as `/new-page` or absolute `http` / `https` URLs. Empty targets are allowed while drafting; CSV and JSON exports keep them empty, while `.htaccess` and Nginx exports use `NEW_URL` as a deployment placeholder.

Ignored rows are saved as admin review state and no longer receive the missing-target highlight. Ignoring a row does not change export behavior; use the export filters when you want to exclude unmapped rows.

## Import and Export

JSON exports from this plugin can be imported later. Imports only update sources that exist in the current scan, so run a fresh scan before importing mappings for another site state.

Export options include:

- Exclude rows without a target.
- Exclude identity redirects where source and target resolve to the same local path.
- Exclude generated comments from `.htaccess` and Nginx output.

## Data Storage

The plugin stores scan results and target mappings in WordPress options:

- `elk_301_migrator_scan`
- `elk_301_migrator_targets`
- `elk_301_migrator_ignored`

The plugin does not create database tables.

## Multilingual Support

- WPML and Polylang expand translated posts, pages, terms, the posts page, and translated attachment file URLs when real translated objects exist.
- The home page, archives, and author URLs are expanded on a best-effort basis using the translation plugin's permalink APIs or language home URLs.
- The scan deduplicates identical URLs automatically, so untranslated items stay single-row even when multiple languages are configured.
- In the admin results table, multilingual rows are grouped by language within each scan section to make per-locale review faster.
- Other translation plugins can extend the scanner with `elk_301_migrator_translation_languages`, `elk_301_migrator_translated_url`, and `elk_301_migrator_url_variants`.

## License

This project is licensed under GPL v2 or later. See [LICENSE.md](LICENSE.md).

For behavior or workflow changes, keep `README.md`, `CONTRIBUTING.md`, and `AGENTS.md` in sync.
