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

    /**
     * Ceiling on the tier and config enumerations in build_tier_info_map()
     * and build_config_data(). Both previously ran posts_per_page => -1,
     * which contradicts this plugin's own cheap-counts-only rule: a site
     * with an unbounded number of tiers (an importer bug, a migration, one
     * tier per organisation) would run an unbounded fetch plus a full meta
     * prime inside a request that already holds a 30-second build lock. 500
     * is far above any real site's tier/config count today; hitting it is
     * itself a signal something is wrong, which is why it is surfaced in
     * metrics rather than silently capped.
     */
    private const MAX_TIERS_AND_CONFIGS = 500;

    /** @var bool Set when a bounded query below hit MAX_TIERS_AND_CONFIGS. Read by collect() to surface it. */
    private static bool $truncated = false;

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
     *         active_by_config: array<int, array{config: string, active: int}>,
     *         tiersOrConfigsTruncated: bool
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
        self::$truncated = false;

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
                // True only when the tier or config enumeration hit
                // MAX_TIERS_AND_CONFIGS — everything above is then a partial
                // view, not the whole site. Absent no-op case stays false;
                // this is not a silent cap.
                'tiersOrConfigsTruncated'  => self::$truncated,
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

    /**
     * Every status a real membership, tier, or config post can be in while
     * it still counts toward a total. An explicit allowlist, not a denylist
     * that grows an exception per unwanted status as they turn up — a
     * denylist of ['trash', 'auto-draft'] previously let 'draft' through,
     * so a membership that was never published counted toward
     * total_memberships alongside genuinely active ones.
     */
    private const COUNTABLE_STATUSES = ['publish', 'private', 'pending', 'future'];

    private static function count_posts(string $post_type): int
    {
        $counts = Reporter_Post_Status_Counts::for_post_type($post_type);
        $total = 0;

        foreach (self::COUNTABLE_STATUSES as $status) {
            $total += $counts[$status] ?? 0;
        }

        return $total;
    }

    /**
     * A true SELECT COUNT(*) + correlated EXISTS, not WP_Query's
     * found_posts — the latter forces SQL_CALC_FOUND_ROWS and materializes
     * the whole result set for an unindexed postmeta scan.
     */
    private static function count_memberships(array $tier_uuids = []): int
    {
        global $wpdb;

        $statuses = self::ACTIVE_STATUSES;
        $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} p
            WHERE p.post_type = %s
              AND p.post_status != 'trash'
              AND EXISTS (
                  SELECT 1 FROM {$wpdb->postmeta} s
                  WHERE s.post_id = p.ID
                    AND s.meta_key = %s
                    AND s.meta_value IN ({$status_placeholders})
              )";

        $params = array_merge([self::membership_post_type(), 'membership_status'], $statuses);

        if ([] !== $tier_uuids) {
            $tier_placeholders = implode(',', array_fill(0, count($tier_uuids), '%s'));

            $sql .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} t
                WHERE t.post_id = p.ID
                  AND t.meta_key = %s
                  AND t.meta_value IN ({$tier_placeholders})
            )";

            $params = array_merge($params, ['membership_tier_uuid'], array_values($tier_uuids));
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private static function count_active_memberships(): int
    {
        return self::count_memberships();
    }

    /**
     * Combined per-config data — collect() splits this into the active
     * count and everything else, keyed the same way. Every config appears
     * even at zero tiers/active memberships, since omitting one would hide
     * a real signal. A membership links to its config only through its
     * tier (tier_data.config_id), never directly, so this resolves
     * tier -> config first, then counts per config.
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
            'post_status'    => array_diff(get_post_stati(), get_post_stati(['exclude_from_search' => true])),
            'fields'         => 'ids',
            'posts_per_page' => self::MAX_TIERS_AND_CONFIGS,
        ]);

        if (count($config_ids) >= self::MAX_TIERS_AND_CONFIGS) {
            self::$truncated = true;
        }

        // Primes the cache up front — the loop below calls get_post() plus
        // three get_post_meta() per config, which would otherwise be N+1.
        if ([] !== $config_ids) {
            _prime_post_caches($config_ids, false, true);
        }

        $breakdown = [];

        foreach ($config_ids as $config_post_id) {
            $config_post = get_post($config_post_id);

            if (!$config_post instanceof WP_Post) {
                continue;
            }

            $tier_uuids = $tiers_by_config[$config_post_id] ?? [];

            // No tiers under this config -> no membership can reference it,
            // so there's nothing to query; report 0 active rather than run
            // a COUNT with an empty IN clause.
            $active_count = [] !== $tier_uuids ? self::count_memberships($tier_uuids) : 0;

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
            // Mirrors what post_status => 'any' actually resolves to:
            // every status except those flagged exclude_from_search (which
            // already includes 'trash' and 'auto-draft'). A plain
            // array_diff(get_post_stati(), ['trash']) would wrongly
            // reintroduce auto-draft and other plugins' excluded statuses
            // (e.g. WooCommerce's wc-* order statuses).
            'post_status'    => array_diff(get_post_stati(), get_post_stati(['exclude_from_search' => true])),
            'fields'         => 'ids',
            'posts_per_page' => self::MAX_TIERS_AND_CONFIGS,
        ]);

        if (count($tier_ids) >= self::MAX_TIERS_AND_CONFIGS) {
            self::$truncated = true;
        }

        // get_posts(['fields' => 'ids']) skips meta priming, so batch it
        // here rather than let the per-tier get_post_meta() below run N+1.
        if ([] !== $tier_ids) {
            _prime_post_caches($tier_ids, false, true);
        }

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
