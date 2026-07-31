<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Memberships integration adapter.
 *
 * wicket-wp-memberships has no counting/reporting helper of its own, so
 * this adapter queries its post types and postmeta directly — cheap counts
 * only, no per-row iteration beyond what building the tier->config lookup
 * (below) requires, which is bounded by tier count, not membership count.
 *
 * Post type slugs are read from wicket-wp-memberships' own
 * Wicket_Memberships\Helper::get_*_cpt_slug() rather than hardcoded here,
 * so a slug rename on their side doesn't silently desync this adapter.
 */
class Memberships_Adapter implements Reporter_Integration_Adapter
{
    /**
     * "In force" statuses — matches wicket-wp-memberships' own internal
     * definition of a membership that hasn't lost access yet (see its
     * Admin_Controller), not just the literal 'active' status.
     */
    private const ACTIVE_STATUSES = ['active', 'grace_period', 'delayed'];

    public function slug(): string
    {
        return 'memberships';
    }

    public function plugin_slug(): string
    {
        return 'wicket-wp-memberships';
    }

    public function is_available(): bool
    {
        return class_exists(\Wicket_Memberships\Helper::class) && post_type_exists(self::membership_post_type());
    }

    private static function membership_post_type(): string
    {
        return \Wicket_Memberships\Helper::get_membership_cpt_slug();
    }

    private static function tier_post_type(): string
    {
        return \Wicket_Memberships\Helper::get_membership_tier_cpt_slug();
    }

    private static function config_post_type(): string
    {
        return \Wicket_Memberships\Helper::get_membership_config_cpt_slug();
    }

    /**
     * @return array{
     *     metrics: array{
     *         total_memberships: int,
     *         total_active_memberships: int,
     *         total_tiers: int,
     *         total_configs: int,
     *         active_by_config: array<int, array{config: string, active: int}>
     *     },
     *     configuration: array{
     *         configs: array<int, array{
     *             config: string,
     *             name: string,
     *             cycleType: string|null,
     *             calendarItems: array<int, array{seasonName: string|null, active: bool, startDate: string|null, endDate: string|null}>,
     *             anniversaryData: array{periodCount: int|null, periodType: string|null}|null,
     *             renewalWindowDays: int|null,
     *             lateFeeWindowDays: int|null,
     *             renewalTypes: array<int, string>,
     *             tierTypes: array<int, string>,
     *             seatTypes: array<int, string>,
     *             approvalRequired: bool,
     *             products: array<int, array{productId: int, variationId: int|null, maxSeats: int|null}>
     *         }>
     *     }
     * }
     */
    public function collect(): array
    {
        $total_memberships = self::count_posts(self::membership_post_type());
        $total_active_memberships = self::count_active_memberships();
        $total_tiers = self::count_posts(self::tier_post_type());
        $total_configs = self::count_posts(self::config_post_type());

        $configs = self::build_config_data();

        return [
            'metrics' => [
                'total_memberships'        => $total_memberships,
                'total_active_memberships' => $total_active_memberships,
                'total_tiers'              => $total_tiers,
                'total_configs'            => $total_configs,
                // Pure counts only — config/tier setup detail lives in
                // configuration.configs[] below, keyed the same way
                // (config slug) so a consumer can join the two if needed.
                'active_by_config'         => array_map(
                    static fn (array $config) => ['config' => $config['config'], 'active' => $config['active']],
                    $configs
                ),
            ],
            'configuration' => [
                'configs' => array_map(
                    static fn (array $config) => self::without_key($config, 'active'),
                    $configs
                ),
            ],
        ];
    }

    /** Returns $array without $key — used to split the active count out of the combined per-config data. */
    private static function without_key(array $array, string $key): array
    {
        unset($array[$key]);

        return $array;
    }

    private static function count_posts(string $post_type): int
    {
        $counts = wp_count_posts($post_type);

        return array_sum((array) $counts);
    }

    /**
     * A membership's status lives in the `membership_status` post meta
     * (not a taxonomy), so wp_count_posts() alone can't filter it — one
     * meta_query-filtered WP_Query, ids-only, reading found_posts rather
     * than materializing post objects.
     */
    private static function count_active_memberships(): int
    {
        $query = new WP_Query([
            'post_type'      => self::membership_post_type(),
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => false,
            'meta_query'     => [
                [
                    'key'     => 'membership_status',
                    'value'   => self::ACTIVE_STATUSES,
                    'compare' => 'IN',
                ],
            ],
        ]);

        return $query->found_posts;
    }

