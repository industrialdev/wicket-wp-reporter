# Changelog

All notable changes to this plugin are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

<!-- new releases inserted below this line -->

## [1.1.3] - 2026-09-11

### Fixed
- lower REST rate limits to 30/30, add a separate force-refresh cap


## [1.1.2] - 2026-09-11

### Other
- Update README.md


## [1.1.1] - 2026-09-04

### Fixed
- version-bump.php exits non-zero on a partial file update
- force nocache headers on the status response object directly
- surface tier/config-enumeration truncation in collectorErrors[]
- let an authenticated caller force past the 8h status cache
- detect a child theme as its own updateSource, not unknown
- surface a degraded-capabilities list when an integration adapter fails
- escape at point of output in render_api_key_field, drop inline styles
- make uninstall cleanup object-cache-aware, guard shared-option write
- resolve the dispatched route instead of sniffing REQUEST_URI
- hide the reporter status route from the public REST index
- drop legacy API-key hash rotation support
- narrow is_wicket_git_package to the industrialdev org, not any git source
- anchor the wc- status prefix strip in both WooCommerce adapters
- use filtered theme accessors and mark an active child theme's parent active too
- validate the environment override against the allowlist at read time
- bound the tier and config queries, exclude trash, surface truncation
- exclude draft posts from total_memberships and related counts
- normalise wp_count_posts() through one shared helper
- serve a stale response instead of an empty 503 on lock contention
- guard Reporter_Log against a missing Wicket() function
- add an independent per-IP rate-limit bucket
- bucket the rate-limit transient key so the window cannot slide

### Documentation
- document monitor{}'s capabilities/degradedCapabilities/generationMs
- compress inline comments, remove plan-ID references
- document the operational contract in api-schema.md
- remove stale legacy-key comment from check_permission
- add security and quality audit findings
- sync API key hash scheme with PR #2

### CI
- match release markers on the first line only
- stage only the files the bumper modified
- anchor the version replace to the plugin header


## [1.1.0] - 2026-08-10

### Fixed
- **BREAKING** harden reporter auth, caching, and memberships queries


## [1.0.1] - 2026-08-02

### Added
- add packageKind field to distinguish real plugins from composer-only packages
- add plugin_slug() to integration adapters
- report wordpress.phpVersion
- expand WooCommerce metrics, split subscriptions into own adapter
- fill in WP core user counts and add WooCommerce adapter (T8, T9)
- add memberships integration adapter (T7)
- add integration adapter interface and registry (T6)
- cache the full status response for 8h (T5)
- add composer, plugin, and theme collectors (T3, T4)
- add status REST endpoint with auth, rate limit, and timing audit log (T2)
- scaffold plugin, settings tab, API key generation (T1)

### Fixed
- correct enabled/environment-override option storage
- hash API key storage instead of plaintext

### Documentation
- document T3/T4 collectors and field value reference

### Maintenance
- wire up release automation

