# Changelog

All notable changes to this plugin are documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/).

<!-- new releases inserted below this line -->

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

