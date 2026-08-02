## Project Overview

WordPress plugin that reports installed plugins, theme, WP core version, and composer state via one secured, local-only REST endpoint. It is the per-site data source for `wicket-fleet-monitor` (a separate Next.js dashboard, different repo) — this plugin makes **no outbound calls**; it only reads local state and reports it when asked.

Full design: [`wicket-atlas/plans/feature-fleet-health-monitor.md`](https://github.com/industrialdev/wicket-atlas/blob/main/plans/archive/feature-fleet-health-monitor.md) — read that plan before changing this plugin's schema, endpoint shape, or settings.

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
├── wicket-wp-reporter.php          # Entry point: constants, hooks, rest_api_init wiring
├── includes/
│   ├── class-reporter-settings.php # Settings section, key generation/reset, uninstall
│   ├── class-reporter-log.php      # Thin wrapper over Wicket()->log(), source = 'wicket-reporter'
│   ├── class-reporter-rest.php     # GET wicket-reporter/v1/status: auth, rate limit, response
│   ├── class-reporter-timer.php    # Per-request collector timing -> one audit log line
│   ├── class-reporter-composer.php # Composer.lock/json parser -> composer[] section
│   ├── class-reporter-plugins.php  # Plugin/theme collectors -> plugins[]/themes[] sections
│   ├── class-reporter-integrations.php # Adapter registry -> integrations{} section
│   └── Integrations/               # One adapter class per integration (memberships, WooCommerce)
├── docs/
│   ├── api-schema.md                # Field/value reference, integration adapter shapes
│   └── engineering/
│       └── release-automation.md    # Stub — canonical doc lives in wicket-atlas
├── composer.json
└── .gitignore
```

### Boot sequence

1. **File load** (`wicket-wp-reporter.php`) — constants defined, `Reporter_Log`/`Reporter_Settings`/`Reporter_Timer`/`Reporter_Rest` classes required, settings filters registered (`wicket_settings_tabs`, `wicket_settings_tab_int`), the Reset-key GET link handled via `admin_init`, and the REST route registered on `rest_api_init`.
2. **`admin_init`** — checks `function_exists('Wicket')`; if base-plugin isn't active, deactivates this plugin and shows an admin notice. This plugin has no working state without base-plugin.
3. Settings section renders inside the existing Wicket → Integrations tab (not a new top-level page) — same `wicket_settings_tabs` pattern as `wicket-wp-woo-order-status-limits` and `wicket-wp-guest-checkout`.

### API key generation — deliberately outside the settings-save pipeline

The API key field (`Reporter_Settings::render_api_key_field`) is a **custom-rendered field**, not a normal WPSettings option value — only the key's hash is ever WPSettings-managed-adjacent storage; the real value lives in its own standalone `wicket_reporter_api_key_hash` option. Reset is a plain nonce-protected GET link handled by `Reporter_Settings::maybe_handle_reset()` on `admin_init`, entirely separate from the tab's own Save Changes submit.

- Raw key: `wp_generate_password(48, false, false)`.
- Stored: only its hash, via `wp_hash_password()`/`wp_check_password()` (WordPress's own password-hashing pair — explicitly documented as safe to use for non-user-password values, not a misuse here).
- Shown once: flashed via a 60-second, current-user-scoped transient, read and deleted in the same `admin_notices` request — a page refresh never shows the raw key twice. Afterward the field shows a masked placeholder, never the real value.
- Regenerating immediately invalidates the previous key (single stored hash, no key history).

### Enabled / environment-override settings

`wicket_reporter_enabled` and `wicket_reporter_environment_override` are normal WPSettings-managed checkbox/select fields — **not** standalone wp_options rows. WPSettings stores one aggregate option (`wicket_settings`) for the whole Wicket settings page, keyed by field name. Read these at runtime with `wicket_get_option('wicket_reporter_enabled')` / `wicket_get_option('wicket_reporter_environment_override')` (base-plugin helper), never `get_option()` directly.

### REST endpoint (T2)

`Reporter_Rest::register_routes()` registers `GET wicket-reporter/v1/status`. `check_permission()` runs, in order: (1) disabled check via `wicket_get_option('wicket_reporter_enabled')` → 403 if off, (2) header-only Bearer token vs the stored hash → 401 if missing/invalid, (3) per-key transient rate limit → 429 if exceeded. `handle_status()` builds the response, wrapping each collector section in `Reporter_Timer::time()`.

### Composer, plugin, and theme collectors (T3, T4)

Collector order matters: `Reporter_Composer::collect()` (T4) runs **before** `Reporter_Plugins::collect_plugins()`/`collect_themes()` (T3), since T3 cross-references T4's parsed output by directory name to derive `installType`/`updateSource` per plugin/theme — see the plan's field-sourcing notes. Every collector call in `handle_status()` goes through `Reporter_Rest::run_collector()`, which wraps it in both `Reporter_Timer::time()` (audit log) and a try/catch (Endpoint resilience — a thrown exception never 500s the whole response; the section falls back to empty/null and the failure is appended to `collectorErrors[]`).

`Reporter_Composer::collect()` reads `composer.lock`/`composer.json` directly (`json_decode`, no `shell_exec`, no composer binary dependency), filtered to `wordpress-plugin`/`wordpress-theme`/`wordpress-muplugin`/`wordpress-core` types only — a real fleet `composer.lock` carries dozens of transitive PHP libraries (Carbon, Doctrine, etc.) that aren't WordPress-installable units and are dropped as noise.

`composer[]`, `plugins[]`, and `themes[]` in the response are each `{ _meta: {...}, items: [...] }` objects, not bare arrays. `latestVersion`/`updateAvailable` are **omitted entirely** from `plugins[].items[]` and `wordpress{}`, not set to `null` — this plugin has no external version-lookup, and a `null` here would read ambiguously close to "no update available" under a naive falsy check in client code.

Full field/value reference and worked examples, including the integration adapter contract and `integrations.memberships` shape: [`docs/api-schema.md`](docs/api-schema.md).

### Integration adapters

`includes/Integrations/interface-integration-adapter.php` defines `Reporter_Integration_Adapter` (`slug()`/`plugin_slug()`/`is_available()`/`collect()`). `collect()` returns `{metrics, configuration}` — the standing convention for every adapter (`metrics` = pure counts, `configuration` = what's set up), not just something memberships happens to do. `plugin_slug()` returns the target plugin's own directory slug (matching `plugins.items[].slug`), letting a consumer join `integrations{}` back to the installed-plugins list — distinct from `slug()`, which is the `integrations{}` key itself. `Reporter_Integrations::collect()` loops the static `$adapters` list, skips one whose `is_available()` is false, else calls `collect()` and wraps it as `{available, active, pluginSlug, metrics, configuration}`; one adapter throwing is caught individually so it never blanks out another adapter's data. Registering a new adapter means adding one class plus one line to `$adapters` — no other file changes. Details and each adapter's shape: [`docs/api-schema.md`](docs/api-schema.md).

### Performance audit log

`Reporter_Timer` times each collector section wrapped in `Reporter_Timer::time($name, $callable)` during a `handle_status()` call, then logs **one** summary line at the end (`Reporter_Log::info('Status generation complete', ['total_ms' => ..., 'collectors' => [['name' => ..., 'ms' => ...], ...]])`) — a single write per request regardless of collector count, so it stays a cheap on-the-fly performance snapshot rather than a per-collector logging burden. A separate "Status generation requested" line logs at the very start of the request.

### Uninstall vs. deactivate

`Reporter_Settings::on_uninstall()` (registered via `register_uninstall_hook`, not a deactivation hook) removes the stored API key hash option, unsets the `enabled`/`environment_override` keys from the shared `wicket_settings` option (without deleting that option itself — other plugins' settings live in it too), and removes any of this plugin's transients. Deactivating and reactivating must **never** force key regeneration — only a full uninstall clears the key.

**Lightweight-only, non-negotiable**: every collector/adapter reports cheap counts only (`COUNT(*)`-shaped queries). Nothing that scans/aggregates many rows. If a metric can't be a cheap count, it doesn't belong in this plugin.

## Constants

```php
WICKET_REPORTER_VERSION                    // Plugin version string
WICKET_REPORTER_PLUGIN_DIR                 // Absolute path to plugin directory (trailing slash)
WICKET_REPORTER_PLUGIN_URL                 // URL to plugin directory (trailing slash)
WICKET_REPORTER_OPTION_API_KEY_HASH        // 'wicket_reporter_api_key_hash'
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
