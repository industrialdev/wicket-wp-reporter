<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Static adapter registry for integrations{}. Loops known adapters, skips
 * one whose is_available() is false (key absent from the response, not an
 * error), calls collect() for the rest.
 *
 * One adapter per plugin, always — e.g. WooCommerce core and WooCommerce
 * Subscriptions are separate installable plugins, so they're separate
 * adapters (Woocommerce_Adapter, Subscriptions_Adapter) even though one
 * depends on the other, rather than folding one plugin's data into
 * another's adapter. Keeps is_available() meaningful per plugin and lets
 * integrations{} correctly report one absent while the other is present.
 */
class Reporter_Integrations
{
    /** @var array<int, class-string<Reporter_Integration_Adapter>> */
    private static array $adapters = [
        Memberships_Adapter::class,
        Woocommerce_Adapter::class,
        Subscriptions_Adapter::class,
    ];

    /**
     * Each adapter is individually wrapped — one adapter throwing must not
     * lose every other adapter's data (a WooCommerce collection failure,
     * say, should never blank out memberships too). Failures are returned
     * alongside the successful integrations, keyed by adapter slug, so the
     * caller can fold them into collectorErrors[] as
     * `integrations.<slug>` — same granularity as every other collector.
     *
     * @return array{integrations: array<string, array<string, mixed>>, errors: array<string, string>}
     */
    public static function collect(): array
    {
        $integrations = [];
        $errors = [];

        foreach (self::$adapters as $adapter_class) {
            $adapter = new $adapter_class();

            try {
                if (!$adapter->is_available()) {
                    continue;
                }

                $result = $adapter->collect();

                $integrations[$adapter->slug()] = [
                    'available'     => true,
                    'active'        => true,
                    'pluginSlug'    => $adapter->plugin_slug(),
                    'metrics'       => $result['metrics'] ?? [],
                    'configuration' => $result['configuration'] ?? [],
                ];
            } catch (Throwable $e) {
                Reporter_Log::error("Integration adapter '{$adapter->slug()}' failed", ['exception' => $e->getMessage()]);
                $errors[$adapter->slug()] = $e->getMessage();
            }
        }

        return ['integrations' => $integrations, 'errors' => $errors];
    }
}
