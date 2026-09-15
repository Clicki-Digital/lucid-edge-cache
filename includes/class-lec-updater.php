<?php

defined('ABSPATH') || exit;

/**
 * Supplies Lucid Edge Cache updates from published GitHub release assets.
 */
final class LEC_Updater {
    private const ASSET_NAME = 'lucid-edge-cache.zip';
    private const PLUGIN_SLUG = 'lucid-edge-cache';
    private const RELEASE_TRANSIENT = 'lec_github_release_v1';
    private const UPDATE_URI = 'https://clickidigital.com.au/plugins/lucid-edge-cache/';

    public static function boot(): void {
        add_filter('update_plugins_clickidigital.com.au', array(__CLASS__, 'update_response'), 10, 4);
        add_filter('plugins_api', array(__CLASS__, 'plugin_information'), 20, 3);
        add_filter('upgrader_pre_download', array(__CLASS__, 'verify_download'), 10, 4);
        add_filter('upgrader_source_selection', array(__CLASS__, 'verify_package_root'), 10, 4);
        add_action('upgrader_process_complete', array(__CLASS__, 'upgrade_complete'), 10, 2);
        add_action('delete_site_transient_update_plugins', array(__CLASS__, 'clear_release_cache'));
    }

    /**
     * Return update data in the format expected by WordPress 5.8 and newer.
     */
    public static function update_response($update, array $plugin_data, string $plugin_file, array $locales) {
        unset($locales);

        if ($plugin_file !== plugin_basename(LEC_FILE)) return $update;

        $release = self::release();
        if (is_wp_error($release)) return false;

        $installed = isset($plugin_data['Version']) ? (string) $plugin_data['Version'] : LEC_VERSION;
        if (version_compare($release['version'], $installed, '<=')) return false;

        return array(
            'id' => self::UPDATE_URI,
            'slug' => self::PLUGIN_SLUG,
            'version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['package'],
            'requires_php' => '8.0',
        );
    }

