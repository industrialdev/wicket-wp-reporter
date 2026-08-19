<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * WooCommerce integration adapter.
 *
 * Pure counts only — no configuration.configs-style detail exists for
 * this adapter (unlike memberships' config/tier setup), so
 * `configuration` is always empty. Uses WooCommerce's own counting APIs
 * throughout, never a raw query — both already abstract HPOS vs. legacy
 * posts-table storage internally and are themselves WC-cached.
 */
class Woocommerce_Adapter implements Reporter_Integration_Adapter
{
    public function slug(): string
    {
        return 'woocommerce';
    }

    public function plugin_slug(): string
    {
        return 'woocommerce';
    }

    public function is_available(): bool
    {
        return class_exists(\WooCommerce::class);
    }

    /**
     * @return array{
     *     metrics: array{
     *         orders: array<string, int>,
     *         users: array{total: int, byRole: array<string, int>},
     *         totalProducts: int,
     *         totalCoupons: int
     *     },
     *     configuration: array{}
     * }
     */
    public function collect(): array
    {
        return [
            'metrics' => [
                'orders'        => self::count_orders_by_status(),
                'users'         => self::count_users_by_role(),
                'totalProducts' => self::count_posts_by_status('product', 'publish'),
                'totalCoupons'  => self::count_posts_by_status('shop_coupon', 'publish'),
            ],
            'configuration' => [],
        ];
    }

    /**
     * One wc_orders_count() call per registered order status —
     * wc_get_order_statuses() already tells us every status this site
     * actually uses, so nothing is hardcoded/guessed. wc_orders_count()
     * takes the unprefixed status ('processing', not 'wc-processing') and
     * is itself WC-cached, already HPOS-aware internally.
     *
     * @return array<string, int>
     */
    private static function count_orders_by_status(): array
    {
        $counts = [];

        foreach (array_keys(wc_get_order_statuses()) as $status) {
            // Anchored to the start: str_replace('wc-', '', $status) strips
            // the substring anywhere, so a custom status containing "wc-"
            // mid-string (e.g. "wc-awaiting-wc-review") would be mangled
            // and mis-keyed instead of just having its leading prefix cut.
            $unprefixed = preg_replace('/^wc-/', '', $status);
            $counts[$unprefixed] = wc_orders_count($unprefixed);
        }

        return $counts;
    }

    /**
     * Same shape as WooCommerce's own WC_Tracker::get_user_counts() — one
     * cheap count_users() call, total plus a per-role breakdown, no
     * attempt at a single derived "customers" figure. A role-count
     * subtraction (total minus admin/shop_manager) would double- or
     * under-count anyone with more than one role, since count_users()'
     * avail_roles counts a multi-role user once per role, not once per
     * person — WC_Tracker itself doesn't compute that figure either, for
     * the same reason.
     *
     * @return array{total: int, byRole: array<string, int>}
     */
    private static function count_users_by_role(): array
    {
        // P1: count_users() is CPU-intensive (one COUNT column per role over
        // every wp_capabilities usermeta row). It is already computed once
        // per request by Reporter_Rest::user_counts() for the wordpress{}
        // section; reuse that memo rather than running it a second time.
        $counts = Reporter_Rest::user_counts();

        return [
            'total'  => $counts['total_users'],
            'byRole' => $counts['avail_roles'],
        ];
    }

    /** wp_count_posts() is core's own cheap-count API — one query, no per-row iteration. */
    private static function count_posts_by_status(string $post_type, string $status): int
    {
        return Reporter_Post_Status_Counts::for_post_type($post_type)[$status] ?? 0;
    }
}
