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
        $context['source'] = self::SOURCE;
        Wicket()->log()->critical($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        $context['source'] = self::SOURCE;
        Wicket()->log()->error($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        $context['source'] = self::SOURCE;
        Wicket()->log()->warning($message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        $context['source'] = self::SOURCE;
        Wicket()->log()->info($message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        $context['source'] = self::SOURCE;
        Wicket()->log()->debug($message, $context);
    }
}
