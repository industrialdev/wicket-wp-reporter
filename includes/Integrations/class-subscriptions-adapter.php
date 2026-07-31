<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * WooCommerce Subscriptions integration adapter.
 *
 * A separate installable plugin from WooCommerce core (and from
 * Woocommerce_Adapter) — one adapter per plugin, per this plugin's own
 * convention (see Reporter_Integration_Adapter). Pure counts only;
 * `configuration` is always empty, same as Woocommerce_Adapter.
 */
class Subscriptions_Adapter implements Reporter_Integration_Adapter
{
    public function slug(): string
    {
        return 'subscriptions';
    }

    public function plugin_slug(): string
    {
        return 'woocommerce-subscriptions';
    }

    public function is_available(): bool
    {
        return class_exists(\WC_Subscriptions::class);
    }

    /**
     * @return array{
     *     metrics: array{byStatus: array<string, int>},
     *     configuration: array{}
     * }
     */
    public function collect(): array
    {
        return [
            'metrics' => [
                'byStatus' => self::count_by_status(),
            ],
            'configuration' => [],
        ];
    }

    /**
     * Same shape/approach as Woocommerce_Adapter's order counting:
     * wc_orders_count() accepts an optional order `$type` —
     * 'shop_subscription' scopes the same HPOS-aware, WC-cached counting
     * API to subscriptions instead of regular orders.
     *
     * @return array<string, int>
     */
    private static function count_by_status(): array
    {
        $counts = [];

        foreach (array_keys(wcs_get_subscription_statuses()) as $status) {
            $unprefixed = str_replace('wc-', '', $status);
            $counts[$unprefixed] = wc_orders_count($unprefixed, 'shop_subscription');
        }

        return $counts;
    }
}
