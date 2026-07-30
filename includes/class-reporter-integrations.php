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
     * TODO: register adapter classes here once built — memberships (T7),
     * WooCommerce (T12). Empty for now, so integrations{} reports nothing.
     *
     * @var array<int, class-string<Reporter_Integration_Adapter>>
     */
    private static array $adapters = [];

    public static function collect(): array
    {
        $integrations = [];

        foreach (self::$adapters as $adapter_class) {
            $adapter = new $adapter_class();

            if (!$adapter->is_available()) {
                continue;
            }

            $integrations[$adapter->slug()] = [
                'available' => true,
                'active'    => true,
                'metrics'   => $adapter->collect(),
            ];
        }

        return $integrations;
    }
}
