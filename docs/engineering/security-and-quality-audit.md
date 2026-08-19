# Wicket Reporter — independent audit

**Date:** 2026-08-19
**Scope:** `wicket-wp-reporter` @ `main` (v1.1.0, commit `cc3d6c7`) — all 13 PHP
files under `wicket-wp-reporter.php` / `includes/`, plus `.ci/` and release
workflow. ~2,600 LOC.
**Method:** Full first-hand read of every source file, with each behavioural
claim checked against the actual WordPress core implementation in
`src/web/wp/wp-includes/` (`set_transient()`, `wp_count_posts()`,
`count_users()`, `rest_send_nocache_headers`) and against the real dependency
signatures in `wicket-wp-base-plugin` and `wicket-wp-memberships`. `php -l`
clean across all files.

## Atlas pre-check (per the stack Atlas-first rule)

- Package doc: `atlas/packages/wicket-wp-reporter.md` (active, tier
  `integration`, owner adrian).
- Active plan touching this area:
  `atlas/plans/feature-fleet-monitor-production-readiness.md` (status
  `proposed`).
- **No collision.** That plan's `contract.stable`, `contract.unstable`, and
  `contract.editing` are all empty, its `components:` list is
  `[wicket-fleet-monitor]` only, and it states explicitly that "the reporter
  plugin and its API contract are unchanged, so M1's shipped artifact is
  unaffected." Nothing in this audit's target paths is under `contract.editing`,
  so no stop condition applies.
- This audit is **findings only** — no code was changed. Anything acted on later
  should be recorded in the package doc or a plan per the "Keep it current"
  rule, and versions must not be hand-edited (release bot owns them).

## Overall assessment

This is a well-built, unusually disciplined plugin. The security posture is
genuinely good and shows evidence of a prior hardening pass that landed real
fixes: header-only bearer auth with `hash_equals()`, no query-string key
fallback, a scoped `rest_send_nocache_headers` filter, per-collector
`try`/`catch` resilience, cache-stampede locking, the `count_users()` memo, and
the deliberate `latestVersion`-omitted-not-null schema decision. The comment
density explaining *why* each of those exists is a real asset.

The findings below are therefore mostly **correctness and robustness**, not
architectural. **No critical vulnerability was found.** The most consequential
items are H1 (rate limiter cannot recover — availability), H2 (a fatal on the
memberships path is caught but rate-limits recovery), and H3 (a WordPress core
return-type mismatch that silently zeroes a metric).

| ID | Severity | Area | Summary |
|---|---|---|---|
| H1 | High | Availability | Rate-limit window slides on every request — a sustained caller locks itself out indefinitely |
| H2 | High | Correctness | `wp_count_posts()` returns `stdClass`; `count_posts()` casts with `(array)` — works, but `count_posts_by_status()` in the Woo adapter is the fragile twin |
| H3 | High | Correctness | Cache lock returns 503 on a *cold* cache while holding no data — first fleet poll after expiry can fail for all callers |
| M1 | Medium | Security | Kill switch is fail-open on a missing/corrupt `wicket_settings` option only in the *disabled* direction — verify default |
| M2 | Medium | Security | Raw API key sits in a DB transient for 60s, readable by any DB-level access |
| M3 | Medium | Correctness | `check_permission()` runs `Reporter_Settings::hash_token()` before checking the class exists |
| M4 | Medium | Performance | `posts_per_page => -1` on tiers and configs is unbounded |
| M5 | Medium | Correctness | Environment override accepts any stored string — no allowlist at read time |
| M6 | Medium | Logic | `is_wicket_git_package()` treats *any* git-sourced package as Wicket-authored |
| M7 | Medium | Correctness | Legacy-hash rotation silently breaks a working key with no REST-side signal |
| L1–L8 | Low | Various | See detail below |

---

## High

### H1 — The rate-limit window slides, so a steady caller can never recover

`includes/class-reporter-rest.php:174-192`

```php
$count = (int) get_transient($transient_key);
if ($count >= self::RATE_LIMIT_MAX_REQUESTS) { return 429; }
set_transient($transient_key, $count + 1, self::RATE_LIMIT_WINDOW_SECONDS);
```

**Verified against core:** `set_transient()` in
`src/web/wp/wp-includes/option.php` rewrites `_transient_timeout_*` to
`time() + $expiration` on *every* call, including updates to an existing
transient. So this is not a fixed 60-second window — each accepted request
pushes the expiry 60 seconds further out.

