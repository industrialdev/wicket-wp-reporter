<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Normalises wp_count_posts() for the integration adapters.
 *
 * Core returns a stdClass (verified in wp-includes/post.php:
 * `$counts = (object) $counts;`), and the value is filtered
 * (`apply_filters('wp_count_posts', ...)`), so a third-party plugin can
 * legally return something else. Memberships_Adapter and Woocommerce_Adapter
 * each read the same core call a different way — one cast with `(array)`,
 * one read a property directly — so a filtered non-object return worked on
 * one path and fataled on the other. Route both through this helper instead.
 */
class Reporter_Post_Status_Counts
{
    /** @return array<string, int> Status => count, whatever shape wp_count_posts() returned. */
    public static function for_post_type(string $post_type): array
    {
        $counts = wp_count_posts($post_type);

        if (is_object($counts)) {
            $counts = get_object_vars($counts);
        }

        if (!is_array($counts)) {
            return [];
        }

        return array_map('intval', $counts);
    }
}
