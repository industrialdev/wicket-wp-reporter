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
    private const RATE_LIMIT_MAX_REQUESTS = 120;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /**
     * Independent per-IP ceiling, same window, so one misbehaving caller
     * cannot spend a shared key's whole budget. Higher than the per-key
     * ceiling so it is a burst guard, not the binding limit in normal
     * operation — the per-key ceiling stays the real budget.
     */
    private const RATE_LIMIT_MAX_REQUESTS_PER_IP = 240;

    /** 8h flat TTL, matching the stack-wide caching policy. No early-bust hooks — TTL-only, by design. */
    private const CACHE_TTL_SECONDS = 8 * HOUR_IN_SECONDS;
    private const CACHE_KEY = WICKET_REPORTER_TRANSIENT_PREFIX . 'status_response';

    /**
     * Longer-lived copy of the same body, written alongside the fresh cache
     * on every successful build. Serves lock-contention callers (see
     * handle_status()) so an expired cache degrades to slightly-stale data
     * instead of a 503 with no body — the monitor treats any non-2xx as a
     * hard failure and drops the site from the dashboard entirely, so a
     * cold-cache 503 on the first request after every 8h expiry blanked a
     * site out rather than showing it slightly behind.
     */
    private const STALE_CACHE_KEY = WICKET_REPORTER_TRANSIENT_PREFIX . 'status_response_stale';
    private const STALE_CACHE_TTL_SECONDS = 48 * HOUR_IN_SECONDS;
    private const STALE_HEADER = 'X-Wicket-Reporter-Stale';

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_status'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            // T19: excludes this route from the public, unauthenticated
            // `GET /wp-json` index, which otherwise advertises its
            // existence (namespace, path, methods) to anyone. The route
            // itself still requires the bearer token regardless — this
            // only stops it from being discoverable without one.
            'show_in_index'       => false,
        ]);

        // M1: bearer auth never establishes a WP user, so WP's default
        // is_user_logged_in() gate sends NO nocache headers. Without this,
        // a full site inventory (plugin list, versions, private repo slugs,
        // commit references) ships with no Cache-Control and can be cached
        // by an intermediary or misconfigured edge and served to an
        // unauthenticated requester. Scope the nocache gate to this route.
        add_filter('rest_send_nocache_headers', [__CLASS__, 'force_nocache_for_status']);
    }

    /**
     * Forces WP's REST nocache headers, but only for this plugin's route —
     * leaving every other REST endpoint's caching policy untouched.
     */
    public static function force_nocache_for_status(bool $nocache): bool
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        if (
            false !== strpos($uri, '/wicket-reporter/v1/')
            || false !== strpos($uri, 'rest_route=/wicket-reporter/v1/')
        ) {
            return true;
        }

        return $nocache;
    }

    /**
     * Permission callback: availability guard, disabled check, bearer-token
     * auth, then rate limit. Order matters — an unavailable site (base
     * plugin missing) 503s before touching undefined helpers; disabled sites
     * reveal nothing about whether a key is valid; a valid-but-throttled
     * key still 429s rather than 401.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function check_permission(WP_REST_Request $request)
    {
        // S5: wicket_get_option()/Wicket()->log() come from wicket-wp-base-plugin.
        // The admin_init self-deactivation guard only covers wp-admin, not
        // REST — without this guard a REST hit after base-plugin removal
        // would call an undefined function and fatal to a stack trace.
        if (!function_exists('wicket_get_option')) {
            return new WP_Error(
                'wicket_reporter_unavailable',
                __('Wicket Reporter is unavailable on this site.', 'wicket-reporter'),
                ['status' => 503]
            );
        }

        if ('1' !== wicket_get_option('wicket_reporter_enabled')) {
            return new WP_Error(
                'wicket_reporter_disabled',
                __('Wicket Reporter is currently disabled for this site.', 'wicket-reporter'),
                ['status' => 403]
            );
        }

        $token = self::get_bearer_token($request);
        $stored_hash = get_option(WICKET_REPORTER_OPTION_API_KEY_HASH, '');

        // S1: API keys are 48-char CSPRNG tokens (~285 bits), so the slow
        // password hashing wp_check_password() applies is pure overhead AND
        // a CPU-amplification DoS vector on this unauthenticated endpoint
        // (any caller sending 'Authorization: Bearer x' forced a bcrypt
        // verify, unthrottled, since the rate limit runs only after auth).
        // Store and compare a fast SHA-256 with hash_equals() (constant-time).
        if ('' === $token || '' === $stored_hash || !hash_equals(Reporter_Settings::hash_token($token), $stored_hash)) {
            Reporter_Log::warning('REST request rejected: missing or invalid API key');

            return new WP_Error(
                'wicket_reporter_unauthorized',
                __('Missing or invalid API key.', 'wicket-reporter'),
                ['status' => 401]
            );
        }

        $rate_limit_error = self::check_rate_limit(
            'ratelimit_' . md5($token),
            self::RATE_LIMIT_MAX_REQUESTS
        );

        if (null !== $rate_limit_error) {
            return $rate_limit_error;
        }

        // Independent bucket, same window and mechanism, keyed on the client
        // IP rather than the API key. REMOTE_ADDR only — never a
        // caller-supplied header (X-Forwarded-For, Client-IP). No
        // trusted-proxy allowlist exists anywhere in this stack, so those
        // headers are fully attacker-controlled; keying a rate limit on one
        // would let an attacker send a fresh value per request and land in a
        // fresh, empty bucket every time; each is a place a new key gets
        // written and never checked back into. On a load-balanced host
        // REMOTE_ADDR resolves to the balancer, so fleet traffic from one
        // host shares one bucket — the per-key ceiling above is the real
        // limit; this is only a burst guard against a single runaway source.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        if ('' !== $ip) {
            $ip_rate_limit_error = self::check_rate_limit(
                'ratelimit_ip_' . md5($ip),
                self::RATE_LIMIT_MAX_REQUESTS_PER_IP
            );

            if (null !== $ip_rate_limit_error) {
                return $ip_rate_limit_error;
            }
        }

        return true;
    }

    /** @var array<string,int>|null Memoized count_users() result. */
    private static ?array $user_counts = null;

    /**
     * count_users() shared across the wordpress{} collector and the
     * WooCommerce adapter. It is a CPU-intensive scan over every
     * wp_capabilities usermeta row (one COUNT column per role), and was
     * being computed twice per cache miss. Memoized once per request.
     */
    public static function user_counts(): array
    {
        if (null === self::$user_counts) {
            self::$user_counts = count_users();
        }

        return self::$user_counts;
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
     * Fixed-window transient counter rate limit, shared by the per-key and
     * per-IP buckets in check_permission(). $bucket_key is already a caller-
     * chosen, collision-resistant identifier (a prefixed hash) — never the
     * raw token or a raw IP, so the transient name doesn't itself become a
     * place sensitive input sits in plaintext.
     *
     * The transient key includes a fixed time bucket (floor(time() /
     * WINDOW)), not just $bucket_key. set_transient() rewrites the
     * transient's expiry on every write, including an update to a transient
     * that already exists — a counter keyed on $bucket_key alone would have
     * its window pushed forward by every accepted request, so a caller near
     * the ceiling could extend the window it has to wait out indefinitely.
     * Bucketing by time makes the window fixed: a write inside one bucket
     * cannot affect the next bucket's key, so the limit always clears within
     * one window.
     *
     * @return WP_Error|null Error to return (429) if the limit is exceeded, else null.
     */
    private static function check_rate_limit(string $bucket_key, int $max_requests): ?WP_Error
    {
        $bucket = (int) floor(time() / self::RATE_LIMIT_WINDOW_SECONDS);
        $transient_key = WICKET_REPORTER_TRANSIENT_PREFIX . $bucket_key . '_' . $bucket;
        $count = (int) get_transient($transient_key);

        if ($count >= $max_requests) {
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
     * Serves the cached response if present, else builds and caches it —
     * so a repeat request skips every collector entirely.
     */
    public static function handle_status(WP_REST_Request $request): WP_REST_Response
    {
        $cached = get_transient(self::CACHE_KEY);

        if (false !== $cached && is_array($cached)) {
            return new WP_REST_Response($cached, 200);
        }

        // M3: coalesce concurrent cache-miss builds with a short lock. A
        // slow or timed-out generation can otherwise be re-triggered by
        // every incoming request up to the rate limit — a self-sustaining
        // load spike with no back-off (and the heaviest collectors, on a
        // large member site, are exactly the ones that can time out).
        // First request in builds; the rest get 503 + Retry-After. The
        // TTL is the safety net if the builder dies before `finally`.
        $lock_key = WICKET_REPORTER_TRANSIENT_PREFIX . 'status_build_lock';

        if (false !== get_transient($lock_key)) {
            $stale = get_transient(self::STALE_CACHE_KEY);

            if (false !== $stale && is_array($stale)) {
                return new WP_REST_Response($stale, 200, [self::STALE_HEADER => '1']);
            }

            // Genuinely nothing to serve — this is the first-ever build, or
            // the stale copy has also expired (48h with no successful
            // build). Only path left is asking the caller to wait.
            return new WP_REST_Response(
                ['error' => 'status_generation_in_progress'],
                503,
                ['Retry-After' => '5']
            );
        }

        set_transient($lock_key, 1, 30);

        try {
            // Re-check after winning the lock: another request may have
            // just finished building while this one was queued.
            $cached = get_transient(self::CACHE_KEY);

            if (false !== $cached && is_array($cached)) {
                return new WP_REST_Response($cached, 200);
            }

            $body = self::build_status_body();

            set_transient(self::CACHE_KEY, $body, self::CACHE_TTL_SECONDS);
            set_transient(self::STALE_CACHE_KEY, $body, self::STALE_CACHE_TTL_SECONDS);

            return new WP_REST_Response($body, 200);
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Builds the status response from scratch, running every collector.
     *
     * Collector order matters: the composer collector must run before the
     * plugin/theme collectors, since those cross-reference its parsed
     * output to derive installType/updateSource per plugin/theme. Every
     * collector is individually wrapped, both in Reporter_Timer::time()
     * (audit log) and try/catch (Endpoint resilience — one failing collector
     * never 500s the whole response; its section is omitted/empty and the
     * failure appended to collectorErrors[]).
     *
     * TODO: integrations{} has no adapters registered yet — memberships
     * (T7) and WooCommerce (T12) will add themselves to
     * Reporter_Integrations::$adapters once built, and this method needs
     * no change when they do.
     */
    private static function build_status_body(): array
    {
        Reporter_Timer::start_request();

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $collector_errors = [];

        $composer_result = self::run_collector('composer', $collector_errors, static fn () => Reporter_Composer::collect());
        $composer_packages = $composer_result['packages'] ?? [];

        if (null !== ($composer_result['error'] ?? null)) {
            $collector_errors[] = ['collector' => 'composer', 'message' => $composer_result['error']];
        }

        $plugins = self::run_collector('plugins', $collector_errors, static fn () => Reporter_Plugins::collect_plugins($composer_packages)) ?? [];
        $themes = self::run_collector('themes', $collector_errors, static fn () => Reporter_Plugins::collect_themes($composer_packages)) ?? [];

        $integrations_result = self::run_collector('integrations', $collector_errors, static fn () => Reporter_Integrations::collect());
        $integrations = $integrations_result['integrations'] ?? [];

        foreach ($integrations_result['errors'] ?? [] as $slug => $message) {
            $collector_errors[] = ['collector' => "integrations.{$slug}", 'message' => $message];
        }

        $body = [
            'schemaVersion' => 1,
            'generatedAt'   => $now,
            'cache'         => [
                'generatedAt' => $now,
                'expiresAt'   => gmdate('Y-m-d\TH:i:s\Z', time() + self::CACHE_TTL_SECONDS),
            ],
            'monitor' => [
                'version'      => WICKET_REPORTER_VERSION,
                // Base capabilities plus each available adapter's own slug
                // (e.g. 'memberships', 'woocommerce') — mirrors
                // integrations{}'s own key-absence rule: a site without
                // that integration doesn't list it here either.
                'capabilities' => array_merge(['wordpress', 'plugins', 'themes', 'composer'], array_keys($integrations)),
                'generationMs' => null, // filled in below, after Reporter_Timer::finish_request()
            ],
            'site'      => Reporter_Timer::time('site', static fn () => self::get_site_info()),
            // latestVersion/updateAvailable deliberately omitted, not null —
            // see the comment in Reporter_Plugins::collect_plugins() for why.
            'wordpress' => Reporter_Timer::time('wordpress', static function () {
                // count_users() is CPU-intensive: one COUNT column per
                // role applied to every wp_capabilities usermeta row, not
                // the cheap one-query call earlier comments claimed. So it
                // is memoized once per request via user_counts() and shared
                // with the WooCommerce adapter. Nested under metrics{}
                // rather than flat on wordpress{} — same identity-vs-usage
                // split the integration adapters use, since
                // version/latestVersion/updateAvailable are a different
                // kind of field than usage counts.
                $user_counts = self::user_counts();

                return [
                    'version'    => get_bloginfo('version'),
                    'phpVersion' => PHP_VERSION,
                    'metrics'    => [
                        'totalUsers'  => $user_counts['total_users'],
                        'usersByRole' => $user_counts['avail_roles'],
                    ],
                ];
            }),
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
            'integrations'     => $integrations,
            'updates'          => ['lastCheckedAt' => null],
            'collectorErrors'  => $collector_errors,
        ];

        // Same total Reporter_Timer already measured for the log line —
        // surfaced here too so a caller can see generation cost without
        // reading server-side logs.
        $body['monitor']['generationMs'] = Reporter_Timer::finish_request();

        return $body;
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
    /**
     * Every value the settings dropdown (Reporter_Settings::register_settings)
     * can legally write, besides the empty "Auto-detect" default.
     */
    private const VALID_ENVIRONMENT_OVERRIDES = ['production', 'staging', 'development', 'sandbox'];

    private static function get_site_info(): array
    {
        $override = wicket_get_option('wicket_reporter_environment_override', '');

        // The settings dropdown constrains input to the list above, but this
        // reads from the shared wicket_settings option, which
        // wicket-wp-portus also writes during config import — so the value
        // can arrive from an import, not only the dropdown. site.environment
        // feeds staging-only guardrails elsewhere in this stack (see
        // wicket-cloudways-ssh-debug), so an unvalidated or malformed value
        // here is a safety-rail bypass, not just a cosmetic wrong label.
        $environment = (is_string($override) && in_array($override, self::VALID_ENVIRONMENT_OVERRIDES, true))
            ? $override
            : wp_get_environment_type();

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