**Failure scenario:** a caller (or the fleet monitor with a retry loop, or any
unauthenticated scanner sending a *valid* key) makes 60 requests in 10 seconds.
The counter hits 60. Every subsequent request 429s. Because the 429 branch
returns *before* `set_transient()`, the transient does eventually expire 60s
after request #60 — but the deeper problem is the opposite case: a caller
sustaining ~1 request/second for 60+ seconds keeps refreshing the timeout while
incrementing the count, hits 60, and the window it needs to age out has been
continuously extended. Recovery is possible but the effective window is
unpredictable and much longer than the documented 60s.

**Also:** the counter is keyed on `md5($token)`. MD5 is fine as a bucket
identifier here (the comment correctly says so), but it means the limit is
per-key, not per-IP — one shared fleet key across many monitor instances shares
one 60-req bucket. At 20+ sites with retries, that ceiling is reachable in
normal operation.

**Recommendation:** make the window fixed. Store `['count' => n, 'start' => ts]`
and only reset when `time() - start > WINDOW`; or store the counter under a key
that includes the window bucket (`floor(time() / 60)`) so it expires
deterministically. Also consider raising `RATE_LIMIT_MAX_REQUESTS` or scoping it
per-IP-plus-key, given the 8h response cache already makes repeat requests cheap.

### H2 — `wp_count_posts()` returns an object; one call site handles it, the twin does not

`includes/Integrations/class-memberships-adapter.php:130-144` and
`includes/Integrations/class-woocommerce-adapter.php:105-110`

**Verified against core:** `wp_count_posts()` in
`src/web/wp/wp-includes/post.php` ends with `$counts = (object) $counts;` and is
documented `@return stdClass`.

The memberships collector does `foreach ((array) $counts as $status => $n)` —
correct, the cast makes public properties iterable. The WooCommerce adapter does
`(int) ($counts->{$status} ?? 0)` — also correct *today*.

The real defect is that these two functions solve the same problem two
incompatible ways, and neither validates the input. `wp_count_posts()` is
filtered (`apply_filters('wp_count_posts', ...)`), so a third-party plugin
returning an array — which several do — makes the Woo adapter's `->{$status}`
throw `Attempt to read property on array`, and makes the memberships version
silently keep working. That asymmetry is the bug: the same core call has two
different robustness levels in the same response.

Additionally, `count_posts()` in the memberships adapter sums *every* status
except `trash` and `auto-draft`. `get_post_stati()` includes `inherit` (used for
revisions and attachments) and any custom status a plugin registers. For the
membership CPT that is probably harmless, but "total_memberships" summing
`draft` + `pending` + `private` + `future` + any custom status is a broader
definition than the comment's claim of "matches what an admin sees in the list
table" — the list table's "All" count excludes `draft` from the default view.

**Recommendation:** one shared helper that normalises `wp_count_posts()` to an
array with `is_object($c) ? get_object_vars($c) : (array) $c`, used by both
adapters. Separately, decide explicitly which statuses count toward
`total_memberships` and state it in `docs/api-schema.md` rather than
implying "everything but trash."

### H3 — The stampede lock 503s callers when there is no cached response to fall back on

`includes/class-reporter-rest.php:198-242`

The lock is correct in shape, but consider the cold-cache path with more than
one caller:

1. Cache expires (8h TTL, `false === $cached`).
2. Request A wins the lock, starts a 6-second collection run.
3. Requests B, C, D arrive → `get_transient($lock_key)` is truthy → each gets
   `503 status_generation_in_progress` with `Retry-After: 5`.

B/C/D receive **no data at all**, not stale data. The monitor's own audit
(`feature-fleet-monitor-production-readiness.md`, cluster 2) makes the point
that failures in the *reassuring* direction are the dangerous ones — but a hard
503 on the first poll after every cache expiry is the kind of intermittent
failure that gets papered over with a retry and then hides a real outage.

Worse: each of those 503 responses **still consumed a rate-limit slot** (the
limiter runs in `check_permission()`, before `handle_status()`). So a
cache-expiry event plus a monitor retry loop burns the H1 budget on responses
that carry no payload.

**Recommendation:** serve stale-while-revalidate. Store the built body under a
second, longer-lived key (e.g. `status_response_stale`, 48h) and on lock
contention return that with a `X-Wicket-Reporter-Stale: 1` header and 200,
falling back to 503 only when no stale copy exists at all. This also removes the
"first request after 8h is slow for everyone" cliff.

---

## Medium

### M1 — Kill-switch read is strict, which is right; confirm the default is off

`includes/class-reporter-rest.php:86` — `if ('1' !== wicket_get_option('wicket_reporter_enabled'))`.

