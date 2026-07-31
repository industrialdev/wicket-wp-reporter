# API schema reference

Field/value reference for `GET wicket-reporter/v1/status`. This is
documentation, not a runtime schema — there is no validation code behind
any of this, matching the plugin's lightweight-only principle. Full design
context: [wicket-atlas's feature-fleet-health-monitor.md](https://github.com/industrialdev/wicket-atlas/blob/main/plans/feature-fleet-health-monitor.md).

## Top-level response shape

`composer`, `plugins`, and `themes` are each `{ _meta: {...}, items: [...] }`
objects, not bare arrays — `_meta.count` on all three, plus
`_meta.activeCount` on `plugins`/`themes`. `latestVersion`/`updateAvailable`
are **omitted entirely** from `plugins.items[]` and `wordpress{}`, not set
to `null` — this plugin has no external version-lookup (that's a separate
service's job), and a `null` here would read ambiguously close to "no
update available" under a naive falsy check in client code.

`wordpress.metrics.totalUsers`/`usersByRole` come from one `count_users()`
call — WP core's own cheap-count API, no per-user iteration. Nested under
`wordpress.metrics{}` rather than flat on `wordpress{}`, mirroring the
integration adapters' `metrics`/`configuration` split: `version`/
`phpVersion`/`latestVersion`/`updateAvailable` are identity/version fields,
`metrics` is usage counts — a different kind of data, even though both
live under the always-present `wordpress{}` (core isn't optional the way
an integration adapter's target plugin is, so it doesn't move under
`integrations{}`, just gets its own `metrics` sub-object). `usersByRole`
is `count_users()['avail_roles']` passed through as-is: a map of role slug
to count, where a multi-role user is counted once per role they hold, not
once overall (so summing `usersByRole`'s values can exceed `totalUsers`).

`wordpress.phpVersion` is `PHP_VERSION` (e.g. `"8.2.15"`) — this site's
running PHP version, not WordPress's. Reported so a fleet monitor can
answer "can this site even upgrade WP core" (core has its own PHP
minimums per release) independently of the WP version check itself.

## Field value reference

| Field | Values | Meaning |
|---|---|---|
| `plugins/themes .items[].status` | `active`, `inactive` | Whether WordPress currently has it active. |
| `plugins/themes .items[].installType` | `composer`, `wordpress`, `manual` | `composer` = matched a `composer.lock` entry by directory name. `wordpress` = no composer match, but a wordpress.org-style `readme.txt` header found. `manual` = neither. |
| `plugins/themes .items[].updateSource` | `git`, `satispress`, `wordpress-org`, `unknown` | Where the fleet-monitor dashboard checks for a newer version. `git` = Wicket-authored (`industrialdev/*` namespace, or a direct git URL). `satispress` = licensed package (`wicketpress/*`). `wordpress-org` = public wordpress.org directory (`wp-plugin/*` or `wpackagist-plugin/*`/`wpackagist-theme/*` — both proxy the same source). `unknown` = no match. |

**Known limitation**: a theme that's Wicket-authored but not
composer-managed (e.g. `wicket-wp-theme`, `wicket-child` — installed
directly in `web/app/themes/`, no matching `composer.lock` entry) reports
`installType: manual`/`updateSource: unknown`, even though it genuinely is
a Wicket git repo. `git`-detection currently only runs off a composer
package's namespace (T4's `is_wicket_git_package()`); there's no fallback
detection path for a non-composer-managed Wicket theme yet. Not a bug —
just a real gap in current coverage, left open rather than adding an ad
hoc detection heuristic outside any scoped task.
| `composer.items[].type` | `wordpress-plugin`, `wordpress-theme`, `wordpress-muplugin`, `wordpress-core` | The only composer package types this plugin reports. |
| `site.environment` | `production`, `staging`, `development`, `sandbox` | From `wp_get_environment_type()` unless the settings override is set. |
| `collectorErrors[].collector` | `composer`, `plugins`, `themes`, `integrations`, `integrations.memberships`, `integrations.woocommerce`, `integrations.subscriptions`, `wordpress`, `site` | `composer`/`plugins`/`themes`/`wordpress`/`site` match a top-level collector throwing. `integrations` matches the whole adapter registry failing to load (rare — e.g. a fatal in `Reporter_Integrations::collect()` itself). `integrations.<slug>` matches one specific adapter throwing — every other adapter's data still returns, per the per-adapter isolation in `Reporter_Integrations::collect()`. |

## Integration adapters

`includes/Integrations/interface-integration-adapter.php` defines
`Reporter_Integration_Adapter`
(`slug()`/`plugin_slug()`/`is_available()`/`collect()`). `collect()`
returns `{metrics, configuration}` — kept as two separate top-level keys,
not one blended bag: `metrics` is pure counts/usage numbers,
`configuration` is what's set up (config/tier settings, product links,
etc.) rather than a number. `Reporter_Integrations::collect()` loops the
static `$adapters` list, skips one whose `is_available()` is false (key
absent from `integrations{}`, not an error), else calls `collect()` and
wraps the result as
`{available, active, pluginSlug, metrics, configuration}`. One adapter
throwing is caught individually and reported as `collectorErrors[]` entry
`integrations.<slug>` — it never blanks out another adapter's data.
Registering a new adapter means adding one class plus one line to
`$adapters` — no other file changes.

`pluginSlug` is the target plugin's own directory slug, matching
`plugins.items[].slug` elsewhere in this response (e.g. `'memberships'`'s
`pluginSlug` is `'wicket-wp-memberships'`) — lets a consumer join
`integrations{}` back to the installed-plugins list without guessing.
Distinct from the adapter's own `slug()`, which is the `integrations{}`
key it reports under, not a WordPress plugin slug.

**There is no runtime schema validation on an adapter's `collect()` return
value.** Each adapter's own `@return array{...}` PHPDoc on `collect()` is
the only contract — treat it as load-bearing documentation, keep it in
sync with the actual return shape.

### Convention: one adapter per plugin, always

Never fold more than one installable plugin's data into a single adapter,
even when one plugin depends on another. WooCommerce Subscriptions is a
separate plugin from WooCommerce core, so `Subscriptions_Adapter` is
separate from `Woocommerce_Adapter` — this was corrected during T9's
build, after subscription counts were first added directly to
`Woocommerce_Adapter`. Folding them together made `is_available()`
ambiguous (checking which plugin?) and forced a `null`-vs-empty hack to
signal "Subscriptions isn't installed," instead of `integrations{}` simply
omitting the `subscriptions` key the same way every other adapter's
absence already works.

### Convention: every adapter splits into `metrics` + `configuration`

This is the default shape for **every** integration adapter, not something
specific to memberships — apply it to the WooCommerce adapter and any
future one the same way:

- **`metrics`** — pure counts and usage numbers only (totals, active
  counts, anything cheap-count-shaped). If a field answers "how many," it
  belongs here.
- **`configuration`** — what's set up, not a number: settings, linked
  posts/products, type/category values, date ranges, feature flags. If a
  field answers "what kind" or "set to what," it belongs here.

When a single concept spans both (e.g. memberships' per-config
breakdown), split it into two parallel structures joined by a shared key
(memberships uses the config's `post_name` slug as that join key) rather
than mixing a count into the configuration object or vice versa — see
`integrations.memberships.metrics.active_by_config[]` vs
`integrations.memberships.configuration.configs[]` above for the worked
example.

### `integrations.memberships` (`Memberships_Adapter`)

Reads `wicket_membership`/`wicket_mship_tier`/`wicket_mship_config` post
types directly (memberships has no counting helper of its own) — post type
slugs come from `Wicket_Memberships\Helper::get_*_cpt_slug()`, not
hardcoded, so a slug rename on their side doesn't silently desync this
adapter.

#### `metrics` — pure counts

| Field | Type | Meaning |
|---|---|---|
| `total_memberships` | int | All `wicket_membership` posts, any status. |
| `total_active_memberships` | int | Memberships whose `membership_status` meta is `active`, `grace_period`, or `delayed` — matches wicket-wp-memberships' own internal "in force" definition, not just the literal `active` status. |
| `total_tiers` | int | All `wicket_mship_tier` posts. |
| `total_configs` | int | All `wicket_mship_config` posts. |
| `active_by_config[].config` | string | The config post's slug (`post_name`) — join key against `configuration.configs[].config`. |
| `active_by_config[].active` | int | In-force memberships (same 3-status definition as `total_active_memberships`) whose tier belongs to this config. Every config appears here, even one with zero tiers or zero active memberships. |

#### `configuration` — what's set up, not a number

| Field | Type | Meaning |
|---|---|---|
| `configs[].config` | string | The config post's slug (`post_name`) — join key against `metrics.active_by_config[].config`. |
| `configs[].name` | string | The config post's title (`post_title`). |
| `configs[].cycleType` | `calendar`, `anniversary`, or `null` | From the config's own `cycle_data` meta. Null if unset. |
| `configs[].calendarItems[]` | array | Named renewal seasons (`seasonName`, `active`, `startDate`, `endDate`) — only meaningful when `cycleType` is `calendar`; empty otherwise. |
| `configs[].anniversaryData` | object or `null` | `{periodCount, periodType}` — only meaningful when `cycleType` is `anniversary`; null otherwise. |
| `configs[].renewalWindowDays` | int or `null` | From the config's own `renewal_window_data.days_count` — how many days before expiry the renewal window opens. |
| `configs[].lateFeeWindowDays` | int or `null` | From the config's own `late_fee_window_data.days_count` — how many days after expiry before a late fee applies. |
| `configs[].renewalTypes` | array of string | Distinct `renewal_type` values seen across the config's tiers. Four values exist, per `wicket-wp-memberships`' own tier-edit UI (`renewalTypeOptions` in `frontend/src/membership_tiers/edit.js`): `current_tier` ("Current Tier"), `sequential_logic` ("Sequential Logic"), `form_flow` ("Renewal Form Flow"), `subscription` ("Subscription") — the last was missing from an earlier pass of this doc until audited against the actual plugin code. |
| `configs[].tierTypes` | array of string | Distinct `type` values (e.g. `individual`, `organization`) seen across the config's tiers. |
| `configs[].seatTypes` | array of string | Distinct `seat_type` values (e.g. `per_seat`) seen across the config's tiers. |
| `configs[].approvalRequired` | bool | True if any tier under this config has `approval_required` set. |
| `configs[].products[].productId` | int | WooCommerce product ID linked from a tier's `product_data`. Deduplicated across the config's tiers by product+variation. |
| `configs[].products[].variationId` | int or `null` | The product's variation ID, if any. |
| `configs[].products[].maxSeats` | int or `null` | Max seats for that product/variation (`-1` conventionally means unlimited — not normalized here, passed through as-is). |

`renewalTypes`, `tierTypes`, `seatTypes`, and `products` are all tier-level
properties aggregated as a **set** across the config's tiers, not a single
value — a config can have more than one tier, and each tier can set these
independently.

**A membership links to its config through its tier, not directly.** A
membership post stores `membership_tier_uuid` (post meta); a tier post's
own `mdp_tier_uuid`, `config_id`, `renewal_type`, `type`, `seat_type`,
`approval_required`, and `product_data` all live together inside its
serialized `tier_data` meta (not separate meta keys). The adapter reads
every tier once (cheap — a handful per site) to build a tier→config
lookup, then runs one `meta_query`-filtered, `found_posts`-only `WP_Query`
per config that has tiers — never one query per membership. Every field
on this page comes from meta already loaded this way, or one more cheap
`get_post_meta()` call per config (bounded by config count) — no extra
queries per membership, no outbound calls of any kind.

#### Example `integrations.memberships` response fragment

```json
{
  "available": true,
  "active": true,
  "pluginSlug": "wicket-wp-memberships",
  "metrics": {
    "total_memberships": 842,
    "total_active_memberships": 761,
    "total_tiers": 4,
    "total_configs": 2,
    "active_by_config": [
      { "config": "attorney-membership", "active": 611 },
      { "config": "affiliate-membership", "active": 150 }
    ]
  },
  "configuration": {
    "configs": [
      {
        "config": "attorney-membership",
        "name": "Attorney Membership",
        "cycleType": "anniversary",
        "calendarItems": [],
        "anniversaryData": { "periodCount": 1, "periodType": "year" },
        "renewalWindowDays": 30,
        "lateFeeWindowDays": 15,
        "renewalTypes": ["current_tier"],
        "tierTypes": ["individual"],
        "seatTypes": ["per_seat"],
        "approvalRequired": false,
        "products": [
          { "productId": 1837, "variationId": null, "maxSeats": -1 }
        ]
      },
      {
        "config": "affiliate-membership",
        "name": "Affiliate Membership",
        "cycleType": "calendar",
        "calendarItems": [
          {
            "seasonName": "2026-2027",
            "active": true,
            "startDate": "2026-07-01T04:00:00.000Z",
            "endDate": "2027-01-01T04:59:59.999Z"
          }
        ],
        "anniversaryData": null,
        "renewalWindowDays": 5,
        "lateFeeWindowDays": 2,
        "renewalTypes": ["sequential_logic", "form_flow"],
        "tierTypes": ["organization"],
        "seatTypes": ["per_seat", "unlimited"],
        "approvalRequired": true,
        "products": [
          { "productId": 2011, "variationId": 2012, "maxSeats": 10 },
          { "productId": 2011, "variationId": 2013, "maxSeats": 50 }
        ]
      }
    ]
  }
}
```

### `integrations.woocommerce` (`Woocommerce_Adapter`)

Uses WooCommerce's own counting APIs throughout, never a raw query —
`wc_orders_count()` already abstracts HPOS vs. legacy posts-table storage
internally and is itself WC-cached, so the adapter doesn't branch on
storage mode. `configuration` is always empty for this adapter — there's
no config/tier-style setup to report, unlike memberships.

#### `metrics` — pure counts

| Field | Type | Meaning |
|---|---|---|
| `orders` | object | One key per order status this site actually uses (from `wc_get_order_statuses()`, not a hardcoded list), each value the count from `wc_orders_count()` for that status. |
| `users.total` | int | `count_users()['total_users']` — every WP user account, not filtered to WooCommerce customers specifically. |
| `users.byRole` | object | `count_users()['avail_roles']` passed through as-is — role slug to count. A multi-role user is counted once per role, not once per person, so summing these values can exceed `users.total`. Same shape WooCommerce's own `WC_Tracker::get_user_counts()` reports; there's no WC-sanctioned way to derive a single "customers" figure from role counts without double- or under-counting multi-role users, so this adapter doesn't attempt one. |
| `totalProducts` | int | `wp_count_posts('product')->publish` — published products only. |
| `totalCoupons` | int | `wp_count_posts('shop_coupon')->publish` — published coupons only. |

#### Example `integrations.woocommerce` response fragment

```json
{
  "available": true,
  "active": true,
  "pluginSlug": "woocommerce",
  "metrics": {
    "orders": {
      "pending": 6,
      "processing": 18,
      "on-hold": 0,
      "completed": 9,
      "cancelled": 0,
      "refunded": 0,
      "failed": 0,
      "checkout-draft": 4
    },
    "users": {
      "total": 13,
      "byRole": {
        "administrator": 8,
        "subscriber": 3,
        "customer": 2
      }
    },
    "totalProducts": 24,
    "totalCoupons": 3
  },
  "configuration": {}
}
```

### `integrations.subscriptions` (`Subscriptions_Adapter`)

WooCommerce Subscriptions — a separate installable plugin from WooCommerce
core, so it gets its own adapter and its own `integrations.subscriptions`
key, absent entirely on a site without the plugin active (not present with
an empty/null value). Uses the same `wc_orders_count()` API as
`Woocommerce_Adapter`, just scoped to the `shop_subscription` order type.
`configuration` is always empty — no config/tier-style setup exists for
this adapter.

#### `metrics` — pure counts

| Field | Type | Meaning |
|---|---|---|
| `byStatus` | object | One key per subscription status (from `wcs_get_subscription_statuses()`), counted via `wc_orders_count($status, 'shop_subscription')` — same HPOS-aware, WC-cached counting API `Woocommerce_Adapter` uses for orders. |

#### Example `integrations.subscriptions` response fragment

```json
{
  "available": true,
  "active": true,
  "pluginSlug": "woocommerce-subscriptions",
  "metrics": {
    "byStatus": {
      "pending": 1,
      "active": 42,
      "on-hold": 3,
      "cancelled": 6,
      "switched": 0,
      "expired": 11,
      "pending-cancel": 0
    }
  },
  "configuration": {}
}
```
