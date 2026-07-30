<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * One implementation per known integration (memberships, WooCommerce, ...).
 * Sibling plugins stay unaware of this plugin — no filter hooks on their
 * side. Coupling to a target plugin's internal data shape lives entirely
 * in its adapter.
 */
interface Reporter_Integration_Adapter
{
    /** integrations{} key this adapter reports under (e.g. 'memberships'). */
    public function slug(): string;

    /** Whether the target plugin is active on this site. */
    public function is_available(): bool;

    /** Cheap-count-only metrics — see the plan's Lightweight-only principle. */
    public function collect(): array;
}