    /**
     * Combined per-config data — collect() splits this into the active
     * count (metrics.active_by_config) and everything else
     * (configuration.configs), keyed the same way so the two can be
     * joined back together if needed. Every config appears here, even
     * one with zero tiers/zero active memberships — total_configs already
     * counts it, so silently omitting it would hide a real signal (a
     * config nobody's assigned to a tier is itself worth seeing, not
     * noise to drop). A membership links to its config through its tier,
     * not directly — membership posts store `membership_tier_uuid`, and a
     * tier's own `tier_data` serialized meta carries `config_id`. So this
     * resolves tier -> config first (cheap: bounded by tier count,
     * typically a handful per site), then runs one ids-only,
     * found_posts-only WP_Query per config that has tiers, filtering
     * memberships whose tier UUID falls in that config's set.
     *
     * @return array<int, array{
     *     config: string,
     *     name: string,
     *     cycleType: string|null,
     *     calendarItems: array<int, array{seasonName: string|null, active: bool, startDate: string|null, endDate: string|null}>,
     *     anniversaryData: array{periodCount: int|null, periodType: string|null}|null,
     *     renewalWindowDays: int|null,
     *     lateFeeWindowDays: int|null,
     *     renewalTypes: array<int, string>,
     *     tierTypes: array<int, string>,
     *     seatTypes: array<int, string>,
     *     approvalRequired: bool,
     *     products: array<int, array{productId: int, variationId: int|null, maxSeats: int|null}>,
     *     active: int
     * }>
     */
    private static function build_config_data(): array
    {
        $tier_info = self::build_tier_info_map();

        $tiers_by_config = [];
        $renewal_types_by_config = [];
        $tier_types_by_config = [];
        $seat_types_by_config = [];
        $approval_required_by_config = [];
        $products_by_config = [];

        foreach ($tier_info as $tier_uuid => $info) {
            $config_id = $info['config_id'];
            $tiers_by_config[$config_id][] = $tier_uuid;

            if (!empty($info['renewal_type'])) {
                $renewal_types_by_config[$config_id][$info['renewal_type']] = true;
            }

            if (!empty($info['type'])) {
                $tier_types_by_config[$config_id][$info['type']] = true;
            }

            if (!empty($info['seat_type'])) {
                $seat_types_by_config[$config_id][$info['seat_type']] = true;
            }

            if ($info['approval_required']) {
                $approval_required_by_config[$config_id] = true;
            }

            foreach ($info['product_data'] as $product) {
                $product_id = $product['product_id'] ?? null;

                if (empty($product_id)) {
                    continue;
                }

                $variation_id = $product['variation_id'] ?? null;
                $key = $product_id . ':' . ($variation_id ?? '');

                $products_by_config[$config_id][$key] = [
                    'productId'   => (int) $product_id,
                    'variationId' => null !== $variation_id ? (int) $variation_id : null,
                    'maxSeats'    => isset($product['max_seats']) ? (int) $product['max_seats'] : null,
                ];
            }
        }

        $config_ids = get_posts([
            'post_type'      => self::config_post_type(),
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        $breakdown = [];

        foreach ($config_ids as $config_post_id) {
            $config_post = get_post($config_post_id);

            if (!$config_post instanceof WP_Post) {
                continue;
            }

            $tier_uuids = $tiers_by_config[$config_post_id] ?? [];

            // No tiers under this config -> no membership can reference it,
            // so there's nothing to query; report 0 active rather than run
            // a WP_Query with an empty IN clause.
            $active_count = 0;

            if ([] !== $tier_uuids) {
                $query = new WP_Query([
                    'post_type'      => self::membership_post_type(),
                    'post_status'    => 'any',
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => false,
                    'meta_query'     => [
                        'relation' => 'AND',
                        [
                            'key'     => 'membership_status',
                            'value'   => self::ACTIVE_STATUSES,
                            'compare' => 'IN',
                        ],
                        [
                            'key'     => 'membership_tier_uuid',
                            'value'   => $tier_uuids,
                            'compare' => 'IN',
                        ],
                    ],
                ]);

                $active_count = $query->found_posts;
            }

            $raw_cycle_data = get_post_meta($config_post_id, 'cycle_data', true);
            $cycle_data = is_array($raw_cycle_data) ? $raw_cycle_data : [];
            $renewal_window_data = get_post_meta($config_post_id, 'renewal_window_data', true);
            $late_fee_window_data = get_post_meta($config_post_id, 'late_fee_window_data', true);

            $breakdown[] = [
                'config'            => $config_post->post_name,
                'name'              => $config_post->post_title,
                // 'calendar' or 'anniversary' — how this config's renewal
                // cycle is dated. Null if cycle_data isn't set yet.
                'cycleType'         => $cycle_data['cycle_type'] ?? null,
                'calendarItems'     => self::format_calendar_items($cycle_data),
                'anniversaryData'   => self::format_anniversary_data($cycle_data),
                'renewalWindowDays' => is_array($renewal_window_data) ? ($renewal_window_data['days_count'] ?? null) : null,
                'lateFeeWindowDays' => is_array($late_fee_window_data) ? ($late_fee_window_data['days_count'] ?? null) : null,
                // The rest of these are tier-level properties, aggregated
                // as sets across this config's tiers — a config's tiers
                // can each set their own value, so most of these are "every
                // distinct value seen," not a single value.
                'renewalTypes'      => array_keys($renewal_types_by_config[$config_post_id] ?? []),
                'tierTypes'         => array_keys($tier_types_by_config[$config_post_id] ?? []),
                'seatTypes'         => array_keys($seat_types_by_config[$config_post_id] ?? []),
                'approvalRequired'  => !empty($approval_required_by_config[$config_post_id]),
                'products'          => array_values($products_by_config[$config_post_id] ?? []),
                'active'            => $active_count,
            ];
        }

        return $breakdown;
    }

    /**
     * `calendar_items[]` only means something when cycleType is 'calendar'
     * — named renewal seasons with their own active flag and date range.
     * Empty for an anniversary-cycle config.
     *
     * @return array<int, array{seasonName: string|null, active: bool, startDate: string|null, endDate: string|null}>
     */
    private static function format_calendar_items(array $cycle_data): array
    {
        $items = $cycle_data['calendar_items'] ?? [];

        if (!is_array($items)) {
            return [];
        }

        return array_map(
            static fn (array $item) => [
                'seasonName' => $item['season_name'] ?? null,
                'active'     => !empty($item['active']),
                'startDate'  => $item['start_date'] ?? null,
                'endDate'    => $item['end_date'] ?? null,
            ],
            $items
        );
    }

    /**
     * `anniversary_data` only means something when cycleType is
     * 'anniversary' — the renewal period length, not a date range like
     * calendar_items. Null for a calendar-cycle config.
     *
     * @return array{periodCount: int|null, periodType: string|null}|null
     */
    private static function format_anniversary_data(array $cycle_data): ?array
    {
        $data = $cycle_data['anniversary_data'] ?? null;

        if (!is_array($data)) {
            return null;
        }

        return [
            'periodCount' => isset($data['period_count']) ? (int) $data['period_count'] : null,
            'periodType'  => $data['period_type'] ?? null,
        ];
    }

    /**
     * Reads every tier post's own `tier_data` meta once, building a
     * tier UUID -> {config_id, renewal_type, type, seat_type,
     * approval_required, product_data} map. All of these properties live
     * inside that same serialized blob (not separate postmeta keys) — see
     * wicket-wp-memberships' own REST field schema for `tier_data` on the
     * tier post type. One query for all tiers (cheap — a handful per site,
     * not per-membership), so the per-config counts above never need to
     * inspect a membership's tier chain themselves.
     *
     * @return array<string, array{
     *     config_id: int,
     *     renewal_type: string|null,
     *     type: string|null,
     *     seat_type: string|null,
     *     approval_required: bool,
     *     product_data: array<int, array<string, mixed>>
     * }>
     */
    private static function build_tier_info_map(): array
    {
        $tier_ids = get_posts([
            'post_type'      => self::tier_post_type(),
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        $map = [];

        foreach ($tier_ids as $tier_id) {
            $tier_data = get_post_meta($tier_id, 'tier_data', true);

            if (!is_array($tier_data)) {
                continue;
            }

            $tier_uuid = $tier_data['mdp_tier_uuid'] ?? null;
            $config_id = $tier_data['config_id'] ?? null;

            if (empty($tier_uuid) || empty($config_id)) {
                continue;
            }

            $map[$tier_uuid] = [
                'config_id'         => (int) $config_id,
                'renewal_type'      => $tier_data['renewal_type'] ?? null,
                'type'              => $tier_data['type'] ?? null,
                'seat_type'         => $tier_data['seat_type'] ?? null,
                'approval_required' => !empty($tier_data['approval_required']),
                'product_data'      => is_array($tier_data['product_data'] ?? null) ? $tier_data['product_data'] : [],
            ];
        }

        return $map;
    }
}