**Verified:** `wicket_get_option($key, $fallback = null)` in
`wicket-wp-base-plugin/includes/helpers/helper-init.php:34` returns
`$options[$key] ?? $fallback`, i.e. `null` when unset. `'1' !== null` is true, so
an unset option means **disabled**. This is correct and fail-closed — worth
recording as verified rather than assumed, because the settings field declares
`'default' => '0'` and WPSettings defaults are a render-time concern, not a
read-time one. The strict `!==` against the string `'1'` is the right call: a
checkbox stored as integer `1` would fail this check, so if WPSettings ever
changes its storage type the endpoint fails *closed*, not open. Good.

No change needed. Flagged so a future refactor to `(bool)` or `==` is recognised
as a security regression.

### M2 — The raw API key is written to the options table for 60 seconds

`includes/class-reporter-settings.php:227` +
`wicket-wp-reporter.php:73-87`

`set_transient('wicket_reporter_new_key_' . $user_id, $new_key, 60)` puts the
**plaintext** 48-char key into `wp_options` as `_transient_wicket_reporter_new_key_N`.
The design is otherwise excellent (hash-only storage, shown once, deleted on
read), and the exposure window is short and non-autoloaded (verified: core sets
`$autoload = false` when `$expiration` is truthy). But for 60 seconds the key
exists in plaintext in the database, and therefore in any DB backup, replica, or
query log taken in that window — which defeats part of the point of storing only
a hash.

This is a deliberate, reasonable tradeoff for a usable "show once" UX, not a
flaw. Two ways to shrink it:

- Render the key directly in the response to the reset POST rather than
  redirecting, removing the need to persist it at all (costs the
  redirect-after-POST pattern).
- Keep the transient but drop the TTL to ~15s and scope the notice to the exact
  redirect, since the flash is consumed on the very next page load anyway.

Also note the reset is a **GET** link (`maybe_handle_reset`, line 213). It *is*
nonce-protected (`check_admin_referer`) and capability-checked, so it is not
CSRF-vulnerable. But a state-changing GET means the nonce URL can land in browser
history, an `Referer` header, or a copy-pasted URL, and the comment concedes the
POST path "has been unreliable... for reasons not yet root-caused." That
unexplained unreliability is worth root-causing rather than routing around
permanently.

### M3 — `check_permission()` calls into `Reporter_Settings` after guarding only for `wicket_get_option`

`includes/class-reporter-rest.php:78-84` then `:106`

The 503 guard checks `function_exists('wicket_get_option')` — a base-plugin
function. It then calls `Reporter_Settings::hash_token()`, which is *this*
plugin's own class and is always loaded, so this is safe in practice. The
mismatch is that the guard's stated purpose ("without this guard a REST hit
after base-plugin removal would call an undefined function and fatal") does not
cover `Reporter_Log::warning()` on line 107 — which calls `Wicket()->log()`,
and `Wicket()` is a **base-plugin** function.

**Failure scenario:** base plugin is deactivated or removed. A REST request
arrives with an invalid/missing token. Line 78's guard passes only if
`wicket_get_option` is somehow still defined but `Wicket()` is not — narrow. But
the reverse ordering matters more: `Reporter_Log` is used on the rejection path
of an endpoint reachable by an unauthenticated caller, and every one of its five
methods calls `Wicket()->log()` with no `function_exists('Wicket')` guard
(`includes/class-reporter-log.php:19-47`). A partial base-plugin failure turns
an intended 401 into a fatal stack trace on a public endpoint.

**Recommendation:** guard `function_exists('Wicket')` inside `Reporter_Log`
itself (early-return if absent), so no logging call can ever fatal. That is one
guard covering all five methods and every call site.

### M4 — Unbounded `posts_per_page => -1` on two queries

`class-memberships-adapter.php:276-281` and `:404-409`

Both `get_posts()` calls fetch **all** config and tier posts with
`'post_status' => 'any'` and no limit, then `_prime_post_caches()` the full set.
The comment justifies this as "bounded by tier count, typically a handful per
site" — true for today's fleet, and the `_prime_post_caches()` N+1 fix is exactly
right. But this contradicts the plugin's own non-negotiable rule, stated twice in
`AGENTS.md` and the Atlas package doc: *"every collector/adapter reports cheap
counts only... nothing that scans/aggregates many rows."*

A site that has generated tiers programmatically (an importer bug, a migration,
one tier per organisation) turns this into an unbounded fetch plus a full meta
prime inside a request that already holds a 30-second build lock. `'post_status'
=> 'any'` also pulls `trash` — `any` excludes only statuses flagged
`exclude_from_search`, and trashed posts are included by `get_posts()` when
`post_status` is explicitly `any`. So trashed tiers contribute to the
tier→config map and their UUIDs are fed into the `count_memberships()` `IN`
clause.

