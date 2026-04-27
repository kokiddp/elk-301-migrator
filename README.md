# ELK 301 Migrator

ELK 301 Migrator is a WordPress admin tool for building a migration redirect table from the public URLs currently known to a site.

It scans public posts, pages, custom post types, taxonomy terms, post type archives, author archives, the front/blog pages, and media attachments. You can then fill target URLs, import/export mappings, and download redirect outputs for CSV, JSON, Apache `.htaccess`, or Nginx.

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
2. Optionally limit attachments by upload month and file extension.
3. Fill the target URL column for rows that should redirect.
4. Save targets.
5. Export the result in the format needed by your deployment.

Targets can be either site-relative paths such as `/new-page` or absolute `http` / `https` URLs. Empty targets are allowed while drafting; CSV and JSON exports keep them empty, while `.htaccess` and Nginx exports use `NEW_URL` as a deployment placeholder.

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

The plugin does not create database tables.

## License

This project is licensed under GPL v2 or later. See [LICENSE.md](LICENSE.md).