    /**
     * Populate the View details modal without querying WordPress.org.
     */
    public static function plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::PLUGIN_SLUG) return $result;

        $release = self::release();
        if (is_wp_error($release)) return $result;

        return (object) array(
            'name' => 'Lucid Edge Cache',
            'slug' => self::PLUGIN_SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://clickidigital.com.au/">Lucid Solutions</a>',
            'homepage' => self::UPDATE_URI,
            'requires' => '6.4',
            'requires_php' => '8.0',
            'download_link' => $release['package'],
            'last_updated' => $release['published_at'],
            'sections' => array(
                'description' => '<p>Safe full-page HTML caching with Varnish purging and optional DigitalOcean Spaces replication.</p>',
                'changelog' => '<p>' . nl2br(esc_html($release['notes'])) . '</p>',
            ),
        );
    }

    /**
     * Download our package ourselves so the GitHub digest can be checked before
     * WordPress extracts or installs any files.
     */
    public static function verify_download($reply, string $package, $upgrader, array $hook_extra) {
        unset($upgrader);

        if ($reply !== false || ($hook_extra['plugin'] ?? '') !== plugin_basename(LEC_FILE)) return $reply;

        $release = self::release();
        if (is_wp_error($release)) return $release;
        if (!hash_equals($release['package'], $package)) {
            return new WP_Error('lec_update_url_mismatch', 'Lucid Edge Cache refused an unexpected update package URL.');
        }

        if (!function_exists('download_url')) require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = download_url($package, 300);
        if (is_wp_error($file)) return $file;

        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($release['sha256'], strtolower($actual))) {
            @unlink($file);
            return new WP_Error('lec_update_checksum_failed', 'Lucid Edge Cache stopped the update because the downloaded package checksum did not match GitHub.');
        }

        return $file;
    }

    /**
     * Prevent an incorrectly packaged archive from replacing the plugin folder.
     */
    public static function verify_package_root($source, string $remote_source, $upgrader, array $hook_extra) {
        unset($remote_source, $upgrader);

        if (is_wp_error($source) || ($hook_extra['plugin'] ?? '') !== plugin_basename(LEC_FILE)) return $source;
        if (basename(rtrim((string) $source, '/\\')) !== self::PLUGIN_SLUG) {
            return new WP_Error('lec_update_package_root', 'Lucid Edge Cache stopped the update because the ZIP did not contain the expected lucid-edge-cache folder.');
        }

        return $source;
    }

    public static function upgrade_complete($upgrader, array $options): void {
        unset($upgrader);

        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') return;
        $plugins = isset($options['plugins']) ? (array) $options['plugins'] : array((string) ($options['plugin'] ?? ''));
        if (in_array(plugin_basename(LEC_FILE), $plugins, true)) delete_site_transient(self::RELEASE_TRANSIENT);
    }

    public static function clear_release_cache(): void {
        delete_site_transient(self::RELEASE_TRANSIENT);
    }

    public static function repository(): string {
        $repository = trim((string) apply_filters('lec_github_repository', LEC_GITHUB_REPOSITORY));
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) ? $repository : '';
    }

    /**
     * Fetch and validate the latest stable GitHub release, caching both success
     * and failure responses so a GitHub outage does not slow WordPress down.
     */
    private static function release() {
        $cached = get_site_transient(self::RELEASE_TRANSIENT);
        if (is_array($cached) && isset($cached['data'])) return $cached['data'];
        if (is_array($cached) && isset($cached['error'])) return new WP_Error('lec_github_cached_error', (string) $cached['error']);

        $repository = self::repository();
        if ($repository === '') return new WP_Error('lec_github_repository', 'The Lucid Edge Cache GitHub repository is not configured correctly.');

        list($owner, $repo) = explode('/', $repository, 2);
        $api_url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases/latest';
        $response = wp_safe_remote_get($api_url, array(
            'timeout' => 12,
            'redirection' => 3,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2026-03-10',
                'User-Agent' => 'Lucid-Edge-Cache/' . LEC_VERSION,
            ),
        ));

        if (is_wp_error($response)) return self::cache_error($response->get_error_message());
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return self::cache_error('GitHub did not return a published Lucid Edge Cache release.');
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($json) || !empty($json['draft']) || !empty($json['prerelease'])) {
            return self::cache_error('GitHub returned an invalid or non-stable release.');
        }

        $version = self::normalise_version((string) ($json['tag_name'] ?? ''));
        $asset = self::find_asset((array) ($json['assets'] ?? array()));
        if ($version === '' || $asset === null) return self::cache_error('The latest GitHub release does not follow the Lucid Edge Cache release contract.');

        $package = (string) ($asset['browser_download_url'] ?? '');
        $digest = strtolower((string) ($asset['digest'] ?? ''));
        $parts = wp_parse_url($package);
        $expected_path = '/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases/download/';
        if (($parts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || strpos((string) ($parts['path'] ?? ''), $expected_path) !== 0) {
            return self::cache_error('The GitHub release asset URL was not trusted.');
        }
        if (!preg_match('/^sha256:([a-f0-9]{64})$/', $digest, $matches)) {
            return self::cache_error('The GitHub release asset does not include a valid SHA-256 digest.');
        }

        $data = array(
            'version' => $version,
            'package' => $package,
            'sha256' => $matches[1],
            'html_url' => esc_url_raw((string) ($json['html_url'] ?? self::UPDATE_URI)),
            'published_at' => sanitize_text_field((string) ($json['published_at'] ?? '')),
            'notes' => (string) ($json['body'] ?? 'See the GitHub release for details.'),
        );
        set_site_transient(self::RELEASE_TRANSIENT, array('data' => $data), 6 * HOUR_IN_SECONDS);
        return $data;
    }

    private static function normalise_version(string $tag): string {
        return preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/', trim($tag), $matches) ? $matches[1] : '';
    }

    private static function find_asset(array $assets): ?array {
        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? '') !== self::ASSET_NAME || ($asset['state'] ?? '') !== 'uploaded') continue;
            $size = (int) ($asset['size'] ?? 0);
            if ($size > 0 && $size <= 20 * MB_IN_BYTES) return $asset;
        }
        return null;
    }

    private static function cache_error(string $message): WP_Error {
        $message = sanitize_text_field($message);
        set_site_transient(self::RELEASE_TRANSIENT, array('error' => $message), 15 * MINUTE_IN_SECONDS);
        return new WP_Error('lec_github_release', $message);
    }
}