**Recommendation:** cap both queries (`'posts_per_page' => 500`) and emit a
`collectorErrors[]` entry when the cap is hit, so truncation is visible rather
than silent — the monitor's own audit calls this out as the "no silent caps"
principle. Exclude `trash` explicitly.

### M5 — Environment override is read back without validation

`includes/class-reporter-rest.php:397-398`

```php
$override = wicket_get_option('wicket_reporter_environment_override', '');
$environment = '' !== $override ? $override : wp_get_environment_type();
```

The select field constrains input to five values at render time, but the read
path accepts whatever is in the shared `wicket_settings` array. That option is
written by base-plugin's WPSettings for the whole Wicket settings page and by
`wicket-wp-portus` (config import/export across environments) — so the value can
arrive from an import, not just this dropdown. A non-string (array from a
malformed import) flows straight into the JSON response as `site.environment`.

This matters more than it looks: the fleet monitor and the
`wicket-cloudways-ssh-debug` / staging-only guardrails key off
`environment: production`. A site whose reported environment is wrong or
unparseable is a site whose safety rails misfire.

**Recommendation:** validate at read time against the same five-value allowlist,
falling back to `wp_get_environment_type()` on anything else, and cast to string.

### M6 — Any git-sourced composer package is classified as Wicket-authored

`includes/class-reporter-composer.php:174-183`

```php
if (str_starts_with($name, 'wicket/') || str_starts_with($name, 'industrialdev/')) return true;
return 'git' === ($package['source']['type'] ?? '');
```

