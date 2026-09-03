<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Plugin/theme collectors.
 *
 * Runs after Reporter_Composer and consumes its parsed output directly to
 * derive installType/updateSource per package. Standard WP core
 * enumeration only (get_plugins(), wp_get_themes()), no raw filesystem
 * scanning beyond what those already do.
 */
class Reporter_Plugins
{
    /**
     * @param array<int, array<string, mixed>> $composer_packages Reporter_Composer's parsed composer[] entries.
     * @return array<int, array<string, mixed>>
     */
    public static function collect_plugins(array $composer_packages): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $by_directory = self::index_composer_packages_by_directory($composer_packages);
        $entries = [];

        foreach (get_plugins() as $file => $data) {
            $slug = self::slug_from_file($file);
            $match = $by_directory[$slug] ?? null;

            $update_source = null !== $match
                ? $match['updateSource']
                : self::detect_manual_update_source($slug);

            $entries[] = [
                'file'            => $file,
                'slug'            => $slug,
                'name'            => (string) ($data['Name'] ?? $slug),
                'version'         => (string) ($data['Version'] ?? ''),
                'status'          => is_plugin_active($file) ? 'active' : 'inactive',
                'installType'     => null !== $match ? 'composer' : self::detect_manual_install_type($slug),
                'composerPackage' => $match['name'] ?? null,
                'updateSource'    => $update_source,
                'packageKind'     => self::package_kind_from_update_source($update_source),
                // latestVersion/updateAvailable deliberately omitted, not
                // set to null — this plugin has no external version-lookup.
                // A null value reads ambiguously close to "no update
                // available" under a naive falsy check; omitting the key
                // is unambiguous "unknown here."
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $composer_packages
     * @return array<int, array<string, mixed>>
     */
    public static function collect_themes(array $composer_packages): array
    {
        $by_directory = self::index_composer_packages_by_directory($composer_packages);

        // get_stylesheet() is core's filtered accessor
        // (apply_filters('stylesheet', get_option('stylesheet'))) — reading
        // the raw option bypassed that filter. WPML's per-language theme
        // switching, and any theme-switcher/A-B plugin, filters this hook,
        // so the raw option can name the wrong theme as active on this
        // stack. get_template() is the matching filtered accessor for the
        // active theme's parent, used below to also mark a child theme's
        // parent active — it is genuinely in use, but comparing only
        // $stylesheet === $active_stylesheet always reported it inactive.
        $active_stylesheet = get_stylesheet();
        $active_template = get_template();
        $entries = [];

        foreach (wp_get_themes() as $stylesheet => $theme) {
            $match = $by_directory[$stylesheet] ?? null;

            $update_source = null !== $match
                ? $match['updateSource']
                : self::detect_manual_update_source($stylesheet, true);

            $is_active = $stylesheet === $active_stylesheet || $stylesheet === $active_template;

            $entries[] = [
                'stylesheet'      => $stylesheet,
                'template'        => $theme->get_template(),
                'name'            => (string) $theme->get('Name'),
                'version'         => (string) $theme->get('Version'),
                'status'          => $is_active ? 'active' : 'inactive',
                'installType'     => null !== $match ? 'composer' : self::detect_manual_install_type($stylesheet, true),
                'composerPackage' => $match['name'] ?? null,
                'updateSource'    => $update_source,
                'packageKind'     => self::package_kind_from_update_source($update_source),
                // See the comment in collect_plugins() — omitted, not null.
            ];
        }

        return $entries;
    }

    /**
     * Keys composer[] entries by the directory name Composer would have
     * installed them under, so a plugin/theme folder name looks itself up
     * directly — one pass over composer_packages, not one lookup per
     * plugin.
     *
     * @param array<int, array<string, mixed>> $composer_packages
     * @return array<string, array<string, mixed>>
     */
    private static function index_composer_packages_by_directory(array $composer_packages): array
    {
        $index = [];

        foreach ($composer_packages as $package) {
            if (empty($package['name']) || !in_array($package['type'] ?? '', ['wordpress-plugin', 'wordpress-theme', 'wordpress-muplugin'], true)) {
                continue;
            }

            $directory = Reporter_Composer::package_directory_name((string) $package['name']);
            $index[$directory] = [
                'name'         => $package['name'],
                'updateSource' => self::update_source_from_entry($package),
            ];
        }

        return $index;
    }

    /**
     * composer[] entries already carry enough to classify updateSource
     * without re-parsing the lock file: a `repository` key present means a
     * Wicket git package (see Reporter_Composer::build_entry); otherwise
     * fall back to the composer package name's own vendor prefix.
     */
    private static function update_source_from_entry(array $package): string
    {
        if (isset($package['repository'])) {
            return 'git';
        }

        $name = (string) ($package['name'] ?? '');

        if (str_starts_with($name, 'wicketpress/')) {
            return 'satispress';
        }

        if (str_starts_with($name, 'wp-plugin/')
            || str_starts_with($name, 'wpackagist-plugin/')
            || str_starts_with($name, 'wpackagist-theme/')
        ) {
            return 'wordpress-org';
        }

        return 'unknown';
    }

    /**
     * Git-sourced rows are Wicket-authored composer packages, not real
     * installable WP plugins/themes — there's no wordpress.org listing or
     * SatisPress mirror behind them, only a git ref (see
     * update_source_from_entry()). Every other source genuinely is a plugin
     * or theme with its own real update channel. A consumer (e.g. a fleet
     * dashboard) can group real plugins/themes separately from
     * `composer-package` rows without re-deriving this from `updateSource`
     * itself. `installable` intentionally covers both plugins and themes —
     * the caller already knows which from the section it's building
     * (`plugins[]` vs `themes[]`), this field only needs to say whether the
     * row is a real WP-installable unit at all.
     */
    private static function package_kind_from_update_source(string $update_source): string
    {
        return 'git' === $update_source ? 'composer-package' : 'installable';
    }

    private static function slug_from_file(string $plugin_file): string
    {
        $slug = dirname($plugin_file);

        return '.' === $slug ? basename($plugin_file, '.php') : $slug;
    }

    /**
     * No composer match: presence of a readme.txt with a wordpress.org-style
     * header means a manually-installed wordpress.org plugin/theme; anything
     * else is a fully manual install with an unknown source.
     */
    private static function detect_manual_install_type(string $slug, bool $is_theme = false): string
    {
        $base_dir = $is_theme
            ? get_theme_root() . '/' . $slug
            : WP_PLUGIN_DIR . '/' . $slug;

        return is_readable($base_dir . '/readme.txt') ? 'wordpress' : 'manual';
    }

    private static function detect_manual_update_source(string $slug, bool $is_theme = false): string
    {
        return 'wordpress' === self::detect_manual_install_type($slug, $is_theme) ? 'wordpress-org' : 'unknown';
    }
}
