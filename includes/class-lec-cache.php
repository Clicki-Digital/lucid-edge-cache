<?php

defined('ABSPATH') || exit;

final class LEC_Cache {
    private static bool $capturing = false;
    private static array $handled_posts = array();
    private static array $old_urls = array();

    public static function boot(): void {
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade'), 2);
        add_action('template_redirect', array(__CLASS__, 'start_capture'), 0);
        add_action('send_headers', array(__CLASS__, 'miss_header'));
        add_action('save_post', array(__CLASS__, 'content_changed'), 20, 3);
        add_action('pre_post_update', array(__CLASS__, 'remember_old_url'), 10, 2);
        add_action('before_delete_post', array(__CLASS__, 'before_delete_post'), 10, 2);
        add_action('transition_post_status', array(__CLASS__, 'status_changed'), 20, 3);
        add_action('switch_theme', array(__CLASS__, 'purge_all'));
        add_action('customize_save_after', array(__CLASS__, 'purge_all'));
        add_action('wp_update_nav_menu', array(__CLASS__, 'purge_all'));
        add_action('activated_plugin', array(__CLASS__, 'purge_all'));
        add_action('deactivated_plugin', array(__CLASS__, 'purge_all'));
        add_action('updated_option', array(__CLASS__, 'option_changed'), 20, 3);
        add_action('created_term', array(__CLASS__, 'term_changed'), 20);
        add_action('edited_term', array(__CLASS__, 'term_changed'), 20);
        add_action('delete_term', array(__CLASS__, 'term_changed'), 20);
        add_action('lec_preload_batch', array(__CLASS__, 'preload_batch'));
        add_action('lec_daily_preload', array(__CLASS__, 'daily_preload'));
        add_action('lec_spaces_retry_batch', array('LEC_Spaces', 'retry_batch'));
        add_action('lec_cache_cleanup', array(__CLASS__, 'cleanup_expired'));
    }

