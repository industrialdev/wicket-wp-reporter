<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Shared logger for Wicket Reporter.
 *
 * Delegates all logging to the centralized WicketWP\Log via Wicket()->log().
 * The 'wicket-reporter' source is injected automatically so the reporter's
 * log entries land in their own dedicated file rather than the shared
 * 'wicket-plugin' bucket.
 */
class Reporter_Log
{
    private const SOURCE = 'wicket-reporter';

    public static function critical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * Wicket() comes from wicket-wp-base-plugin. The admin_init
     * self-deactivation guard in the main plugin file only covers wp-admin,
     * not REST — Reporter_Rest::check_permission() calls
     * Reporter_Log::warning() on its token-rejection path, which an
     * unauthenticated caller reaches. Without this guard, a REST hit after
     * base-plugin is deactivated or partly loaded would fatal on
     * Wicket()->log() instead of returning the intended 401.
     */
    private static function log(string $level, string $message, array $context): void
    {
        if (!function_exists('Wicket')) {
            return;
        }

        $context['source'] = self::SOURCE;
        Wicket()->log()->{$level}($message, $context);
    }
}