The second clause is far broader than the docblock ("Wicket-authored private VCS
packages"). *Every* package Composer resolved from a git source — including any
third-party plugin installed from a fork, a GitHub tag, or a `dev-` branch —
returns true. Consequences, both in the shipped schema:

1. `resolve_update_source()` returns `'git'`, so
2. `package_kind_from_update_source()` returns `'composer-package'`
   (`class-reporter-plugins.php:164-167`), which the docblock explicitly defines
   as "not real installable WP plugins/themes."

So a third-party WordPress plugin installed from a git fork is reported to the
monitor as a non-installable composer package. Given the monitor's entire purpose
is "what is out of date across the fleet," and given its own audit found four
ways it wrongly reports things as up to date, mislabelling a real plugin as
un-updatable lands in exactly the same reassuring-direction failure class.

**Recommendation:** narrow the git test to the vendor allowlist (`wicket/`,
`industrialdev/`) plus a host check on `source.url` for the org's own GitHub
org, and report a distinct `updateSource: 'git-thirdparty'` for the rest rather
than folding it into the Wicket bucket. Note this is a **schema-affecting
change** — per `AGENTS.md` and the Atlas package doc, read
`plans/archive/feature-fleet-health-monitor.md` first and coordinate with
`wicket-fleet-monitor`, since `packageKind` is a consumed field.

### M7 — Legacy-hash rotation is invisible to the REST caller

`includes/class-reporter-settings.php:298-325`

The rotation logic is sound and the one-time admin notice is a thoughtful touch.
The gap is timing: the old key stopped authenticating the moment v1.1.0 shipped,
but the hash is only rotated **on the next settings-tab render** — a human
visiting wp-admin. Until someone does, the site returns 401 to the fleet monitor
with `wicket_reporter_unauthorized`, indistinguishable from a wrong key, a
revoked key, or an attacker probing. On a client site nobody logs into, that is
an indefinitely silent monitoring outage.

Also, `set_transient('wicket_reporter_legacy_rotated_' . get_current_user_id(), ...)`
is scoped to whichever admin happened to trigger the render. If that render came
from a cron/CLI context or a user without `manage_options`, the notice is written
to a user ID that will never see it (`show_legacy_rotation_notice()` requires
`manage_options`), and the prompt is lost.

**Recommendation:** distinguish the error code for a legacy-hash state
(`wicket_reporter_key_rotation_required`, still 401) so the monitor can surface
"needs re-registration" instead of "auth failed." Rotate on `init` or on plugin
upgrade (`upgrader_process_complete` / a version-option check), not on tab
render, and store the notice flag as a site option rather than a per-user
transient.

---

## Low / recommendations

**L1 — `force_nocache_for_status()` matches on a raw `REQUEST_URI` substring.**
`class-reporter-rest.php:48-60`. Verified the filter exists and receives one
bool (`class-wp-rest-server.php:487`), so the signature is right. But
`strpos($uri, '/wicket-reporter/v1/')` returns true for *any* URL containing
that substring — e.g. `/some-page?redirect=/wicket-reporter/v1/status`. The
consequence is only "sends nocache headers when it needn't," so this is
harmless, but the cleaner source is the REST request object itself: hook
`rest_pre_dispatch` or compare `$request->get_route()` inside the callback.

**L2 — Escaped strings are re-echoed unescaped through `printf`/`echo`.**
`class-reporter-settings.php:188-196` echoes `$label`, `$masked_value`, and
`$description` — all pre-escaped at assignment (lines 161-166), so output is
correct. But the pattern (escape early, `echo` raw later) is the one that breaks
silently when someone adds a fourth variable and forgets. Prefer escaping at the
point of output.

**L3 — `render_api_key_field()` emits a bare `<tr>` outside a declared table.**
Same file, line 187. It works because WPSettings renders fields inside a
`<table class="form-table">`, but it hard-couples this custom field to that
library's internal markup — the `$impl` parameter is accepted and ignored, and
the `section` query-arg comment already documents one such coupling. Fragile
across base-plugin upgrades; worth a note in the docblock that this breaks if
WPSettings stops using `form-table`.

**L4 — Inline `style="..."` attributes.** Lines 190-191. Minor; a small
enqueued admin stylesheet or existing WP admin classes would be tidier and
CSP-friendlier.

**L5 — `on_uninstall()` deletes transients with two unindexed `LIKE` queries.**
`class-reporter-settings.php:376-388`. Correctly uses `$wpdb->prepare()` +
`esc_like()` — no injection. But two full `wp_options` scans, and it misses
transients entirely when an external object cache is active (verified: core's
`set_transient()` branches to `wp_cache_set()` and never writes the option rows).
On a Redis-backed site the transients survive uninstall. Also
`update_option('wicket_settings', $wicket_settings)` on line 374 will write an
empty array if `get_option` returned a non-array — guard with `is_array()`.

**L6 — `count_memberships()` builds SQL by string concatenation.** 
`class-memberships-adapter.php:155-188`. The interpolated parts are
`{$wpdb->posts}`, `{$wpdb->postmeta}`, and placeholder strings generated by
`array_fill()` — all developer-controlled, all values passed through
`$wpdb->prepare()`. **Not injectable.** Noted only because the pattern
(`implode(',', array_fill(0, count($x), '%s'))`) will pass a `prepare()` call
zero placeholders if `$tier_uuids` is ever non-empty-but-all-filtered; the
`[] !== $tier_uuids` guard at line 174 and the call-site guard at line 303 both
cover it today. The correlated-`EXISTS` design is the right call and the comment
explaining why it beats `found_posts` is excellent.

**L7 — `monitor.capabilities` conflates fixed and dynamic capabilities.**
`class-reporter-rest.php:297` merges four hardcoded strings with
`array_keys($integrations)`. If the integrations collector *throws*,
`$integrations` is `[]` and the response advertises only the four base
capabilities — a consumer cannot distinguish "this site has no WooCommerce" from
"the integrations collector failed." `collectorErrors[]` carries the truth, but
`capabilities` reads as authoritative. Consider omitting `capabilities` or
marking it degraded when `collectorErrors[]` contains an `integrations*` entry.

**L8 — `$user_counts` memo is never reset and `Reporter_Timer` is static state.**
`class-reporter-rest.php:126` and `class-reporter-timer.php:16-18`. Both are
per-request statics, correct under PHP-FPM. Under a persistent worker
(RoadRunner, Swoole, or a long-lived WP-CLI process serving multiple requests)
`$user_counts` would go stale and `$timings` would accumulate across the
`start_request()` reset boundary. `finish_request()` does reset `$timings` but
`$request_start` is never re-zeroed, so a second `finish_request()` without a
`start_request()` reports a nonsense total. Not a current bug; worth a note since
`Reporter_Timer::time()` is also reachable from `run_collector()` independently.

**L9 — No test coverage.** Per the stack rule, tests belong in `./qa`
(wicket-warden), not here. There is no reporter suite there. The highest-value
targets are `Reporter_Composer` (pure functions — `resolve_update_source()`,
`package_directory_name()`, `extract_repo_slug()`, `is_wicket_git_package()`,
all trivially unit-testable with fixture lock files) and the
`check_permission()` matrix (disabled / no token / bad token / good token /
throttled). M6, M4, and H2 above are all defects that a fixture-based test on
`Reporter_Composer` would have caught.

**L10 — `docs/api-schema.md` does not document the operational contract.**
Grepped: no mention of the 8h cache TTL, the 429 rate limit, the 503 lock
response, or `Retry-After`. A consumer integrating against this endpoint needs
all four. Add a "Responses and caching" section covering 200/401/403/429/503 and
the cache headers.

---

## What is notably well done

Worth recording so a future refactor does not undo it:

- **Auth**: header-only, `hash_equals()`, no query-string fallback, and the
  reasoning for SHA-256-over-bcrypt on a 285-bit CSPRNG token is correct — the
  CPU-amplification-DoS argument is the right one for an unauthenticated
  endpoint, and it is documented at the call site.
- **Ordering in `check_permission()`**: availability → disabled → auth → rate
  limit, with the comment explaining why each precedes the next. Disabled sites
  reveal nothing about key validity.
- **`rest_send_nocache_headers` scoping**: recognising that bearer auth means
  `is_user_logged_in()` is false and a full site inventory would ship with no
  `Cache-Control` is a subtle catch, and scoping the fix to one route rather
  than filtering globally is the disciplined version.
- **Endpoint resilience**: `run_collector()` wrapping every section in both
  timing and `try`/`catch`, with failures surfaced in `collectorErrors[]` rather
  than swallowed.
- **`latestVersion`/`updateAvailable` omitted rather than `null`** — a genuinely
  good schema decision, correctly reasoned about naive falsy checks in client
  code.
- **`_prime_post_caches()`** on both `fields => 'ids'` queries, with the N+1
  explained.
- **The `count_users()` memo** shared between the `wordpress{}` collector and
  the Woo adapter, with an accurate correction of an earlier comment that had
  called it cheap.

## Suggested order of work

1. **H1** — fixed-window rate limiter. Smallest change, removes a real
   availability failure.
2. **M3** — `function_exists('Wicket')` guard inside `Reporter_Log`. One-line
   fix, removes a fatal on a public endpoint's rejection path.
3. **H3** — stale-while-revalidate on lock contention. Removes the
   post-expiry 503 cliff and stops it burning rate-limit budget.
4. **H2 / M4 / M5** — normalise `wp_count_posts()` handling, bound the two
   unbounded queries with visible truncation, validate the environment override.
5. **M6** — narrow `is_wicket_git_package()`. **Schema-affecting** — read
   `plans/archive/feature-fleet-health-monitor.md` and coordinate with
   `wicket-fleet-monitor` before touching `packageKind`/`updateSource`.
6. **M7** — rotation on upgrade rather than tab render, plus a distinct 401
   error code.
7. **M2, L1–L10** — as capacity allows.

Per Atlas rules, any of these that changes a stable surface (the endpoint shape,
`updateSource`/`packageKind` values, or the settings fields) needs the package
doc or a plan updated in the same change, and the version left alone for the
release bot.

---

# Second audit pass — 2026-08-19

An independent second pass, deliberately aimed at surfaces the first pass
under-covered: the `.ci/` release tooling, the GitHub Actions workflow, REST
route registration and discoverability, theme collection, and payload scaling.
Findings are numbered `N<n>` to keep them distinct from the first pass.

Two of the first pass's open questions are also resolved here with evidence.

## Resolved open questions

### Redis / object cache: not active locally; unverifiable for the fleet

Checked and found no `object-cache.php` drop-in anywhere under `src/web/`, no
`WP_REDIS_*` or `WP_CACHE_KEY_SALT` constants in config, and no
`redis-cache` / `object-cache-pro` / `litespeed` plugin installed. Breeze *is*
installed and supports Redis, and `wp-config.php` carries a stray
`define('WP_CACHE', true)` left by W3 Total Cache, but neither of those makes an
object cache active on its own.

The fleet monitor cannot answer for client sites either: `list_sites` returns
only `localhost`, marked unreachable. So this stays a **deployment-time check
per site**, not a blocker. The safe design conclusion holds regardless:

- The bucketed rate-limit key (M1/T1) works correctly under both storage
  backends, so it is not blocked by this.
- The uninstall `LIKE` sweep (`L5`) is genuinely wrong on any site that *does*
  run an object cache — core `set_transient()` routes to `wp_cache_set()` and
  never writes the `_transient_*` option rows the sweep looks for. Fix it with
  explicit `delete_transient()` calls for known keys, which works under both.

### `total_memberships` must exclude `draft`

Confirmed with the product owner. `count_posts()` must exclude `draft`
alongside `trash` and `auto-draft`. This also settles the shape of the T6 fix:
it is an explicit status allowlist, not a denylist with growing exceptions.

## New findings

### N1 — High — The version bumper can corrupt unrelated `Version:` lines

`.ci/version-bump.php:158-167`

```php
$docblockPattern = '/(^\s*\*\s*Version:\s*)' . $versionPatternPart . '/m';
$tempContent = preg_replace($docblockPattern, '${1}' . $newVersion, $content, -1, $count1);
```

The replace is global (`-1`) and multiline (`/m`), and matches **any** docblock
line whose label is `Version:`. It is not anchored to the plugin header block and
does not require the matched value to equal the current version.

Today `wicket-wp-reporter.php` has exactly one such line, so it is correct. It
breaks the moment any root `.php` file gains a second `Version:` docblock line —
an `@since`-style annotation, a vendored header, a second doc comment. Both get
rewritten to the new plugin version.

The `default:` branch is worse: for any non-`json`/`php` file it replaces **every
occurrence of the current version string anywhere in the file**. That branch is
unreachable with today's `$filesToUpdate` (`composer.json` plus the main PHP
file), but it is a loaded gun for whoever adds `style.css` or a `readme.txt` to
that list — a `Requires at least: 1.1.0` line would silently be rewritten.

