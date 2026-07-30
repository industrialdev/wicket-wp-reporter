<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Static adapter registry for integrations{}. Loops known adapters, skips
 * one whose is_available() is false (key absent from the response, not an
 * error), calls collect() for the rest.
 */
class Reporter_Integrations
{
    /**
     * TODO: register the WooCommerce adapter here once built (T12).
     *
     * @var array<int, class-string<Reporter_Integration_Adapter>>
     */
    private static array $adapters = [
        Memberships_Adapter::class,
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
