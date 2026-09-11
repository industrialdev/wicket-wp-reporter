<?php

/**
 * Plugin Name:       Wicket Reporter
 * Plugin URI:        https://github.com/industrialdev/wicket-wp-reporter
 * Description:       Reports installed plugins, theme, WP core, and composer state via a secured local-only REST endpoint, for fleet-wide health monitoring.
 * Version:           1.1.4
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Wicket
 * Author URI:        https://wicket.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wicket-reporter
 * Requires Plugins:  wicket-wp-base-plugin
 */
defined('ABSPATH') || exit;

define('WICKET_REPORTER_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
define('WICKET_REPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WICKET_REPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WICKET_REPORTER_OPTION_API_KEY_HASH', 'wicket_reporter_api_key_hash');
define('WICKET_REPORTER_TRANSIENT_PREFIX', 'wicket_reporter_');

// Load classes early so the settings filters can reference them.
// No Wicket/WP calls at file level — each class does nothing until called.
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/class-reporter-log.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/class-reporter-settings.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/class-reporter-timer.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/class-reporter-composer.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/class-reporter-plugins.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/Integrations/interface-integration-adapter.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/Integrations/class-post-status-counts.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/Integrations/class-memberships-adapter.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/Integrations/class-woocommerce-adapter.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/Integrations/class-subscriptions-adapter.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/class-reporter-integrations.php';
require_once WICKET_REPORTER_PLUGIN_DIR . 'includes/class-reporter-rest.php';

add_action('rest_api_init', ['Reporter_Rest', 'register_routes']);

/**
 * Claims authentication for this plugin's own namespace before any site-wide
 * auth plugin gets to inspect the request.
 *
 * Plugins such as Simple JWT Login (its "JWT Middleware for all WordPress
 * endpoints" option) hook rest_authentication_errors globally at priority 0
 * and try to decode any Authorization: Bearer value as a JWT, on every route,
 * not just their own. They never ask whether the target route wanted their
 * authentication, only whether a token is present. Our key is a 48-char
 * wp_generate_password() string, so their decode throws ("Wrong number of
 * segments") and they hand core a WP_Error from check_authentication(), which
 * runs before dispatch(). Reporter_Rest::check_permission() then never
 * executes at all, and the fleet monitor sees a 4xx indistinguishable from a
 * genuinely bad key.
 *
 * Returning true is core's documented "this authentication method was used,
 * and it succeeded" signal (see WP_REST_Server::check_authentication). It sets
 * no current user and bypasses none of our own auth: dispatch() still runs,
 * and check_permission() still requires a valid bearer token before anything
 * is served. Every global handler in this chain, Simple JWT Login's middleware
 * and its WooCommerce and Force Login integrations along with core's own
 * rest_cookie_check_errors, opens with a bail-if-already-decided guard, so one
 * early true clears them all at once without naming or depending on the
 * internals of any single plugin.
 *
 * Scoped strictly to wicket-reporter/v1. Every other route on the site keeps
 * whatever authentication stack it had.
 */
add_filter('rest_authentication_errors', static function ($errors) {
    if (null !== $errors) {
        return $errors;
    }

    // Set by WP during parse_request, well before serve_request() applies this
    // filter, for both /wp-json/... and ?rest_route=... request forms.
    $route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';

    if (!is_string($route) || !str_starts_with(ltrim($route, '/'), 'wicket-reporter/v1/')) {
        return $errors;
    }

    return true;
}, -PHP_INT_MAX);

// Register the settings section in the Wicket Integrations tab.
// Same wicket_settings_tabs pattern as OSL_Limiter / WicketGuestPaymentConfig.
add_filter('wicket_settings_tabs', ['Reporter_Settings', 'extend_settings_tabs'], 20);
add_filter('wicket_settings_tab_int', ['Reporter_Settings', 'extend_settings_tab_fallback'], 20);

add_action('admin_enqueue_scripts', ['Reporter_Settings', 'maybe_enqueue_admin_assets']);

// Reset API Key link (see render_api_key_field) — a plain GET, handled
// before the tab renders so maybe_handle_reset can flash the new raw key
// via a short-lived transient (see admin_notices hook below).
add_action('admin_init', ['Reporter_Settings', 'maybe_handle_reset']);

/**
 * Show a newly generated/reset raw API key once, immediately after the
 * GET-link reset redirect lands back on this tab.
 *
 * The raw key lives only in a 60-second, current-user-scoped transient (set
 * in maybe_handle_reset) — reading it here deletes it in the same request,
 * so a page refresh never shows it twice. Only the hash is ever persisted.
 */
add_action('admin_notices', function (): void {
    if (empty($_GET['wicket_reporter_key_generated'])) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $transient_key = 'wicket_reporter_new_key_' . get_current_user_id();
    $raw_key = get_transient($transient_key);

    if (false === $raw_key) {
        return;
    }

    delete_transient($transient_key);

    printf(
        '<div class="notice notice-success is-dismissible"><p><strong>%s</strong></p><p><code>%s</code></p><p>%s</p></div>',
        esc_html__('Wicket Reporter API key generated.', 'wicket-reporter'),
        esc_html($raw_key),
        esc_html__('Copy this now — it will not be shown again.', 'wicket-reporter')
    );
});

/**
 * Hard dependency on wicket-wp-base-plugin.
 *
 * No settings UI, no logger, no Wicket() helper exist without it — deactivate
 * with an admin notice rather than fatal on a missing class/hook.
 */
add_action('admin_init', function (): void {
    if (function_exists('Wicket')) {
        return;
    }

    deactivate_plugins(plugin_basename(__FILE__));

    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('Wicket Reporter requires Wicket Base Plugin to be active. It has been deactivated.', 'wicket-reporter') .
            '</p></div>';
    });
});

register_uninstall_hook(__FILE__, ['Reporter_Settings', 'on_uninstall']);