**Recommendation:** anchor the PHP replace to the first `Plugin Name:` docblock,
require the matched value to equal `$this->currentVersion`, and cap the replace
at one (`$limit = 1`). Delete the `default:` branch or make it require an exact,
labelled match.

### N2 — Medium — `git add ./*.php` in the release workflow is a blind glob

`.github/workflows/release.yml:110`

```
git add composer.json CHANGELOG.md ./*.php
```

The bump step only ever edits the one auto-detected main plugin file, but the
commit stages *every* root `.php` file. Anything a merged PR left in the repo
root gets committed by the release bot under a `chore(release):` message, with
the bot's identity, straight to `main` — bypassing the branch protection that
made the App a bypass-list member in the first place.

Low likelihood, but the blast radius is "arbitrary file committed to `main` by an
automated identity with ruleset bypass", and the fix is trivial.

**Recommendation:** have `version-bump.php` print the file it modified and stage
exactly that path, or hardcode `wicket-wp-reporter.php`.

### N3 — Medium — The release workflow's `#norelease` marker matches too loosely

`.github/workflows/release.yml:75-81`

```
if echo "$msg" | grep -qiE '#norelease'; then
```

Correctly routed through `env: COMMIT_MSG` rather than direct `${{ }}`
interpolation, so **there is no command-injection exposure** — the obvious thing
to check, and it is done right.

