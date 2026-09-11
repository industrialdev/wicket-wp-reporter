# Wicket Reporter

Reports installed plugins, theme, WordPress core, and composer state via a single secured, local-only REST endpoint — the per-site data source for [`wicket-fleet-monitor`](https://github.com/industrialdev/wicket-fleet-monitor)'s fleet-wide health dashboard.

Part of the Fleet Health Monitor project. Plan and full design: [`wicket-atlas/plans/feature-fleet-health-monitor.md`](https://github.com/industrialdev/wicket-atlas/blob/main/plans/feature-fleet-health-monitor.md).

## What it does

- Exposes `GET /wp-json/wicket-reporter/v1/status` reporting plugins, theme, WP core version, and `composer.json`/`composer.lock` state.
- Reports optional integration metrics (memberships, WooCommerce) when those plugins are active.
- Makes **no outbound calls** — everything reported is read from this site's own filesystem/database.
- Caches its response in a transient so repeat requests don't re-scan the filesystem/DB every time.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- [`wicket-wp-base-plugin`](https://github.com/industrialdev/wicket-wp-base-plugin) active (hard dependency — this plugin deactivates itself with an admin notice if base-plugin isn't active)

## Setup

1. Activate the plugin (requires `wicket-wp-base-plugin` active first).
2. Go to **Wicket → Integrations → Wicket Reporter**.
3. Toggle **Enable Wicket Reporter**.
4. Click **Reset** and copy the raw key shown — it is shown once at the top in the status flash and not stored anywhere retrievable afterward.
5. Add this site to `wicket-fleet-monitor`'s site registry using that key (see that repo's docs for the registry entry format).

## Settings

| Setting | What it does |
|---|---|
| Enable Wicket Reporter | Hard kill switch. When off, the REST endpoint refuses every request — no collectors run, no data leaves the site. |
| Environment Override | Overrides this site's self-reported environment for the fleet monitor. Defaults to auto-detect via `wp_get_environment_type()`. |
| Generate / Regenerate Key | (Re)generates the API key. Regenerating immediately invalidates the previous key. |

## Development

```bash
composer install
composer cs:lint     # check code style
composer cs:fix      # auto-fix code style
```

Release process is fully automated — see [`docs/engineering/release-automation.md`](docs/engineering/release-automation.md). Never hand-bump the version or create tags.
