<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Per-request collector timing, for a single audit log line at the end of
 * a status-generation request — a cheap on-the-fly performance snapshot,
 * not a persistent metrics store. One microtime() read before and after
 * each wrapped section; no I/O, no options/transients touched here.
 */
class Reporter_Timer
{
    /** @var array<int, array{name: string, ms: float}> */
    private static array $timings = [];

    private static float $request_start = 0.0;

    /**
     * Marks the start of a new status-generation request. Call once, before
     * any collector runs.
     */
    public static function start_request(): void
    {
        self::$timings = [];
        self::$request_start = microtime(true);

        Reporter_Log::info('Status generation requested');
    }

    /**
     * Wraps one collector/section, timing it and recording the result.
     * Exceptions still propagate — the caller (each collector call site) is
     * already individually try/caught per the plan's Endpoint resilience
     * rule; this only adds timing on top, it doesn't swallow anything.
     */
    public static function time(string $name, callable $section): mixed
    {
        $start = microtime(true);
        $result = $section();
        self::$timings[] = [
            'name' => $name,
            'ms'   => round((microtime(true) - $start) * 1000, 1),
        ];

        return $result;
    }

    /**
     * Logs one summary line for the whole request — total elapsed time plus
     * the per-collector breakdown gathered via time() — then resets state.
     * A single log write per request, not one per collector, to stay cheap.
     *
     * @return float Total elapsed ms, so the caller can also surface it in
     *                the response body's monitor.generationMs (not just the
     *                log) — same number, no second measurement taken.
     */
    public static function finish_request(): float
    {
        $total_ms = round((microtime(true) - self::$request_start) * 1000, 1);

        Reporter_Log::info('Status generation complete', [
            'total_ms'   => $total_ms,
            'collectors' => self::$timings,
        ]);

        self::$timings = [];

        return $total_ms;
    }
}
