<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Wicket Reporter settings section, added to the Wicket -> Integrations tab.
 *
 * Same wicket_settings_tabs pattern as OSL_Limiter (wicket-wp-woo-order-status-limits)
 * and WicketGuestPaymentConfig (wicket-wp-guest-checkout).
 */
class Reporter_Settings
{
    private static bool $settings_section_added = false;

    /**
     * Hook into wicket_settings_tabs and wrap the existing 'integrations' entry's
     * callback so our section renders after the base tab.
     *
     * @param array $tabs
     * @return array
     */
    public static function extend_settings_tabs(array $tabs): array
    {
        foreach ($tabs as $priority => $config) {
            if (!is_array($config) || ('integrations' !== ($config['key'] ?? ''))) {
                continue;
            }

            $original_callback = $config['callback'] ?? null;
            self::$settings_section_added = true;

            $tabs[$priority]['callback'] = function ($tab) use ($original_callback): void {
                if (is_callable($original_callback)) {
                    call_user_func($original_callback, $tab);
                }
                self::add_settings_section($tab);
            };

            return $tabs;
        }

        return $tabs;
    }

    /**
     * Fallback for older base plugin versions that don't expose wicket_settings_tabs.
     * Skipped if extend_settings_tabs already ran.
     *
     * @param mixed $integrations_tab WPSettings tab instance.
     * @return mixed
     */
    public static function extend_settings_tab_fallback($integrations_tab)
    {
        if (self::$settings_section_added) {
            return $integrations_tab;
        }

        self::add_settings_section($integrations_tab);

        return $integrations_tab;
    }

