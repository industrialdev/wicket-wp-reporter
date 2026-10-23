# Repository Guidelines

## What This Plugin Is

WordPress plugin that reports installed plugins, theme, WP core version, and composer state via one secured, local-only REST endpoint. It is the per-site data source for `wicket-fleet-monitor` (a separate Next.js dashboard, different repo) — this plugin makes **no outbound calls**; it only reads local state and reports it when asked.

For architecture (boot sequence, API key generation, settings, REST endpoint, collectors, integration adapters, audit log, uninstall behavior, constants) and the full field/value reference: `@see atlas://packages/wicket-wp-reporter.md`.

Full design plan: `@see atlas://plans/feature-fleet-health-monitor.md` — read it before changing this plugin's schema, endpoint shape, or settings.

## Documentation

The Atlas knowledge base holds the full engineering and product documentation for the whole Wicket stack. Start at `@see atlas://packages/wicket-wp-reporter.md` for this package. The repo docs/ folder, when present, holds only public end-consumer guides; engineering material never lives beside the code.

## Development Commands

```bash
composer install
composer cs:lint      # check code style (dry-run)
composer cs:fix       # auto-fix code style
composer check        # alias for cs:lint
composer version-bump # preview only — releases are automated
```

## Non-Negotiable Constraints

- **Lightweight-only**: every collector/adapter reports cheap counts only (`COUNT(*)`-shaped queries). Nothing that scans or aggregates many rows. If a metric cannot be a cheap count, it does not belong in this plugin.
- **No outbound calls**: external version-lookup (wordpress.org, GitHub, SatisPress) is `wicket-fleet-monitor`'s job, not this plugin's.
- **No multisite/network support**: single-site activation only.

## Coding Standards

- PHP 8.1+ minimum; no namespace — procedural entry point plus static classes, same convention as `wicket-wp-woo-order-status-limits`.
- No Composer autoloader for plugin classes — direct `require_once` from the main file.
- Text domain: `wicket-reporter`.
- Every collector/adapter call is individually wrapped in try/catch — one failing collector must never 500 the whole endpoint; its section is omitted and the failure appended to `collectorErrors[]`.

## Security

- Header-only bearer auth on the REST endpoint (RFC 6750) — the API key is never accepted via query string, cookie, or request body.
- Nonce verification for the Generate/Regenerate Key button; `current_user_can('manage_options')` before generating a key.
- Per-key transient rate limiting on the REST endpoint — defensive-in-depth alongside the API key gate.

## Release & Branch Workflow

All work happens on branches. `main` is locked; changes land via peer-reviewed Pull Request. Never commit to `main` directly, and never push or open a PR without explicit human approval.

Merging a PR to `main` **auto-releases** via the `wicket-release-bot` GitHub App: version bump, `CHANGELOG.md` update, git tag. Never bump versions or create tags by hand. The bump level comes from a marker in the PR title (squash-merge makes it the commit message): _(none)_ / `#patch` = patch, `#minor`, `#major`, or `#norelease` (no release; use for docs/tooling-only merges). Conventional commit prefixes (`feat:`, `fix:`, `docs:`, ...) drive changelog grouping; a `!` (e.g. `feat!:`) flags a BREAKING change.