    public static function activate(): void {
        wp_mkdir_p(LEC_CACHE_DIR . '/pages');
        $wp_cache = LEC_Config::ensure_wp_cache();
        if (is_wp_error($wp_cache)) update_option('lec_wp_cache_error', $wp_cache->get_error_message(), false);
        else delete_option('lec_wp_cache_error');
        self::write_runtime_config();
        self::install_dropin();
        if (!wp_next_scheduled('lec_cache_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'lec_cache_cleanup');
        self::sync_preload_schedule();
        update_option('lec_version', LEC_VERSION, false);
        if (!LEC_Config::is_managed()) set_transient('lec_activation_redirect', 1, 60);
    }

    public static function maybe_upgrade(): void {
        if (get_option('lec_version') === LEC_VERSION) return;
        wp_mkdir_p(LEC_CACHE_DIR . '/pages');
        $stored = (array) get_option('lec_settings', array());
        if (($stored['exclude_paths'] ?? '') === "/cart/\n/checkout/\n/my-account/\n/account/") $stored['exclude_paths'] = '/account/';
        if (($stored['exclude_cookies'] ?? '') === "woocommerce_items_in_cart\nwp_woocommerce_session\nedd_items_in_cart\nmemberpress") $stored['exclude_cookies'] = "edd_items_in_cart\nmemberpress";
        if ($stored) update_option('lec_settings', $stored, false);
        $wp_cache = LEC_Config::ensure_wp_cache();
        if (is_wp_error($wp_cache)) update_option('lec_wp_cache_error', $wp_cache->get_error_message(), false);
        else delete_option('lec_wp_cache_error');
        self::write_runtime_config();
        self::install_dropin();
        if (!wp_next_scheduled('lec_cache_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'lec_cache_cleanup');
        self::sync_preload_schedule();
        update_option('lec_version', LEC_VERSION, false);
        self::log('plugin_upgrade', 'success', LEC_VERSION);
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('lec_preload_batch');
        wp_clear_scheduled_hook('lec_daily_preload');
        wp_clear_scheduled_hook('lec_spaces_retry_batch');
        wp_clear_scheduled_hook('lec_cache_cleanup');
        self::remove_own_dropin();
    }

    private static function defaults(): array {
        return array(
            'enabled' => 1,
            'ttl' => 21600,
            'preload_schedule' => 'daily',
            'varnish_enabled' => 1,
            'varnish_url' => '',
            'flush_object_cache' => 0,
            'spaces_enabled' => 0,
            'spaces_region' => 'syd1',
            'spaces_bucket' => '',
            'spaces_prefix' => '',
            'spaces_cdn_url' => '',
            'do_cdn_id' => '',
            'exclude_paths' => '/account/',
            'exclude_cookies' => "edd_items_in_cart\nmemberpress",
        );
    }

    public static function settings(): array {
        $settings = wp_parse_args((array) get_option('lec_settings', array()), self::defaults());
        $constants = array(
            'spaces_bucket' => 'LEC_SPACES_BUCKET', 'spaces_region' => 'LEC_SPACES_REGION',
            'spaces_cdn_url' => 'LEC_SPACES_CDN_URL', 'varnish_url' => 'LEC_VARNISH_URL',
            'spaces_prefix' => 'LEC_SPACES_PREFIX', 'do_cdn_id' => 'LEC_DO_CDN_ID',
        );
        foreach ($constants as $key => $constant) if (defined($constant)) $settings[$key] = constant($constant);
        if (defined('LEC_SPACES_KEY') && LEC_SPACES_KEY !== '' && defined('LEC_SPACES_SECRET') && LEC_SPACES_SECRET !== '') $settings['spaces_enabled'] = 1;
        return $settings;
    }

    public static function write_runtime_config(?array $settings = null): bool {
        wp_mkdir_p(LEC_CACHE_DIR);
        $settings = $settings ?? self::settings();
        $config = array(
            'enabled' => !empty($settings['enabled']),
            'ttl' => max(60, (int) $settings['ttl']),
            'cache_dir' => LEC_CACHE_DIR . '/pages',
            'site_host' => self::site_host(),
            'protected_paths' => self::protected_paths(),
            'protected_cookies' => self::protected_cookies(),
            'exclude_paths' => self::setting_lines((string) ($settings['exclude_paths'] ?? '')),
            'exclude_cookies' => self::setting_lines((string) ($settings['exclude_cookies'] ?? '')),
        );
        $php = "<?php\n// Generated by Lucid Edge Cache.\nreturn " . var_export($config, true) . ";\n";
        $target = LEC_CACHE_DIR . '/config.php';
        $tmp = $target . '.' . wp_generate_password(8, false, false) . '.tmp';
        if (false === file_put_contents($tmp, $php, LOCK_EX)) return false;
        @chmod($tmp, 0640);
        if (!@rename($tmp, $target)) { @unlink($tmp); return false; }
        return true;
    }

    private static function install_dropin(): bool {
        $target = WP_CONTENT_DIR . '/advanced-cache.php';
        $source = LEC_DIR . 'dropins/advanced-cache.php';
        if (file_exists($target) && strpos((string) file_get_contents($target), 'LUCID_EDGE_CACHE_DROPIN') === false) {
            update_option('lec_dropin_conflict', 1, false);
            return false;
        }
        delete_option('lec_dropin_conflict');
        return copy($source, $target);
    }

    private static function remove_own_dropin(): void {
        $target = WP_CONTENT_DIR . '/advanced-cache.php';
        if (file_exists($target) && strpos((string) file_get_contents($target), 'LUCID_EDGE_CACHE_DROPIN') !== false) {
            unlink($target);
        }
    }

    public static function dropin_ok(): bool {
        $target = WP_CONTENT_DIR . '/advanced-cache.php';
        return file_exists($target) && strpos((string) file_get_contents($target), 'LUCID_EDGE_CACHE_DROPIN') !== false;
    }

    public static function cacheable_request(): bool {
        return self::bypass_reason() === '';
    }

    public static function bypass_reason(): string {
        if (is_admin()) return 'administration';
        if (is_user_logged_in()) return 'logged-in';
        if (wp_doing_ajax()) return 'ajax';
        if (wp_doing_cron()) return 'cron';
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', array('GET', 'HEAD'), true)) return 'request-method';
        if (!empty($_GET) && empty($_SERVER['HTTP_X_LUCID_CACHE_PRELOAD'])) return 'query-string';
        if (is_404()) return 'not-found';
        if (is_search()) return 'search';
        if (is_preview()) return 'preview';
        if (is_feed()) return 'feed';
        if (is_trackback()) return 'trackback';
        if (is_robots()) return 'robots';
        if (is_embed()) return 'embed';
        if (is_singular() && post_password_required()) return 'password-protected';
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (preg_match('#/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php)(/|$)#i', $uri)) return 'protected-path';
        $settings = self::settings();
        foreach (array_merge(self::protected_paths(), self::setting_lines((string) $settings['exclude_paths'])) as $excluded) {
            if ($excluded !== '' && stripos((string) parse_url($uri, PHP_URL_PATH), $excluded) !== false) return 'protected-path';
        }
        $cookies = strtolower(implode(';', array_keys($_COOKIE)));
        foreach (array_merge(self::protected_cookies(), self::setting_lines((string) $settings['exclude_cookies'])) as $excluded) {
            if ($excluded !== '' && stripos($cookies, strtolower($excluded)) !== false) return 'protected-cookie';
        }
        return '';
    }

    public static function protected_cookies(): array {
        return array(
            'wordpress_logged_in',
            'wordpress_sec',
            'wp-postpass',
            'comment_author',
            'woocommerce_cart_hash',
            'woocommerce_items_in_cart',
            'wp_woocommerce_session_',
            'woocommerce_recently_viewed',
            'store_notice',
        );
    }

    public static function protected_paths(): array {
        $paths = array('/wp-admin/', '/wp-login.php', '/wp-json/', '/xmlrpc.php');
        if (function_exists('wc_get_page_permalink')) {
            foreach (array('cart', 'checkout', 'myaccount') as $page) {
                $url = wc_get_page_permalink($page);
                $path = is_string($url) ? (string) wp_parse_url($url, PHP_URL_PATH) : '';
                if ($path !== '') $paths[] = trailingslashit($path);
            }
        }
        return array_values(array_unique($paths));
    }

    public static function safety_rules(): array {
        return array(
            'Sessions' => 'Logged-in users; wordpress_logged_in_* and wordpress_sec_* cookies',
            'Request methods' => 'Only anonymous GET and HEAD requests can be cached',
            'Dynamic requests' => 'Query strings, AJAX, cron, REST, login and administration requests',
            'WordPress views' => 'Previews, search, feeds, embeds, trackbacks, robots and 404 responses',
            'Private content' => 'Password-protected posts, wp-postpass_* and comment_author_* cookies, and responses that set cookies',
            'Response safety' => 'Only non-empty HTTP 200 HTML responses are stored',
            'WooCommerce pages' => function_exists('wc_get_page_permalink') ? 'Cart, Checkout and My Account: ' . implode(', ', array_slice(self::protected_paths(), 4)) : 'Automatically detected when WooCommerce is active',
            'WooCommerce state' => implode(', ', array_slice(self::protected_cookies(), 4)),
        );
    }

    public static function start_capture(): void {
        if (!self::cacheable_request()) return;
        self::$capturing = true;
        ob_start(array(__CLASS__, 'store_response'));
    }

    public static function miss_header(): void {
        if (headers_sent()) return;
        $reason = self::bypass_reason();
        if ($reason === '') header('X-Lucid-Cache: MISS');
        else self::send_bypass_header($reason);
    }

    public static function store_response(string $html): string {
        if (!self::$capturing) { self::release_generation_lock(); return $html; }
        if (http_response_code() !== 200) { self::send_bypass_header('response-status'); self::release_generation_lock(); return $html; }
        if (trim($html) === '') { self::send_bypass_header('empty-response'); self::release_generation_lock(); return $html; }
        $headers = headers_list();
        foreach ($headers as $header) {
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false) { self::send_bypass_header('response-type'); self::release_generation_lock(); return $html; }
            if (stripos($header, 'set-cookie:') === 0) { self::send_bypass_header('response-cookie'); self::release_generation_lock(); return $html; }
        }
        $relative = self::relative_cache_path(self::site_host(), (string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $path = LEC_CACHE_DIR . '/pages/' . $relative;
        wp_mkdir_p(dirname($path));
        $tmp = $path . '.' . wp_generate_password(8, false) . '.tmp';
        if (false !== file_put_contents($tmp, $html, LOCK_EX)) {
            @chmod($tmp, 0640);
            if (!@rename($tmp, $path)) { @unlink($tmp); self::log('local_write', 'error', $relative); self::release_generation_lock(); return $html; }
            self::log('local_write', 'success', $relative);
            $settings = self::settings();
            if (!empty($settings['spaces_enabled'])) {
                if (!LEC_Spaces::upload_html($relative, $html, $settings)) LEC_Spaces::queue_retry('upload', $relative, $html);
            }
        }
        self::release_generation_lock();
        return $html;
    }

    private static function release_generation_lock(): void {
        $handle = $GLOBALS['lec_cache_lock_handle'] ?? null;
        $path = (string) ($GLOBALS['lec_cache_lock_path'] ?? '');
        if (is_resource($handle)) { @flock($handle, LOCK_UN); @fclose($handle); }
        if ($path !== '' && is_file($path)) @unlink($path);
        unset($GLOBALS['lec_cache_lock_handle'], $GLOBALS['lec_cache_lock_path']);
    }

    private static function send_bypass_header(string $reason): void {
        if (headers_sent()) return;
        header('X-Lucid-Cache: BYPASS');
        header('X-Lucid-Cache-Reason: ' . sanitize_key($reason));
    }

    private static function relative_cache_path(string $host, string $uri): string {
        $host = preg_replace('/[^a-z0-9.-]/i', '_', strtolower($host));
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $path = trim(preg_replace('#/+#', '/', $path), '/');
        if ($path === '') return $host . '/index.html';
        $segments = array_map(static function ($part) {
            $part = preg_replace('/[^A-Za-z0-9_.-]/', '-', $part);
            return trim((string) $part, '-');
        }, explode('/', $path));
        return $host . '/' . implode('/', array_filter($segments, 'strlen')) . '/index.html';
    }

    public static function site_host(): string {
        return strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
    }

    public static function content_changed(int $post_id, WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ($post->post_status !== 'publish' || isset(self::$handled_posts[$post_id])) return;
        self::$handled_posts[$post_id] = true;
        $new_url = get_permalink($post);
        if (!empty(self::$old_urls[$post_id]) && self::$old_urls[$post_id] !== $new_url) self::remove_url(self::$old_urls[$post_id], true);
        self::invalidate_urls(self::related_urls($post), 'content_change');
    }

    public static function remember_old_url(int $post_id, array $data): void {
        $old = get_permalink($post_id);
        if ($old) self::$old_urls[$post_id] = $old;
    }

    public static function status_changed(string $new, string $old, WP_Post $post): void {
        if ($new === $old || $new !== 'publish' || isset(self::$handled_posts[$post->ID])) return;
        self::$handled_posts[$post->ID] = true;
        self::invalidate_urls(self::related_urls($post), 'status_publish');
    }

    public static function before_delete_post(int $post_id, WP_Post $post): void {
        $url = get_permalink($post);
        if ($url) self::remove_url($url, true);
        self::invalidate_urls(array_filter(array(home_url('/'), get_post_type_archive_link($post->post_type))), 'content_delete');
    }

    public static function option_changed(string $option, $old, $value): void {
        if ($old === $value || strpos($option, 'lec_') === 0) return;
        $global = array('blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'permalink_structure', 'sidebars_widgets', 'widget_block', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'theme_mods_' . get_option('stylesheet'));
        if (in_array($option, $global, true) || strpos($option, 'widget_') === 0) {
            self::write_runtime_config();
            self::purge_all('option_' . sanitize_key($option));
        }
    }

    public static function term_changed(): void {
        self::purge_all('taxonomy_change');
    }

    public static function purge_all($source = 'manual'): void {
        self::delete_tree(LEC_CACHE_DIR . '/pages', true);
        wp_mkdir_p(LEC_CACHE_DIR . '/pages');
        self::log('local_purge', 'success', is_scalar($source) ? (string) $source : 'hook');
        $settings = self::settings();
        if (!empty($settings['varnish_enabled'])) self::purge_varnish($settings);
        if (!empty($settings['flush_object_cache']) && wp_using_ext_object_cache()) wp_cache_flush();
        if (!empty($settings['spaces_enabled'])) LEC_Spaces::purge_cdn($settings, array('*'));
        do_action('lec_cache_purged');
    }

    private static function delete_tree(string $dir, bool $remove_root = false): void {
        if (!is_dir($dir)) return;
        $items = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) self::delete_tree($item->getPathname(), true);
            else @unlink($item->getPathname());
        }
        if ($remove_root) @rmdir($dir);
    }

    private static function purge_varnish(array $settings): bool {
        $url = trim((string) $settings['varnish_url']);
        if ($url === '') $url = home_url('/');
        $home_host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        $response = wp_remote_request($url, array(
            'method' => 'PURGE', 'timeout' => 8, 'blocking' => true,
            'headers' => array('Host' => $home_host, 'X-Purge-Method' => 'regex', 'X-Lucid-Purge' => 'all'),
        ));
        if (is_wp_error($response)) {
            self::log('varnish_purge', 'error', $response->get_error_message());
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        self::log('varnish_purge', 'http_' . $code, $url);
        return $code >= 200 && $code < 300;
    }

    public static function test_varnish(): bool {
        $settings = self::settings();
        if (empty($settings['varnish_enabled'])) {
            self::log('varnish_purge', 'disabled', 'Enable Varnish purging first');
            return false;
        }
        return self::purge_varnish($settings);
    }

    public static function test_varnish_endpoint(string $url): bool {
        if (!self::valid_varnish_endpoint($url)) {
            self::log('varnish_purge', 'invalid_endpoint', 'Endpoint rejected');
            return false;
        }
        return self::purge_varnish(array('varnish_url' => $url));
    }

    public static function valid_varnish_endpoint(string $url): bool {
        if ($url === '') return true;
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true)) return false;
        if (!empty($parts['user']) || !empty($parts['pass'])) return false;
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $home = strtolower(rtrim((string) parse_url(home_url('/'), PHP_URL_HOST), '.'));
        return $host === $home || in_array($host, array('localhost', '127.0.0.1', '::1'), true);
    }

    private static function purge_varnish_urls(array $urls, array $settings): void {
        $endpoint = trim((string) $settings['varnish_url']);
        $home_host = (string) parse_url(home_url('/'), PHP_URL_HOST);
        foreach ($urls as $url) {
            $target = $url;
            if ($endpoint !== '') {
                $target = rtrim($endpoint, '/') . '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            }
            $response = wp_remote_request($target, array(
                'method' => 'PURGE', 'timeout' => 8, 'blocking' => true,
                'headers' => array('Host' => $home_host, 'X-Lucid-Purge' => 'url'),
            ));
            if (is_wp_error($response)) self::log('varnish_url_purge', 'error', $url . ' — ' . $response->get_error_message());
            else self::log('varnish_url_purge', 'http_' . wp_remote_retrieve_response_code($response), $url);
        }
    }

    public static function queue_preload(): int {
        $ids = get_posts(array('post_type' => 'any', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID'));
        $urls = array(home_url('/'));
        foreach ($ids as $id) {
            $url = get_permalink($id);
            if ($url) $urls[] = $url;
        }
        $urls = array_values(array_unique(array_filter($urls)));
        self::enqueue_urls($urls);
        self::log('preload_queue', 'queued', count($urls) . ' URLs');
        return count($urls);
    }

    public static function daily_preload(): void {
        $settings = self::settings();
        if (!empty($settings['enabled']) && ($settings['preload_schedule'] ?? 'daily') === 'daily') {
            $count = self::queue_preload();
            update_option('lec_last_automatic_preload', array('time' => time(), 'count' => $count), false);
            self::log('automatic_preload', 'queued', $count . ' URLs');
        }
        self::sync_preload_schedule();
    }

    public static function sync_preload_schedule(?array $settings = null): void {
        $settings = wp_parse_args($settings ?? self::settings(), self::defaults());
        wp_clear_scheduled_hook('lec_daily_preload');
        if (empty($settings['enabled']) || ($settings['preload_schedule'] ?? 'daily') !== 'daily') return;
        wp_schedule_single_event(self::next_preload_timestamp(), 'lec_daily_preload');
    }

    private static function next_preload_timestamp(): int {
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $minute_offset = abs((int) crc32(self::site_host())) % 91;
        $next = $now->setTime(4, 0)->modify('+' . $minute_offset . ' minutes');
        if ($next <= $now) $next = $next->modify('+1 day');
        return $next->getTimestamp();
    }

    public static function preload_batch(): void {
        if (get_transient('lec_preload_lock')) return;
        set_transient('lec_preload_lock', 1, 2 * MINUTE_IN_SECONDS);
        $queue = (array) get_option('lec_preload_queue', array());
        $batch = array_splice($queue, 0, 5);
        foreach ($batch as $item) {
            $item = is_array($item) ? $item : array('url' => (string) $item, 'attempts' => 0);
            $url = (string) ($item['url'] ?? '');
            if (!$url) continue;
            $busted = add_query_arg('lec_preload', rawurlencode(wp_generate_uuid4()), $url);
            $response = wp_remote_get($busted, array('timeout' => 20, 'redirection' => 3, 'headers' => array('X-Lucid-Cache-Preload' => '1', 'Cache-Control' => 'no-cache, no-store')));
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 400) {
                $item['attempts'] = (int) ($item['attempts'] ?? 0) + 1;
                if ($item['attempts'] <= 2) $queue[] = $item;
                self::log('preload', is_wp_error($response) ? 'error' : 'http_' . $code, $url . ' — attempt ' . $item['attempts']);
            } else self::log('preload', 'http_' . $code, $url);
        }
        update_option('lec_preload_queue', $queue, false);
        delete_transient('lec_preload_lock');
        if ($queue) wp_schedule_single_event(time() + 10, 'lec_preload_batch');
    }

    public static function cache_stats(): array {
        $count = 0; $bytes = 0;
        $dir = LEC_CACHE_DIR . '/pages';
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) if ($file->isFile() && $file->getExtension() === 'html') { $count++; $bytes += $file->getSize(); }
        }
        return array('count' => $count, 'bytes' => $bytes);
    }

    public static function cleanup_expired(): int {
        $dir = LEC_CACHE_DIR . '/pages';
        if (!is_dir($dir)) return 0;
        $ttl = max(60, (int) self::settings()['ttl']);
        $removed = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            if ($item->isFile() && ($item->getExtension() === 'html' || $item->getExtension() === 'lock') && ($item->getMTime() + $ttl) < time()) {
                if (@unlink($item->getPathname())) $removed++;
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
        self::log('cache_cleanup', 'success', $removed . ' expired file(s) removed');
        return $removed;
    }

    public static function inspect_url(string $url): array {
        $home_host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        $parts = wp_parse_url($url);
        if (!wp_http_validate_url($url) || !is_array($parts) || !empty($parts['user']) || !empty($parts['pass']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true) || strtolower((string) ($parts['host'] ?? '')) !== $home_host) return array('valid' => false, 'message' => 'Enter a valid URL from this WordPress site.');
        $settings = self::settings();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $excluded = '';
        foreach (array_merge(self::protected_paths(), self::setting_lines((string) $settings['exclude_paths'])) as $rule) {
            if ($rule !== '' && stripos($path, $rule) !== false) { $excluded = $rule; break; }
        }
        $relative = self::relative_cache_path(self::site_host(), $path);
        $file = LEC_CACHE_DIR . '/pages/' . $relative;
        $exists = is_file($file);
        $age = $exists ? max(0, time() - (int) filemtime($file)) : 0;
        $ttl = max(60, (int) $settings['ttl']);
        $queue = (array) get_option('lec_preload_queue', array());
        $queued = false;
        foreach ($queue as $item) {
            $queued_url = is_array($item) ? (string) ($item['url'] ?? '') : (string) $item;
            if (untrailingslashit($queued_url) === untrailingslashit($url)) { $queued = true; break; }
        }
        return array(
            'valid' => true,
            'url' => $url,
            'status' => $excluded !== '' ? 'Always bypassed' : ($exists ? ($age <= $ttl ? 'Cached' : 'Expired') : 'Not cached'),
            'reason' => $excluded !== '' ? 'Matches protected path rule: ' . $excluded : 'No path exclusion matched; cookies and response headers are request-dependent.',
            'local_file' => $exists ? 'Present' : 'Absent',
            'age' => $exists ? human_time_diff((int) filemtime($file), time()) : '—',
            'queued' => $queued ? 'Yes' : 'No',
            'spaces_key' => trim((string) ($settings['spaces_prefix'] ?? ''), '/') . (empty($settings['spaces_prefix']) ? '' : '/') . $relative,
        );
    }

    public static function set_enabled(bool $enabled): void {
        $settings = (array) get_option('lec_settings', array());
        $settings['enabled'] = $enabled ? 1 : 0;
        update_option('lec_settings', $settings, false);
        self::write_runtime_config(self::settings());
        self::sync_preload_schedule(self::settings());
        if (!$enabled) {
            self::delete_tree(LEC_CACHE_DIR . '/pages', true);
            wp_mkdir_p(LEC_CACHE_DIR . '/pages');
        }
        self::log('cache_state', $enabled ? 'enabled' : 'disabled', 'Administrator action');
    }

    public static function diagnostic_report(): array {
        $settings = self::settings();
        return array(
            'generated_at' => gmdate('c'),
            'site_host' => self::site_host(),
            'plugin_version' => LEC_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'health' => self::health(),
            'policy' => array('enabled' => !empty($settings['enabled']), 'ttl' => (int) $settings['ttl'], 'preload_schedule' => (string) ($settings['preload_schedule'] ?? 'daily'), 'additional_paths' => self::setting_lines((string) $settings['exclude_paths']), 'additional_cookies' => self::setting_lines((string) $settings['exclude_cookies'])),
            'infrastructure' => array('varnish_enabled' => !empty($settings['varnish_enabled']), 'object_cache' => wp_using_ext_object_cache(), 'spaces_enabled' => !empty($settings['spaces_enabled']), 'spaces_region' => (string) $settings['spaces_region'], 'spaces_bucket' => (string) $settings['spaces_bucket'], 'namespace' => self::site_host() . '/'),
            'recent_activity' => self::activity(),
        );
    }

    public static function queue_url(string $url): bool {
        $home_host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        if (!wp_http_validate_url($url) || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $home_host) return false;
        self::enqueue_urls(array($url));
        self::remove_url($url, false);
        self::log('preload_queue', 'queued', $url);
        return true;
    }

    public static function health(): array {
        $settings = self::settings();
        $stats = self::cache_stats();
        $queue = (array) get_option('lec_preload_queue', array());
        $retries = (array) get_option('lec_spaces_retry_queue', array());
        $last = (array) get_option('lec_last_system_test', array());
        $last_preload = (array) get_option('lec_last_automatic_preload', array());
        $next_preload = wp_next_scheduled('lec_daily_preload');
        $automatic_preload = !empty($settings['enabled']) && ($settings['preload_schedule'] ?? 'daily') === 'daily';
        return array(
            'Plugin version' => LEC_VERSION,
            'Update source' => class_exists('LEC_Updater') ? 'GitHub — ' . LEC_Updater::repository() : 'Unavailable',
            'Page cache' => !empty($settings['enabled']) ? 'Enabled' : 'Disabled',
            'Drop-in' => self::dropin_ok() ? 'Installed' : 'Unavailable',
            'Cache directory' => is_dir(LEC_CACHE_DIR . '/pages') && is_writable(LEC_CACHE_DIR . '/pages') ? 'Writable' : 'Not writable',
            'Cached pages' => (string) $stats['count'],
            'Varnish' => empty($settings['varnish_enabled']) ? 'Disabled' : 'Enabled',
            'Object cache' => wp_using_ext_object_cache() ? 'Persistent' : 'WordPress default',
            'Spaces' => empty($settings['spaces_enabled']) ? 'Disabled' : 'Enabled',
            'Namespace' => self::site_host() . '/',
            'Pending regeneration' => (string) count($queue),
            'Pending Spaces retries' => (string) count($retries),
            'Automatic preload' => $automatic_preload ? 'Daily — staggered between 04:00 and 05:30' : 'Disabled',
            'Next automatic preload' => $automatic_preload && $next_preload ? wp_date('Y-m-d H:i:s', (int) $next_preload) : 'Not scheduled',
            'Last automatic preload' => empty($last_preload['time']) ? 'Not run' : wp_date('Y-m-d H:i:s', (int) $last_preload['time']) . ' — ' . absint($last_preload['count'] ?? 0) . ' URLs queued',
            'Next expired-file cleanup' => wp_next_scheduled('lec_cache_cleanup') ? wp_date('Y-m-d H:i:s', (int) wp_next_scheduled('lec_cache_cleanup')) : 'Not scheduled',
            'Last system test' => empty($last['time']) ? 'Not run' : wp_date('Y-m-d H:i:s', (int) $last['time']) . ' — ' . sanitize_text_field((string) ($last['summary'] ?? '')),
        );
    }

    public static function run_system_test(): array {
        $settings = self::settings();
        $results = array(
            'runtime_config' => self::write_runtime_config($settings),
            'dropin' => self::dropin_ok(),
            'cache_directory' => is_dir(LEC_CACHE_DIR . '/pages') && is_writable(LEC_CACHE_DIR . '/pages'),
            'varnish' => empty($settings['varnish_enabled']) ? null : self::test_varnish(),
            'spaces' => empty($settings['spaces_enabled']) ? null : LEC_Spaces::test($settings),
        );
        $failed = count(array_filter($results, static function ($value) { return $value === false; }));
        update_option('lec_last_system_test', array('time' => time(), 'summary' => $failed ? $failed . ' check(s) failed' : 'All enabled checks passed', 'results' => $results), false);
        self::log('system_test', $failed ? 'warning' : 'success', $failed ? $failed . ' check(s) failed' : 'All enabled checks passed');
        return $results;
    }

    private static function remove_url(string $url, bool $delete_remote): void {
        $relative = self::relative_cache_path(self::site_host(), (string) parse_url($url, PHP_URL_PATH));
        $file = LEC_CACHE_DIR . '/pages/' . $relative;
        if (is_file($file)) @unlink($file);
        if ($delete_remote) {
            $settings = self::settings();
            if (!empty($settings['spaces_enabled']) && !LEC_Spaces::delete_html($relative, $settings)) LEC_Spaces::queue_retry('delete', $relative);
        }
    }

    private static function invalidate_urls(array $urls, string $source): void {
        $urls = array_values(array_unique(array_filter($urls)));
        foreach ($urls as $url) self::remove_url($url, false);
        $settings = self::settings();
        if (!empty($settings['varnish_enabled'])) self::purge_varnish_urls($urls, $settings);
        self::enqueue_urls($urls);
        self::log('selective_invalidation', 'success', $source . ' — ' . count($urls) . ' URLs');
    }

    private static function related_urls(WP_Post $post): array {
        $urls = array(home_url('/'), get_permalink($post));
        $per_page = max(1, (int) get_option('posts_per_page', 10));
        $counts = wp_count_posts($post->post_type);
        $published = isset($counts->publish) ? (int) $counts->publish : 0;
        $posts_page = (int) get_option('page_for_posts');
        if ($posts_page) $urls = array_merge($urls, self::paginated_urls(get_permalink($posts_page), $published, $per_page));
        else $urls = array_merge($urls, self::paginated_urls(home_url('/'), $published, $per_page));
        $archive = get_post_type_archive_link($post->post_type);
        if ($archive) $urls = array_merge($urls, self::paginated_urls($archive, $published, $per_page));
        $author = get_author_posts_url((int) $post->post_author);
        if ($author) $urls = array_merge($urls, self::paginated_urls($author, count_user_posts((int) $post->post_author, $post->post_type, true), $per_page));
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $terms = get_the_terms($post, $taxonomy);
            if (is_array($terms)) foreach ($terms as $term) {
                $link = get_term_link($term);
                if (!is_wp_error($link)) $urls = array_merge($urls, self::paginated_urls($link, (int) $term->count, $per_page));
            }
        }
        if ($post->post_type === 'page') {
            foreach (get_post_ancestors($post) as $parent_id) $urls[] = get_permalink($parent_id);
        }
        return array_values(array_unique(array_filter($urls)));
    }

    private static function paginated_urls(string $base, int $items, int $per_page): array {
        if ($base === '') return array();
        $urls = array($base);
        $pages = min(250, max(1, (int) ceil($items / max(1, $per_page))) + 1);
        for ($page = 2; $page <= $pages; $page++) $urls[] = trailingslashit($base) . user_trailingslashit('page/' . $page, 'paged');
        return $urls;
    }

    private static function enqueue_urls(array $urls): void {
        $queue = (array) get_option('lec_preload_queue', array());
        $by_url = array();
        foreach ($queue as $item) {
            $item = is_array($item) ? $item : array('url' => (string) $item, 'attempts' => 0);
            if (!empty($item['url'])) $by_url[$item['url']] = $item;
        }
        foreach ($urls as $url) if ($url) $by_url[$url] = array('url' => $url, 'attempts' => 0);
        update_option('lec_preload_queue', array_values($by_url), false);
        if (!wp_next_scheduled('lec_preload_batch')) wp_schedule_single_event(time() + 2, 'lec_preload_batch');
    }

    private static function setting_lines(string $value): array {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value))));
    }

    public static function log(string $event, string $status, string $detail = ''): void {
        $detail = self::normalise_log_detail($detail);
        $log = (array) get_option('lec_activity', array());
        array_unshift($log, array('time' => time(), 'event' => sanitize_key($event), 'status' => sanitize_text_field($status), 'detail' => sanitize_text_field($detail)));
        update_option('lec_activity', array_slice($log, 0, 30), false);
    }

    public static function activity(): array {
        return (array) get_option('lec_activity', array());
    }

    private static function normalise_log_detail(string $detail): string {
        $home_host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        return (string) preg_replace_callback('#https?://[^\s]+#i', static function (array $match) use ($home_host): string {
            $url = rtrim((string) $match[0], '.,;:)');
            $suffix = substr((string) $match[0], strlen($url));
            $parts = wp_parse_url($url);
            if (!is_array($parts) || strtolower((string) ($parts['host'] ?? '')) !== $home_host) return '[External URL]' . $suffix;
            $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
            return $path . $suffix;
        }, $detail);
    }
}