The problem is matching. `grep -qiE '#norelease'` searches the **entire** commit
message, including the body. Squash-merge appends every commit subject from the
branch to the body. So a developer whose branch contains a commit reading
`chore: drop #norelease from the docs` silently suppresses a real release. The
same applies to `#major`, which is checked before `#minor` and would win from a
body mention.

The workflow comment says "a marker anywhere in the merge commit message" — so
this is deliberate. It is still the wrong default for a squash-merge repo, where
bodies accumulate unrelated subjects.

**Recommendation:** match markers against the **first line** only
(`head -1`), which is the PR title under squash-merge, and is what both
`AGENTS.md` and the stack `CLAUDE.md` actually document ("Put a marker in the
**PR title**").

### N4 — Medium — Active theme detection misses filtered stylesheets

`includes/class-reporter-plugins.php:68`

```php
$active_stylesheet = get_option('stylesheet');
```

Core's accessor is `get_stylesheet()`, which is
`apply_filters('stylesheet', get_option('stylesheet'))` (verified in
`src/web/wp/wp-includes/theme.php`). Reading the raw option bypasses that filter.

This stack runs **WPML** (`sitepress-multilingual-cms`), and per-language theme
switching is a real WPML feature; theme-switcher and A/B plugins filter the same
hook. On any site where the filter is active, `themes[].status` reports the wrong
theme as active — and `_meta.activeCount` with it.

There is a second, unconditional bug in the same block: only the **stylesheet**
is ever compared. For a child theme, the parent (`template`) is genuinely in use
but is always reported `inactive`. The row already carries `template`, so a
consumer could derive it, but the `status` field is simply wrong for the parent.

**Recommendation:** use `get_stylesheet()`, and mark the parent of an active
child theme as active — or add an explicit `isParentOfActive` flag rather than
overloading `status`. The latter is a schema addition, so it needs the same
monitor coordination as the first pass's `M6`.

### N5 — Low — The status route is publicly listed in the REST index

`includes/class-reporter-rest.php:29-33`

`register_rest_route()` is called without `'show_in_index' => false`, and core
defaults that to `true` (verified in `class-wp-rest-server.php:981`). So
`GET /wp-json/` — unauthenticated — advertises `/wicket-reporter/v1/status` on
every fleet site.

This leaks no data. It does tell an unauthenticated scanner that this plugin is
installed and where its endpoint is, which is free reconnaissance and
inconsistent with the plugin's otherwise careful posture (header-only auth, no
key in URLs, nocache headers, 403-without-confirming-key-validity).

