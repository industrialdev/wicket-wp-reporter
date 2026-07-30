<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Wicket Reporter REST endpoint: GET /wicket-reporter/v1/status.
 *
 * Auth: header-only Bearer token (RFC 6750) checked against the hashed key
 * from Reporter_Settings. Never accepts the key via query string, cookie,
 * or request body — see Reporter_Rest::get_bearer_token().
 */
class Reporter_Rest
{
    private const NAMESPACE = 'wicket-reporter/v1';
    private const ROUTE = '/status';

    /** Rate limit: max requests per key within the window below. */
    private const RATE_LIMIT_MAX_REQUESTS = 60;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_status'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);
    }

    /**
     * Permission callback: disabled check, then bearer-token auth, then
     * rate limit. Order matters — disabled sites reveal nothing about
     * whether a key is valid, and a valid-but-throttled key still 429s
     * rather than 401.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function check_permission(WP_REST_Request $request)
    {
        if ('1' !== wicket_get_option('wicket_reporter_enabled')) {
            return new WP_Error(
                'wicket_reporter_disabled',
                __('Wicket Reporter is currently disabled for this site.', 'wicket-reporter'),
                ['status' => 403]
            );
        }

        $token = self::get_bearer_token($request);
        $stored_hash = get_option(WICKET_REPORTER_OPTION_API_KEY_HASH, '');

        if ('' === $token || '' === $stored_hash || !wp_check_password($token, $stored_hash)) {
            Reporter_Log::warning('REST request rejected: missing or invalid API key');

            return new WP_Error(
                'wicket_reporter_unauthorized',
                __('Missing or invalid API key.', 'wicket-reporter'),
                ['status' => 401]
            );
        }

        $rate_limit_error = self::check_rate_limit($token);

        if (null !== $rate_limit_error) {
            return $rate_limit_error;
        }

        return true;
    }

    /**
     * Reads the API key from the Authorization header only.
     *
     * Actively ignores (does not fall back to) ?api_key= query params, a
     * cookie, or the request body — a key must never leak into server
     * access logs, browser history, or proxy logs via the URL.
     */
    private static function get_bearer_token(WP_REST_Request $request): string
    {
        $header = $request->get_header('authorization');

        if (empty($header) || 1 !== preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            return '';
        }

        return $matches[1];
    }

    /**
     * Per-key transient counter rate limit, same shape as
     * WicketGuestPaymentAuth's failed-attempt counter
     * (wicket-wp-guest-checkout/src/WicketGuestPaymentAuth.php).
     *
     * Keyed on a hash of the raw token (never the token itself, so the
     * transient name doesn't itself become a place the raw key sits in
     * plaintext) rather than the API key's own stored hash, since a
     * transient name has a length ceiling and this only needs to be a
     * stable, collision-resistant identifier for the same key.
     *
     * @return WP_Error|null Error to return (429) if the limit is exceeded, else null.
     */
    private static function check_rate_limit(string $token): ?WP_Error
    {
        $transient_key = WICKET_REPORTER_TRANSIENT_PREFIX . 'ratelimit_' . md5($token);
        $count = (int) get_transient($transient_key);

        if ($count >= self::RATE_LIMIT_MAX_REQUESTS) {
            Reporter_Log::info('REST request throttled: rate limit exceeded');

            return new WP_Error(
                'wicket_reporter_rate_limited',
                __('Rate limit exceeded. Try again shortly.', 'wicket-reporter'),
                ['status' => 429]
            );
        }

        set_transient($transient_key, $count + 1, self::RATE_LIMIT_WINDOW_SECONDS);

        return null;
    }

    /**
     * Builds the status response.
     *
     * Collector order matters: composer (T4) must run before plugins/themes
     * (T3) since T3 cross-references T4's parsed output to derive
     * installType/updateSource — see the plan's field-sourcing notes.
     * integrations{} (T6/T7/T11/T12) stays an empty placeholder for now.
     * Every collector is individually wrapped, both in Reporter_Timer::time()
     * (audit log) and try/catch (Endpoint resilience — one failing collector
     * never 500s the whole response; its section is omitted/empty and the
     * failure appended to collectorErrors[]).
     */
    public static function handle_status(WP_REST_Request $request): WP_REST_Response
    {
        Reporter_Timer::start_request();

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $cache_ttl = 8 * HOUR_IN_SECONDS;
        $collector_errors = [];

        $composer_result = self::run_collector('composer', $collector_errors, static fn () => Reporter_Composer::collect());
        $composer_packages = $composer_result['packages'] ?? [];

        if (null !== ($composer_result['error'] ?? null)) {
            $collector_errors[] = ['collector' => 'composer', 'message' => $composer_result['error']];
        }

        $plugins = self::run_collector('plugins', $collector_errors, static fn () => Reporter_Plugins::collect_plugins($composer_packages)) ?? [];
        $themes = self::run_collector('themes', $collector_errors, static fn () => Reporter_Plugins::collect_themes($composer_packages)) ?? [];

        $body = [
            'schemaVersion' => 1,
            'generatedAt'   => $now,
            'cache'         => [
                'generatedAt' => $now,
                'expiresAt'   => gmdate('Y-m-d\TH:i:s\Z', time() + $cache_ttl),
            ],
            'monitor' => [
                'version'      => WICKET_REPORTER_VERSION,
                'capabilities' => ['wordpress', 'plugins', 'themes', 'composer'],
                'generationMs' => null, // filled in below, after Reporter_Timer::finish_request()
            ],
            'site'      => Reporter_Timer::time('site', static fn () => self::get_site_info()),
            // latestVersion/updateAvailable deliberately omitted, not null —
            // see the comment in Reporter_Plugins::collect_plugins() for why.
            'wordpress' => Reporter_Timer::time('wordpress', static fn () => [
                'version'     => get_bloginfo('version'),
                'totalUsers'  => null,
                'usersByRole' => [],
            ]),
            'composer'         => [
                '_meta' => ['count' => count($composer_packages)],
                'items' => $composer_packages,
            ],
            'plugins'          => [
                '_meta' => [
                    'count'       => count($plugins),
                    'activeCount' => self::count_active($plugins),
                ],
                'items' => $plugins,
            ],
            'themes'           => [
                '_meta' => [
                    'count'       => count($themes),
                    'activeCount' => self::count_active($themes),
                ],
                'items' => $themes,
            ],
            'integrations'     => [],
            'updates'          => ['lastCheckedAt' => null],
            'collectorErrors'  => $collector_errors,
        ];

        // Same total Reporter_Timer already measured for the log line —
        // surfaced here too so a caller can see generation cost without
        // reading server-side logs.
        $body['monitor']['generationMs'] = Reporter_Timer::finish_request();

        return new WP_REST_Response($body, 200);
    }

    /**
     * Runs one collector wrapped in both Reporter_Timer::time() (audit log)
     * and a try/catch — a thrown exception is logged, appended to
     * collectorErrors[] by reference, and the section falls back to null so
     * the rest of the response still returns (Endpoint resilience rule).
     *
     * @param array<int, array{collector: string, message: string}> $collector_errors
     */
    private static function run_collector(string $name, array &$collector_errors, callable $section): mixed
    {
        try {
            return Reporter_Timer::time($name, $section);
        } catch (Throwable $e) {
            Reporter_Log::error("Collector '{$name}' failed", ['exception' => $e->getMessage()]);
            $collector_errors[] = ['collector' => $name, 'message' => $e->getMessage()];

            return null;
        }
    }

    /**
     * Cheap count of 'active' entries in a plugins[]/themes[] items array —
     * count() only, no re-querying anything. Used to build each category's
     * own `_meta.activeCount` below.
     */
    private static function count_active(array $items): int
    {
        return count(array_filter(
            $items,
            static fn (array $item) => 'active' === ($item['status'] ?? null)
        ));
    }

    /**
     * site{} fields — see the plan's field-sourcing notes: site.id from a
     * sanitized home_url(), site.url from home_url() specifically (not
     * site_url(), which can diverge for a subdirectory WP install),
     * site.name from get_bloginfo('name'), site.environment from
     * wp_get_environment_type() unless the settings override is set.
     */
    private static function get_site_info(): array
    {
        $override = wicket_get_option('wicket_reporter_environment_override', '');
        $environment = '' !== $override ? $override : wp_get_environment_type();

        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
        $site_id = sanitize_title(str_replace('.', '-', (string) $host));

        return [
            'id'          => $site_id,
            'name'        => get_bloginfo('name'),
            'url'         => home_url(),
            'environment' => $environment,
        ];
    }
}
