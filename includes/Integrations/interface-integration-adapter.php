<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * One implementation per known integration (memberships, WooCommerce, ...).
 *
 * **One adapter per plugin, always — never one adapter covering more than
 * one installable plugin.** WooCommerce core and WooCommerce Subscriptions
 * are separate plugins, so they're separate adapters even though
 * Subscriptions depends on WooCommerce — folding Subscriptions' data into
 * Woocommerce_Adapter would make is_available() ambiguous (which plugin is
 * it actually checking?) and would force a `null`-vs-absent hack to report
 * "Subscriptions isn't installed" instead of integrations{} simply
 * omitting the key, per the key-absence convention every other adapter
 * already follows.
 *
 * Sibling plugins stay unaware of this plugin — no filter hooks on their
 * side. Coupling to a target plugin's internal data shape lives entirely
 * in its adapter.
 */
interface Reporter_Integration_Adapter
{
    /** integrations{} key this adapter reports under (e.g. 'memberships'). */
    public function slug(): string;

    /**
     * The target plugin's own slug, matching plugins.items[].slug in this
     * plugin's response — lets a consumer join integrations{} back to the
     * plugin/theme list without guessing. Not the same as slug() above:
     * that's this adapter's own integrations{} key (e.g. 'memberships'),
     * this is the WordPress plugin directory slug it's reporting about
     * (e.g. 'wicket-wp-memberships').
     */
    public function plugin_slug(): string;

    /** Whether the target plugin is active on this site. */
    public function is_available(): bool;

    /**
     * Returns {metrics, configuration} — the standing convention for every
     * adapter, kept as two separate top-level keys rather than one blended
     * bag. `metrics` is pure counts/usage numbers (cheap-count-only, see
     * the plan's Lightweight-only principle) — if a field answers "how
     * many," it goes here. `configuration` is what's set up — settings,
     * linked posts/products, type/category values — if a field answers
     * "what kind" or "set to what," it goes here. See docs/api-schema.md's
     * "Convention: every adapter splits into metrics + configuration" for
     * the worked example. Every implementation must declare its own
     * concrete return shape via an @return array{...} PHPDoc annotation on
     * its collect() — this plugin has no runtime schema validation, so the
     * doc block is the only contract a consumer (or another dev) has to
     * go on.
     *
     * @return array{metrics: array<string, mixed>, configuration: array<string, mixed>}
     */
    public function collect(): array;
}