    /**
     * Add the Wicket Reporter section and its options to a tab object.
     *
     * Auto-generates the API key here, on render (a plain page load), if
     * none exists yet — deliberately not tied to a settings-page save/POST,
     * so simply opening this tab always guarantees a key exists to show.
     *
     * @param mixed $tab WPSettings tab instance.
     */
    private static function add_settings_section($tab): void
    {
        if (!is_object($tab) || !method_exists($tab, 'add_section')) {
            return;
        }

        self::ensure_key_exists();

        $section = $tab->add_section(__('Wicket Reporter', 'wicket-reporter'), [
            'as_link'     => true,
            'description' => __('Reports installed plugins, theme, WP core, and composer state to the fleet health monitor via a secured, local-only REST endpoint.', 'wicket-reporter'),
        ]);

        self::register_settings($section);
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Register settings fields into the Wicket Reporter section.
     *
     * @param mixed $section WPSettings Section instance.
     */
    public static function register_settings($section): void
    {
        $section->add_option('checkbox', [
            'name'        => 'wicket_reporter_enabled',
            'label'       => __('Enable Wicket Reporter', 'wicket-reporter'),
            'description' => __('Hard kill switch. When disabled, the REST endpoint itself refuses requests — no collectors run, no data leaves this site.', 'wicket-reporter'),
            'default'     => '0',
        ]);

        $section->add_option('select', [
            'name'        => 'wicket_reporter_environment_override',
            'label'       => __('Environment Override', 'wicket-reporter'),
            'description' => __('Overrides this site\'s self-reported environment for the fleet monitor. Leave on Auto-detect to use WordPress\'s own wp_get_environment_type().', 'wicket-reporter'),
            'options'     => [
                ''             => __('Auto-detect', 'wicket-reporter'),
                'production'   => __('Production', 'wicket-reporter'),
                'staging'      => __('Staging', 'wicket-reporter'),
                'development'  => __('Development', 'wicket-reporter'),
                'sandbox'      => __('Sandbox', 'wicket-reporter'),
            ],
            'default'     => '',
        ]);

        // Masked key display. Custom-rendered rather than a plain 'text'
        // option: WPSettings' own value system reads from the tab's
        // aggregate settings option (a single nested array), but only the
        // key's hash is ever stored (written by ensure_key_exists()/reset),
        // and a hash can't be shown back as the real value — so this field
        // bypasses the normal value/save pipeline entirely and just renders
        // a masked placeholder plus a Reset link. Reset is a plain GET link
        // (not the settings page's Save Changes/POST path, which has been
        // unreliable for this plugin's fields on this site, for reasons not
        // yet root-caused), handled by maybe_handle_reset()
        // before this tab even renders, and its nonce URL explicitly targets
        // this same tab (page=wicket-settings&tab=integrations) — clicking
        // it always lands back here, never a generic admin.php or the tab's
        // own root. The raw key itself is shown exactly once, via a flash
        // notice, right after a reset (see admin_notices hook in the main
        // plugin file).
        $section->add_option('text', [
            'name'        => 'api_key_display',
            'label'       => __('API Key', 'wicket-reporter'),
            'render'      => [__CLASS__, 'render_api_key_field'],
            'sanitize'    => static fn ($value) => '',
        ]);
    }

    /**
     * Renders a masked API key placeholder with a Reset link beside it.
     *
     * Only the key's hash is stored (see WICKET_REPORTER_OPTION_API_KEY_HASH),
     * so there is no raw value to read back here — the raw key is shown
     * exactly once, via a flash notice, right after generation/reset (see
     * the admin_notices hook in the main plugin file).
     *
     * @param mixed $impl WPSettings Option implementation instance (unused —
     *                    this field ignores the normal value system entirely).
     * @return string
     */
    public static function render_api_key_field($impl): string
    {
        $has_key = (bool) get_option(WICKET_REPORTER_OPTION_API_KEY_HASH, '');
        $label = esc_html__('API Key', 'wicket-reporter');
        $masked_value = $has_key
            ? esc_html__('••••••••••••••••••••••••••••••• (hidden — reset to view)', 'wicket-reporter')
            : esc_html__('No key set', 'wicket-reporter');
        $description = esc_html__('Add this site to the fleet monitor\'s registry using this key. The raw value is shown once, right after Reset, and is not stored or retrievable afterward.', 'wicket-reporter');
        $reset_label = esc_html__('Reset', 'wicket-reporter');

        $reset_url = wp_nonce_url(
            add_query_arg(
                [
                    'page'                   => 'wicket-settings',
                    'tab'                    => 'integrations',
                    // Matches the section's own slug (sanitize_title() of
                    // its title, "Wicket Reporter") — without this, the
                    // WPSettings library lands on the tab's first as_link
                    // section instead of jumping back to this one.
                    'section'                => 'wicket-reporter',
                    'wicket_reporter_reset'  => '1',
                ],
                admin_url('admin.php')
            ),
            'wicket_reporter_reset_key'
        );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo $label; ?></th>
            <td class="forminp forminp-text">
                <div style="display: flex; gap: 8px; align-items: center; max-width: 480px;">
                    <input type="text" readonly value="<?php echo $masked_value; ?>" style="flex: 1;">
                    <a href="<?php echo esc_url($reset_url); ?>" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Generate a new key? The old key stops working immediately.', 'wicket-reporter')); ?>');">
                        <?php echo $reset_label; ?>
                    </a>
                </div>
                <p class="description"><?php echo $description; ?></p>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Handles the Reset API Key link's GET navigation.
     *
     * Runs on init (before the settings page renders). Only the new key's
     * hash is persisted; the raw value is stashed in a 60-second,
     * current-user-scoped transient and the redirect carries a flag so the
     * admin_notices hook in the main plugin file can flash it once.
     */
    public static function maybe_handle_reset(): void
    {
        if (empty($_GET['wicket_reporter_reset']) || !is_admin()) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('wicket_reporter_reset_key');

        $new_key = self::generate_and_store_key();

        Reporter_Log::info('API key reset', ['user_id' => get_current_user_id()]);

        set_transient('wicket_reporter_new_key_' . get_current_user_id(), $new_key, 60);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'                        => 'wicket-settings',
                    'tab'                         => 'integrations',
                    'section'                     => 'wicket-reporter',
                    'wicket_reporter_key_generated' => '1',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Generates and stores an API key (hash only) if none exists yet.
     * Called on every settings-tab render (a GET) — a page load alone
     * guarantees a verifiable key exists.
     */
    private static function ensure_key_exists(): void
    {
        if ('' !== get_option(WICKET_REPORTER_OPTION_API_KEY_HASH, '')) {
            return;
        }

        self::generate_and_store_key();
        Reporter_Log::info('API key auto-generated (none existed)');
    }

    /**
     * Stable, fast hash of a raw API key for storage and comparison — the
     * single source of truth shared with Reporter_Rest::check_permission().
     * SHA-256 of a 285-bit CSPRNG token: no offline brute-force threat
     * exists, so the slow KDF human passwords need is unnecessary here and
     * only adds latency plus a CPU-amplification DoS surface on the public
     * endpoint. The caller compares with hash_equals() for constant time.
     */
    public static function hash_token(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Generates a raw key, stores only its hash, and returns the raw value
     * to the caller (which decides whether/how to surface it once).
     */
    private static function generate_and_store_key(): string
    {
        $new_key = wp_generate_password(48, false, false);
        update_option(WICKET_REPORTER_OPTION_API_KEY_HASH, self::hash_token($new_key), false);

        return $new_key;
    }

    // -------------------------------------------------------------------------
    // Uninstall
    // -------------------------------------------------------------------------

    /**
     * Removes the stored API key hash and any transients on uninstall.
     *
     * Not run on deactivate — deactivating and reactivating must not force
     * key regeneration. The enabled/environment-override fields live inside
     * base-plugin's shared `wicket_settings` aggregate option (WPSettings
     * stores one option for the whole tab page, keyed by field name — see
     * wicket_get_option()), not their own standalone options, so they're
     * unset from that array rather than deleted as top-level options; the
     * shared option itself is never deleted since other plugins' settings
     * live in it too.
     */
    public static function on_uninstall(): void
    {
        delete_option(WICKET_REPORTER_OPTION_API_KEY_HASH);

        $wicket_settings = get_option('wicket_settings', []);
        unset($wicket_settings['wicket_reporter_enabled'], $wicket_settings['wicket_reporter_environment_override']);
        update_option('wicket_settings', $wicket_settings);

        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_' . WICKET_REPORTER_TRANSIENT_PREFIX) . '%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_timeout_' . WICKET_REPORTER_TRANSIENT_PREFIX) . '%'
            )
        );
    }
}
