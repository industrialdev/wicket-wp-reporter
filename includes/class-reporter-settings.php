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

    private const NONCE_ACTION = 'wicket_reporter_generate_key';
    private const NONCE_NAME = 'wicket_reporter_generate_key_nonce';

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
     * @param mixed $tab WPSettings tab instance.
     */
    private static function add_settings_section($tab): void
    {
        if (!is_object($tab) || !method_exists($tab, 'add_section')) {
            return;
        }

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
            'name'        => 'enabled',
            'label'       => __('Enable Wicket Reporter', 'wicket-reporter'),
            'description' => __('Hard kill switch. When disabled, the REST endpoint itself refuses requests — no collectors run, no data leaves this site.', 'wicket-reporter'),
            'default'     => '0',
        ]);

        $section->add_option('select', [
            'name'        => 'environment_override',
            'label'       => __('Environment Override', 'wicket-reporter'),
            'description' => __('Overrides this site\'s self-reported environment for the fleet monitor. Leave on Auto-detect to use WordPress\'s own wp_get_environment_type().', 'wicket-reporter'),
            'choices'     => [
                ''             => __('Auto-detect', 'wicket-reporter'),
                'production'   => __('Production', 'wicket-reporter'),
                'staging'      => __('Staging', 'wicket-reporter'),
                'development'  => __('Development', 'wicket-reporter'),
                'sandbox'      => __('Sandbox', 'wicket-reporter'),
            ],
            'default'     => '',
        ]);

        // Custom-rendered field: API key generate/regenerate button + masked
        // current-key display. Not a WPSettings-managed value — the key hash
        // is stored/rotated via its own admin_post_ handler, not the settings
        // save pipeline, so a click can't be silently overwritten by an
        // unrelated Save Changes submit on this same tab.
        $section->add_option('checkbox', [
            'name'     => 'api_key_display',
            'label'    => '',
            'render'   => [__CLASS__, 'render_api_key_field'],
            'sanitize' => static fn ($value) => $value,
        ]);
    }

    /**
     * Renders the API key status + Generate/Regenerate button.
     *
     * Deliberately outside the WPSettings save pipeline — the option value
     * passed in is discarded (see 'sanitize' above); this field is read-only
     * display plus its own form posting to admin-post.php.
     *
     * @param mixed $impl WPSettings Option instance.
     * @return string
     */
    public static function render_api_key_field($impl): string
    {
        $label = esc_html__('API Key', 'wicket-reporter');
        $has_key = (bool) get_option(WICKET_REPORTER_OPTION_API_KEY_HASH, '');
        $button_label = $has_key
            ? esc_html__('Regenerate Key', 'wicket-reporter')
            : esc_html__('Generate Key', 'wicket-reporter');

        $status = $has_key
            ? esc_html__('A key is set. The raw value is shown once, at generation time, and is not stored or retrievable afterward.', 'wicket-reporter')
            : esc_html__('No key set. The REST endpoint will reject every request until a key is generated.', 'wicket-reporter');

        ob_start();
        ?>
        <tr>
            <th scope="row"><?php echo $label; ?></th>
            <td>
                <p><?php echo $status; ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wicket_reporter_generate_key">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                    <button type="submit" class="button button-secondary">
                        <?php echo $button_label; ?>
                    </button>
                </form>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Generate/regenerate action
    // -------------------------------------------------------------------------

    /**
     * Handles the Generate/Regenerate Key button's admin-post.php submission.
     *
     * Registered from the plugin bootstrap file. Shows the raw key once, via
     * a redirect + transient flash (never persisted in the URL or in a log),
     * stores only its hash, and immediately invalidates any previous key.
     */
    public static function handle_generate_key(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'wicket-reporter'));
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $raw_key = wp_generate_password(48, false, false);
        $hash = wp_hash_password($raw_key);

        update_option(WICKET_REPORTER_OPTION_API_KEY_HASH, $hash, false);

        // One-time flash of the raw key, current user only, short TTL —
        // never written to wp_options or any log.
        set_transient(
            'wicket_reporter_new_key_' . get_current_user_id(),
            $raw_key,
            60
        );

        Reporter_Log::info('API key regenerated', ['user_id' => get_current_user_id()]);

        wp_safe_redirect(add_query_arg(
            ['page' => 'wicket-settings', 'tab' => 'integrations', 'wicket_reporter_key_generated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    // -------------------------------------------------------------------------
    // Uninstall
    // -------------------------------------------------------------------------

    /**
     * Removes the stored API key hash and any transients on uninstall.
     *
     * Not run on deactivate — deactivating and reactivating must not force
     * key regeneration.
     */
    public static function on_uninstall(): void
    {
        delete_option(WICKET_REPORTER_OPTION_API_KEY_HASH);
        delete_option(WICKET_REPORTER_OPTION_ENABLED);
        delete_option(WICKET_REPORTER_OPTION_ENV_OVERRIDE);

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
