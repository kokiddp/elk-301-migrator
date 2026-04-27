# AGENTS.md

## Scope

These instructions apply to the `elk-301-migrator` plugin directory.

## Project Notes

- This is a small WordPress plugin with procedural PHP.
- Keep changes scoped to the plugin unless the task explicitly requires WordPress application changes.
- The admin UI lives in `includes/admin.php`.
- URL scanning and saved target storage live in `includes/scanner.php`.
- Export builders live in `includes/exporter.php`.

## Coding Rules

- Preserve compatibility with PHP 7.4 and WordPress 5.2+.
- Use WordPress APIs for permissions, nonces, escaping, sanitization, redirects, options, URLs, and JSON.
- Keep public/admin actions restricted to users with `manage_options`.
- Sanitize input before storage and escape output at render time.
- Do not add build tooling or dependencies unless the task explicitly needs them.
- Keep generated server config output free of raw newlines, tabs, or spaces inside directive tokens.
- Add `// SPDX-License-Identifier: GPL-2.0-or-later` to new PHP files.

## Verification

Before handing work back, run PHP syntax checks for changed PHP files:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

When WordPress behavior changes, manually test the Tools > 301 Migrator screen in a local WordPress install where possible.

## License

The plugin is licensed under GPL v2 or later. Keep `LICENSE.md`, plugin headers, and docs aligned if license metadata changes.
