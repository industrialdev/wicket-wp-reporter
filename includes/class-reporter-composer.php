<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * composer.json/composer.lock collector.
 *
 * Direct JSON parse only — no shell_exec, no composer binary dependency.
 * Runs before the plugin/theme collectors (Reporter_Rest::handle_status)
 * so they can cross-reference this output to derive installType/
 * updateSource per plugin/theme.
 */
class Reporter_Composer
{
    /**
     * @return array{packages: array<int, array<string, mixed>>, error: string|null}
     *   `packages` is the composer[] response array (possibly empty).
     *   `error` is a collectorErrors[]-shaped message, or null on success.
     */
    public static function collect(): array
    {
        $lock_path = self::find_file('composer.lock');
        $json_path = self::find_file('composer.json');

        if (null === $lock_path) {
            return [
                'packages' => [],
                'error'    => 'composer.lock not found or unreadable at expected path',
            ];
        }

        $lock_data = json_decode((string) file_get_contents($lock_path), true);

        if (!is_array($lock_data) || !isset($lock_data['packages']) || !is_array($lock_data['packages'])) {
            return [
                'packages' => [],
                'error'    => 'composer.lock could not be parsed (invalid JSON or missing packages[])',
            ];
        }

        $installer_paths = self::get_installer_paths($json_path);

        $packages = [];

        foreach ($lock_data['packages'] as $package) {
            if (!is_array($package) || empty($package['name']) || !self::is_relevant_type($package)) {
                continue;
            }

            $packages[] = self::build_entry($package, $installer_paths);
        }

        return ['packages' => $packages, 'error' => null];
    }

    /**
     * Fleet health only cares about things WordPress itself can run/update:
     * plugins, themes, must-use plugins, and core. Everything else in
     * composer.lock (PHP libraries, Composer plugins, transitive deps of
     * a wordpress-plugin package) is noise for this purpose — e.g. this
     * fleet's own lock file pulls in ~30 plain PHP libraries (Carbon,
     * Doctrine, etc.) that nobody here maintains directly and that aren't
     * installable/updatable as a WordPress unit.
     */
    private static function is_relevant_type(array $package): bool
    {
        return in_array($package['type'] ?? '', [
            'wordpress-plugin',
            'wordpress-theme',
            'wordpress-muplugin',
            'wordpress-core',
        ], true);
    }

    /**
     * Locates composer.lock/composer.json relative to the WordPress install
     * root. Bedrock's layout puts both at the repo root, one level above
     * ABSPATH (web/wp) and two above this plugin's own directory.
     */
    private static function find_file(string $filename): ?string
    {
        $candidates = [
            ABSPATH . '../../' . $filename,
            ABSPATH . '../' . $filename,
            ABSPATH . $filename,
        ];

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);

            if (false !== $real && is_readable($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Reads composer.json's extra.installer-paths so package->directory
     * matching (see build_entry) works the same way Composer's own
     * installer resolves it, rather than guessing a fixed path pattern.
     *
     * @return array<string, string> Map of composer `type` -> path template
     *   (e.g. 'wordpress-plugin' => 'web/app/plugins/{$name}/').
     */
    private static function get_installer_paths(?string $json_path): array
    {
        if (null === $json_path) {
            return [];
        }

        $json_data = json_decode((string) file_get_contents($json_path), true);
        $raw = $json_data['extra']['installer-paths'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $path_template => $types) {
            foreach ((array) $types as $type) {
                if (is_string($type) && str_starts_with($type, 'type:')) {
                    $map[substr($type, 5)] = $path_template;
                }
            }
        }

        return $map;
    }

    /**
     * Builds one composer[] response entry: name, constraint, resolved
     * version, git reference, type, installed flag — per the plan's API
     * wireframe example.
     */
    private static function build_entry(array $package, array $installer_paths): array
    {
        $name = (string) $package['name'];
        $type = (string) ($package['type'] ?? 'library');
        $reference = $package['source']['reference'] ?? ($package['dist']['reference'] ?? null);

        $entry = [
            'name'        => $name,
            'constraint'  => (string) ($package['version'] ?? ''),
            'version'     => (string) ($package['version'] ?? ''),
            'reference'   => $reference,
            'type'        => 'wordpress-core' === $type ? 'wordpress-core' : $type,
            'installed'   => true,
        ];

        // Wicket-authored git repos surface their source repository slug,
        // matching the worked example in the plan's API wireframe.
        if (self::is_wicket_git_package($name, $package)) {
            $source_url = $package['source']['url'] ?? '';
            $repo_slug = self::extract_repo_slug($source_url);

            if (null !== $repo_slug) {
                $entry['repository'] = $repo_slug;
            }
        }

        return $entry;
    }

    /** GitHub org confirmed as the sole trusted source for Wicket-authored git packages. */
    private const WICKET_GITHUB_ORG = 'industrialdev';

    /**
     * Wicket-authored private VCS packages: `wicket/*` / `industrialdev/*`
     * composer vendor namespace, or a git source resolving to the
     * industrialdev GitHub org specifically — not any git source, which
     * would wrongly count a third-party fork as Wicket-authored and
     * silently exempt it from wicket-fleet-monitor's update checks.
     */
    public static function is_wicket_git_package(string $name, array $package): bool
    {
        if (str_starts_with($name, 'wicket/') || str_starts_with($name, 'industrialdev/')) {
            return true;
        }

        $source_type = $package['source']['type'] ?? '';

        if ('git' !== $source_type) {
            return false;
        }

        $source_url = $package['source']['url'] ?? '';
        $repo_slug = self::extract_repo_slug((string) $source_url);

        return null !== $repo_slug && str_starts_with($repo_slug, self::WICKET_GITHUB_ORG . '/');
    }

    private static function extract_repo_slug(string $git_url): ?string
    {
        if (1 === preg_match('#github\.com[:/]([^/]+/[^/.]+)(?:\.git)?$#', $git_url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * updateSource, resolved per the plan's three-source order:
     * git (Wicket-authored) -> satispress (wicketpress/*) -> wordpress-org
     * (wp-plugin/* or wpackagist-plugin/* — both proxy wordpress.org;
     * this fleet's actual composer.json uses wp-plugin/* via
     * repo.wp-packages.org, not the wpackagist-plugin/* the plan
     * originally assumed, so both prefixes are matched here).
     */
    public static function resolve_update_source(string $composer_package_name, array $package): string
    {
        if (self::is_wicket_git_package($composer_package_name, $package)) {
            return 'git';
        }

        if (str_starts_with($composer_package_name, 'wicketpress/')) {
            return 'satispress';
        }

        if (str_starts_with($composer_package_name, 'wp-plugin/')
            || str_starts_with($composer_package_name, 'wpackagist-plugin/')
            || str_starts_with($composer_package_name, 'wpackagist-theme/')
        ) {
            return 'wordpress-org';
        }

        return 'unknown';
    }

    /**
     * Given a composer package name (e.g. 'wp-plugin/woocommerce') and its
     * `type`, returns the directory name Composer would have installed it
     * under (e.g. 'woocommerce') — the second path segment after the
     * vendor, same as Composer's own `{$name}` installer-path placeholder.
     */
    public static function package_directory_name(string $composer_package_name): string
    {
        $parts = explode('/', $composer_package_name, 2);

        return $parts[1] ?? $composer_package_name;
    }
}
