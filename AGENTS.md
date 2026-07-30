## Project Overview

WordPress plugin that reports installed plugins, theme, WP core version, and composer state via one secured, local-only REST endpoint. It is the per-site data source for `wicket-fleet-monitor` (a separate Next.js dashboard, different repo) — this plugin makes **no outbound calls**; it only reads local state and reports it when asked.

Full design: [`wicket-atlas/plans/feature-fleet-health-monitor.md`](https://github.com/industrialdev/wicket-atlas/blob/main/plans/feature-fleet-health-monitor.md) — read that plan before changing this plugin's schema, endpoint shape, or settings.

## Development Commands

```bash
composer install
composer cs:lint     # check code style (dry-run)
composer cs:fix       # auto-fix code style
composer check         # alias for cs:lint
composer version-bump  # do not run manually except to preview — see Release Process
```

## Architecture

### Plugin structure

```
wicket-wp-reporter/
├── wicket-wp-reporter.php          # Entry point: constants, hooks, admin_post_ handler wiring
├── includes/
│   ├── class-reporter-settings.php # Settings section, Generate-key button, uninstall
│   ├── class-reporter-log.php      # Thin wrapper over Wicket()->log(), source = 'wicket-reporter'
│   └── Integrations/               # One adapter class per integration (memberships, WooCommerce)
├── docs/engineering/
│   └── release-automation.md       # Stub — canonical doc lives in wicket-atlas
├── composer.json
└── .gitignore
```

### Boot sequence

1. **File load** (`wicket-wp-reporter.php`) — constants defined, `Reporter_Log`/`Reporter_Settings` classes required, settings filters registered (`wicket_settings_tabs`, `wicket_settings_tab_int`), the `admin_post_wicket_reporter_generate_key` action registered.
2. **`admin_init`** — checks `function_exists('Wicket')`; if base-plugin isn't active, deactivates this plugin and shows an admin notice. This plugin has no working state without base-plugin.
3. Settings section renders inside the existing Wicket → Integrations tab (not a new top-level page) — same `wicket_settings_tabs` pattern as `wicket-wp-woo-order-status-limits` and `wicket-wp-guest-checkout`.

### API key generation — deliberately outside the settings-save pipeline

The Generate/Regenerate Key button is a **custom-rendered field** (`Reporter_Settings::render_api_key_field`), not a normal WPSettings option value — the vendored `jeffreyvanrossum/wp-settings` library has no button/action option type. The button posts to `admin-post.php?action=wicket_reporter_generate_key`, handled by `Reporter_Settings::handle_generate_key`, entirely separate from the tab's own Save Changes submit. This matters: a click on Generate Key must not be silently discarded or overwritten by an unrelated settings-form submit on the same page.

- Raw key: `wp_generate_password(48, false, false)`.
- Stored: only its hash, via `wp_hash_password()`/`wp_check_password()` (WordPress's own password-hashing pair — explicitly documented as safe to use for non-user-password values, not a misuse here).
- Shown once: flashed via a 60-second, current-user-scoped transient, read and deleted in the same `admin_notices` request — a page refresh never shows the raw key twice.
- Regenerating immediately invalidates the previous key (single stored hash, no key history).

### Uninstall vs. deactivate

`Reporter_Settings::on_uninstall()` (registered via `register_uninstall_hook`, not a deactivation hook) removes the stored API key hash, the enabled/environment-override options, and any of this plugin's transients. Deactivating and reactivating must **never** force key regeneration — only a full uninstall clears the key.

### Integration adapters (T6/T7/T11/T12 — not all built yet)

`includes/Integrations/` holds one adapter class per known integration, each implementing a common `is_available()`/`collect()` interface (see the plan's Integrations model section). Sibling plugins stay completely unaware of this plugin — no filter hooks added on their side. Adding a new integration means adding one adapter class here, not touching the target plugin.

**Lightweight-only, non-negotiable**: every collector/adapter reports cheap counts only (`COUNT(*)`-shaped queries). Nothing that scans/aggregates many rows. If a metric can't be a cheap count, it doesn't belong in this plugin — see the plan's "Lightweight-only, stack-wide principle" section.

## Constants

```php
WICKET_REPORTER_VERSION                    // Plugin version string
WICKET_REPORTER_PLUGIN_DIR                 // Absolute path to plugin directory (trailing slash)
WICKET_REPORTER_PLUGIN_URL                 // URL to plugin directory (trailing slash)
WICKET_REPORTER_OPTION_ENABLED             // 'wicket_reporter_enabled'
WICKET_REPORTER_OPTION_API_KEY_HASH        // 'wicket_reporter_api_key_hash'
WICKET_REPORTER_OPTION_ENV_OVERRIDE        // 'wicket_reporter_environment_override'
WICKET_REPORTER_TRANSIENT_PREFIX           // 'wicket_reporter_'
```

## Coding Standards

- **PHP 8.1+** minimum.
- **No namespace** — procedural entry point + static classes (`Reporter_Settings`, `Reporter_Log`), same convention as `wicket-wp-woo-order-status-limits`.
- **No Composer autoloader for plugin classes** — loaded via direct `require_once` from the main file.
- **Text domain**: `wicket-reporter`.
- Every collector/adapter call must be individually wrapped (try/catch) — one failing collector must never 500 the whole endpoint. Its section is omitted and the failure appended to `collectorErrors[]` in the response.

## Security

- **Header-only bearer auth** on the REST endpoint (RFC 6750) — the API key is never accepted via query string, cookie, or request body. See the plan's API wireframe section.
- **Nonce verification** — the Generate/Regenerate Key button uses `wp_nonce_field()`/`check_admin_referer()`.
- **Capability check** — `current_user_can('manage_options')` before generating a key.
- **Per-key rate limiting** on the REST endpoint (transient-based counter) — defensive-in-depth alongside the API key gate.

## Out of scope (do not add)

- Any outbound/external API call from this plugin. External version-lookup (wordpress.org, GitHub, SatisPress) is `wicket-fleet-monitor`'s job, not this plugin's — see the plan's Scope section.
- WordPress multisite/network support. Single-site activation only.
- Any metric that isn't a cheap count.

## Release Process (Automated)

Releases are **fully automated**. Merging a PR to `main` cuts a release via the `wicket-release-bot` GitHub App: it bumps the version, prepends `CHANGELOG.md`, commits `chore(release): x.y.z`, and pushes the matching git tag. No one needs push access to `main`.

**Never do these by hand:** bump the version, edit `composer.json` / the main file header / `*_VERSION` constants, or create git tags. The bot owns all of that after merge.

### Releasing (default behavior)

Every PR merged to `main` releases automatically with a **patch** bump. Control the bump by putting a marker in the **PR title** (squash-merge makes the title the commit message):

| Marker | Result |
|---|---|
| _(none)_ | patch (`1.0.0` -> `1.0.1`) |
| `#minor` | minor (`1.0.0` -> `1.1.0`) |
| `#major` | major (`1.0.0` -> `2.0.0`) |
| `#norelease` | no bump, no tag |

### Not releasing

Add `#norelease` to the PR title for docs/tooling-only changes that should not cut a version. **Every merge releases unless the message contains `#norelease`.**

### Commit conventions that affect the changelog

- Use conventional prefixes: `feat:`, `fix:`, `docs:`, `chore:`, `perf:`, `refactor:`, etc. The changelog groups entries by prefix.
- `feat!:` (or any `!:`) flags a **BREAKING** change in the changelog.
- **Squash-merge** yields the cleanest changelog (one PR = one line).
- A release lists **everything merged since the last tag**, not just the triggering PR. Catch-up is expected.

### Local version bump (optional)

`composer version-bump` (or `php .ci/version-bump.php`) edits version files only; it never commits or tags. Use it to preview, not to release.

Full details, markers, and troubleshooting: [`docs/engineering/release-automation.md`](docs/engineering/release-automation.md).
