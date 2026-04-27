# Contributing

Thanks for working on ELK 301 Migrator.

## Development Setup

1. Install the plugin in a local WordPress site at `wp-content/plugins/elk-301-migrator`.
2. Activate the plugin from the WordPress admin.
3. Open **Tools > 301 Migrator** to test scan, save, import, and export flows.

## Code Style

- Keep PHP compatible with PHP 7.4.
- Follow the surrounding procedural WordPress style.
- Use capabilities and nonces for admin actions.
- Sanitize request data with WordPress helpers before use.
- Escape rendered HTML with `esc_html()`, `esc_attr()`, or `esc_url()` as appropriate.
- Prefer small, focused functions over broad rewrites.

## Testing

Run syntax checks before submitting:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

For behavior changes, test these flows manually:

- Run a scan with and without attachment filters.
- Save, clear, and update target URLs.
- Import a JSON export.
- Export CSV, JSON, `.htaccess`, and Nginx formats.
- Confirm identity redirects can be excluded.

## License

By contributing, you agree that your contribution is provided under GPL v2 or later. See [LICENSE.md](LICENSE.md).