**Recommendation:** pass `'show_in_index' => false`. The monitor calls the route
by its known path and never reads the index, so nothing breaks.

### N6 — Low — The nocache filter is registered on every REST request

`includes/class-reporter-rest.php:41`

`register_routes()` runs on `rest_api_init`, which fires for **every** REST
request, and unconditionally adds the `rest_send_nocache_headers` filter. The
filter body then re-checks the URI to decide whether to act.

Harmless — one closure and one `strpos` per REST request. But combined with `L1`
(the substring match on raw `REQUEST_URI`) it means the cheapest correct
implementation is being skipped: decide once, at registration time, or scope it
inside the route callback where `$request->get_route()` is authoritative. Fold
into `L1`.

### N7 — Low — `str_replace('wc-', '')` is unanchored

`class-woocommerce-adapter.php:71` and `class-subscriptions-adapter.php:61`

`str_replace('wc-', '', $status)` strips the substring anywhere, not just the
prefix. WooCommerce's own statuses are all `wc-`-prefixed once, so this is
correct today. A custom status registered as `wc-awaiting-wc-review` would be
mangled into `awaitingreview`, silently mis-keying that metric.

**Recommendation:** anchor it —
`preg_replace('/^wc-/', '', $status)`. One-character-class change, removes the
class of bug entirely.

### N8 — Informational — Payload size is not a problem, and stale caching stays safe

Measured against this workspace's real data rather than estimated: 44 relevant
`composer.lock` packages and 56 installed plugins produce **~24 KB of JSON and
~31 KB serialized** — which is what `set_transient()` actually stores.

That is far inside `longtext`, and the transient is non-autoloaded (core sets
`$autoload = false` whenever an expiry is given). The first pass's M2
stale-while-revalidate proposal doubles the footprint to roughly 62 KB, which is
still comfortably safe. **No change needed** — recorded so the stale-copy design
is not second-guessed on size grounds later.

Also verified in passing: the composer collector's path resolution is correct.
`ABSPATH . '../../composer.lock'` resolves to `src/composer.lock` (44 relevant
packages), not the unrelated lock file at the workspace root. The candidate
ordering works as designed.

## Atlas documentation drift (rule 10 — code is truth)

`atlas/packages/wicket-wp-reporter.md:19` states the endpoint "Refuses
(404/403) when the settings toggle disables it."

The code never returns 404. `check_permission()` returns exactly four statuses:
503 (base plugin missing), 403 (disabled), 401 (bad key), 429 (throttled). The
route is registered unconditionally on `rest_api_init` regardless of the toggle,
so a disabled site returns 403, never 404.

Fix the package doc to say 403. Low impact, but it is precisely the kind of drift
Atlas rule 10 exists to catch, and the endpoint's documented refusal behaviour is
something a monitor-side developer would reasonably code against.

## Checked and found correct (no action)

Recording these so a later pass does not re-litigate them:

- **No command injection in the release workflow.** The untrusted
  `github.event.head_commit.message` is passed via `env:`, not interpolated into
  the `run:` script. This is the correct pattern.
- **`Memberships_Adapter::is_available()` short-circuit ordering is safe.**
  `class_exists()` runs before `post_type_exists(self::membership_post_type())`,
  and the `Helper::get_*_cpt_slug()` methods return hardcoded strings with no
  bootstrap dependency (verified in `wicket-wp-memberships/includes/Helper.php`).
- **`count_memberships()` SQL is not injectable.** Re-confirmed independently:
  every interpolated fragment is developer-controlled, all values go through
  `$wpdb->prepare()`.
- **`Reporter_Plugins::collect_plugins()` requiring `wp-admin/includes/plugin.php`
  on a REST request is safe** — `is_plugin_active()` lives in that same file and
  is available after the require.
- **`on_uninstall()` calls no logger**, so it cannot fatal when base-plugin is
  already gone during uninstall.

## Revised suggested order

Folding the new findings into the first pass's order:

1. **H1, M3** — unchanged; still first.
2. **N1, N2, N3** — the release-tooling fixes. Small, independent of the runtime
   work, and they protect `main` and the version history. Worth doing early
   precisely because they are cheap and touch nothing else.
3. **H3 + monitor 503/stale handling** — unchanged.
4. **H2 / M4 / M5 / N4 / N7** — the correctness cluster. `total_memberships`
   excludes `draft` per the resolved decision above.
5. **M6** — unchanged; still needs monitor coordination.
6. **M7, N5, N6, L1–L10**, plus the Atlas package-doc 403 fix.
