<?php

defined('ABSPATH') || exit;

final class LEC_Admin {
    public static function boot(): void {
        add_action('admin_init', array(__CLASS__, 'ensure_prerequisites'), 0);
        add_action('admin_init', array(__CLASS__, 'maybe_redirect_setup'), 1);
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
        add_action('update_option_lec_settings', array(__CLASS__, 'settings_saved'), 10, 2);
        add_action('admin_notices', array(__CLASS__, 'notices'));
        add_action('admin_post_lec_purge', array(__CLASS__, 'purge'));
        add_action('admin_post_lec_preload', array(__CLASS__, 'preload'));
        add_action('admin_post_lec_regenerate_url', array(__CLASS__, 'regenerate_url'));
        add_action('admin_post_lec_test_spaces', array(__CLASS__, 'test_spaces'));
        add_action('admin_post_lec_test_varnish', array(__CLASS__, 'test_varnish'));
        add_action('admin_post_lec_system_test', array(__CLASS__, 'system_test'));
        add_action('admin_post_lec_cache_state', array(__CLASS__, 'cache_state'));
        add_action('admin_post_lec_queue_action', array(__CLASS__, 'queue_action'));
        add_action('admin_post_lec_inspect_url', array(__CLASS__, 'inspect_url'));
        add_action('admin_post_lec_diagnostics', array(__CLASS__, 'diagnostics'));
        add_action('admin_post_lec_onboard', array(__CLASS__, 'onboard'));
    }

    public static function ensure_prerequisites(): void {
        if (defined('WP_CACHE') && WP_CACHE) {
            delete_option('lec_wp_cache_error');
            return;
        }
        $result = LEC_Config::ensure_wp_cache();
        if (is_wp_error($result)) update_option('lec_wp_cache_error', $result->get_error_message(), false);
        else delete_option('lec_wp_cache_error');
    }

    public static function maybe_redirect_setup(): void {
        if (isset($_GET['page']) && $_GET['page'] === 'lucid-edge-cache' && !LEC_Config::is_configured()) {
            wp_safe_redirect(admin_url('options-general.php?page=lucid-edge-cache-setup')); exit;
        }
        if (isset($_GET['page']) && $_GET['page'] === 'lucid-edge-cache-setup' && LEC_Config::is_locked()) {
            wp_safe_redirect(add_query_arg('lec_notice', 'already_configured', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
        }
        if (!get_transient('lec_activation_redirect')) return;
        delete_transient('lec_activation_redirect');
        if (!current_user_can('manage_options') || wp_doing_ajax() || isset($_GET['activate-multi'])) return;
        wp_safe_redirect(admin_url('options-general.php?page=lucid-edge-cache-setup')); exit;
    }

    public static function menu(): void {
        if (!LEC_Config::is_configured()) {
            add_options_page('Lucid Edge Cache Setup', 'Lucid Edge Cache', 'manage_options', 'lucid-edge-cache-setup', array(__CLASS__, 'setup_page'));
            return;
        }
        add_options_page('Lucid Edge Cache', 'Lucid Edge Cache', 'manage_options', 'lucid-edge-cache', array(__CLASS__, 'page'));
        if (!LEC_Config::is_locked()) {
            add_submenu_page('options-general.php', 'Lucid Edge Cache Setup', 'Lucid Cache Setup', 'manage_options', 'lucid-edge-cache-setup', array(__CLASS__, 'setup_page'));
        }
    }

    public static function register(): void {
        register_setting('lec', 'lec_settings', array('sanitize_callback' => array(__CLASS__, 'sanitize')));
    }

    public static function sanitize($input): array {
        $input = is_array($input) ? $input : array();
        $existing = LEC_Cache::settings();
        $clean = array(
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'ttl' => max(60, min(DAY_IN_SECONDS * 7, absint($input['ttl'] ?? 21600))),
            'varnish_enabled' => empty($input['varnish_enabled']) ? 0 : 1,
            'varnish_url' => esc_url_raw($input['varnish_url'] ?? ''),
            'flush_object_cache' => empty($input['flush_object_cache']) ? 0 : 1,
            'spaces_enabled' => empty($input['spaces_enabled']) ? 0 : 1,
            'spaces_region' => sanitize_key($input['spaces_region'] ?? 'syd1'),
            'spaces_bucket' => sanitize_text_field($input['spaces_bucket'] ?? ''),
            'spaces_prefix' => trim(sanitize_text_field($input['spaces_prefix'] ?? ''), '/'),
            'spaces_cdn_url' => esc_url_raw($input['spaces_cdn_url'] ?? ''),
            'do_cdn_id' => sanitize_text_field($input['do_cdn_id'] ?? ''),
            'exclude_paths' => sanitize_textarea_field($input['exclude_paths'] ?? ''),
            'exclude_cookies' => sanitize_textarea_field($input['exclude_cookies'] ?? ''),
        );
        if (!LEC_Cache::valid_varnish_endpoint($clean['varnish_url'])) {
            add_settings_error('lec_settings', 'lec_varnish_endpoint', 'The Varnish endpoint must use HTTP(S) and target localhost or this site hostname.');
            $clean['varnish_url'] = $existing['varnish_url'];
        }
        if (LEC_Config::is_managed() || (defined('LEC_LOCK_SETTINGS') && LEC_LOCK_SETTINGS)) {
            foreach (array('varnish_enabled', 'varnish_url', 'spaces_enabled', 'spaces_region', 'spaces_bucket', 'spaces_prefix', 'spaces_cdn_url', 'do_cdn_id') as $key) $clean[$key] = $existing[$key];
        }
        return $clean;
    }

    public static function settings_saved($old, $value): void {
        LEC_Cache::write_runtime_config((array) $value);
        if ($old !== $value) LEC_Cache::purge_all();
    }

    public static function notices(): void {
        if (!current_user_can('manage_options')) return;
        $wp_cache_error = get_option('lec_wp_cache_error');
        if ((!defined('WP_CACHE') || !WP_CACHE) && $wp_cache_error) echo '<div class="notice notice-error"><p><strong>Lucid Edge Cache:</strong> ' . esc_html((string) $wp_cache_error) . ' Automatic configuration was not possible.</p></div>';
        if (get_option('lec_dropin_conflict')) echo '<div class="notice notice-error"><p><strong>Lucid Edge Cache:</strong> another <code>advanced-cache.php</code> drop-in exists. Remove/deactivate the previous page-cache plugin, then deactivate and reactivate Lucid Edge Cache.</p></div>';
        if (is_multisite()) echo '<div class="notice notice-warning"><p><strong>Lucid Edge Cache:</strong> WordPress multisite has not yet been certified for v1. Continue only on a staging network.</p></div>';
    }

    public static function purge(): void {
        check_admin_referer('lec_purge'); if (!current_user_can('manage_options')) wp_die('Forbidden');
        LEC_Cache::purge_all();
        wp_safe_redirect(add_query_arg('lec_notice', 'purged', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    public static function preload(): void {
        check_admin_referer('lec_preload'); if (!current_user_can('manage_options')) wp_die('Forbidden');
        $count = LEC_Cache::queue_preload();
        wp_safe_redirect(add_query_arg(array('lec_notice' => 'queued', 'count' => $count), admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    public static function regenerate_url(): void {
        check_admin_referer('lec_regenerate_url'); if (!current_user_can('manage_options')) wp_die('Forbidden');
        $url = esc_url_raw(wp_unslash($_POST['lec_url'] ?? ''));
        $ok = LEC_Cache::queue_url($url);
        wp_safe_redirect(add_query_arg('lec_notice', $ok ? 'url_queued' : 'invalid_url', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    public static function test_spaces(): void {
        check_admin_referer('lec_test_spaces'); if (!current_user_can('manage_options')) wp_die('Forbidden');
        $ok = LEC_Spaces::test(LEC_Cache::settings());
        wp_safe_redirect(add_query_arg('lec_notice', $ok ? 'spaces_ok' : 'spaces_failed', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    public static function test_varnish(): void {
        check_admin_referer('lec_test_varnish'); if (!current_user_can('manage_options')) wp_die('Forbidden');
        LEC_Cache::test_varnish();
        wp_safe_redirect(add_query_arg('lec_notice', 'varnish_tested', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    public static function system_test(): void {
        check_admin_referer('lec_system_test');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        LEC_Cache::run_system_test();
        wp_safe_redirect(add_query_arg('lec_notice', 'system_tested', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    public static function cache_state(): void {
        check_admin_referer('lec_cache_state');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $enabled = ($_GET['state'] ?? '') === 'enable';
        LEC_Cache::set_enabled($enabled);
        wp_safe_redirect(add_query_arg('lec_notice', $enabled ? 'cache_enabled' : 'cache_disabled', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    public static function queue_action(): void {
        check_admin_referer('lec_queue_action');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $operation = sanitize_key(wp_unslash($_GET['operation'] ?? ''));
        if (!in_array($operation, array('retry', 'clear'), true)) wp_die('Invalid queue action.');
        if ($operation === 'retry') {
            if (get_option('lec_preload_queue') && !wp_next_scheduled('lec_preload_batch')) wp_schedule_single_event(time() + 1, 'lec_preload_batch');
            if (get_option('lec_spaces_retry_queue') && !wp_next_scheduled('lec_spaces_retry_batch')) wp_schedule_single_event(time() + 1, 'lec_spaces_retry_batch');
        } elseif ($operation === 'clear') {
            delete_option('lec_preload_queue');
            delete_option('lec_spaces_retry_queue');
            wp_clear_scheduled_hook('lec_preload_batch');
            wp_clear_scheduled_hook('lec_spaces_retry_batch');
        }
        wp_safe_redirect(add_query_arg('lec_notice', $operation === 'clear' ? 'queues_cleared' : 'queues_retried', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    public static function inspect_url(): void {
        check_admin_referer('lec_inspect_url');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        $url = esc_url_raw(wp_unslash($_POST['lec_inspect_url'] ?? ''));
        set_transient('lec_inspection_' . get_current_user_id(), LEC_Cache::inspect_url($url), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('options-general.php?page=lucid-edge-cache#lec-url-inspector')); exit;
    }

    public static function diagnostics(): void {
        check_admin_referer('lec_diagnostics');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        nocache_headers();
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="lucid-edge-cache-diagnostics-' . sanitize_file_name(LEC_Cache::site_host()) . '.json"');
        echo wp_json_encode(LEC_Cache::diagnostic_report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function onboard(): void {
        check_admin_referer('lec_onboard'); if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (LEC_Config::is_locked()) {
            wp_die('Lucid Edge Cache configuration is locked. Define LEC_ALLOW_RECONFIGURE as true temporarily to run onboarding again.', 'Configuration locked', array('response' => 403));
        }
        $values = array(
            'spaces_key' => sanitize_text_field(wp_unslash($_POST['spaces_key'] ?? '')),
            'spaces_secret' => trim((string) wp_unslash($_POST['spaces_secret'] ?? '')),
            'spaces_bucket' => sanitize_text_field(wp_unslash($_POST['spaces_bucket'] ?? '')),
            'spaces_region' => sanitize_key(wp_unslash($_POST['spaces_region'] ?? 'syd1')),
            'spaces_cdn_url' => esc_url_raw(wp_unslash($_POST['spaces_cdn_url'] ?? '')),
            'spaces_prefix' => trim(sanitize_text_field(wp_unslash($_POST['spaces_prefix'] ?? '')), '/'),
            'do_cdn_id' => sanitize_text_field(wp_unslash($_POST['do_cdn_id'] ?? '')),
            'do_api_token' => trim((string) wp_unslash($_POST['do_api_token'] ?? '')),
            'varnish_url' => esc_url_raw(wp_unslash($_POST['varnish_url'] ?? '')),
            'lock_settings' => !empty($_POST['lock_settings']),
        );
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $values['spaces_bucket']) || !preg_match('/^[a-z0-9-]+$/', $values['spaces_region'])) {
            set_transient('lec_setup_error_' . get_current_user_id(), 'Enter a valid Spaces bucket and region. Nothing was written to wp-config.php.', 60);
            wp_safe_redirect(admin_url('options-general.php?page=lucid-edge-cache-setup')); exit;
        }
        $settings = array('spaces_bucket' => $values['spaces_bucket'], 'spaces_region' => $values['spaces_region']);
        if (!LEC_Spaces::test_credentials($values['spaces_key'], $values['spaces_secret'], $settings)) {
            set_transient('lec_setup_error_' . get_current_user_id(), 'Spaces connection failed. Nothing was written to wp-config.php.', 60);
            wp_safe_redirect(admin_url('options-general.php?page=lucid-edge-cache-setup')); exit;
        }
        if (!LEC_Cache::valid_varnish_endpoint($values['varnish_url']) || !LEC_Cache::test_varnish_endpoint($values['varnish_url'])) {
            set_transient('lec_setup_error_' . get_current_user_id(), 'Varnish purge test failed. Nothing was written to wp-config.php.', 60);
            wp_safe_redirect(admin_url('options-general.php?page=lucid-edge-cache-setup')); exit;
        }
        $result = LEC_Config::write($values);
        if (is_wp_error($result)) {
            $block = LEC_Config::block($values);
            nocache_headers();
            wp_die(
                '<h1>Manual configuration required</h1><p>' . esc_html($result->get_error_message()) . '</p><p>Copy this block into <code>wp-config.php</code> above the WordPress bootstrap line. This response is generated directly and has not been stored in WordPress.</p><textarea style="width:100%;min-height:320px" readonly>' . esc_textarea($block) . '</textarea><p><a href="' . esc_url(admin_url('options-general.php?page=lucid-edge-cache-setup')) . '">Return to setup</a></p>',
                'Lucid Edge Cache setup',
                array('response' => 500)
            );
        } else {
            update_option('lec_settings', array_merge((array) get_option('lec_settings', array()), array('spaces_enabled' => 1, 'varnish_enabled' => 1)), false);
            $runtime = array_merge(LEC_Cache::settings(), array(
                'spaces_enabled' => 1,
                'spaces_bucket' => $values['spaces_bucket'],
                'spaces_region' => $values['spaces_region'],
                'spaces_cdn_url' => $values['spaces_cdn_url'],
                'spaces_prefix' => $values['spaces_prefix'],
                'do_cdn_id' => $values['do_cdn_id'],
                'varnish_enabled' => 1,
                'varnish_url' => $values['varnish_url'],
            ));
            LEC_Cache::write_runtime_config($runtime);
            LEC_Cache::log('onboarding', 'success', LEC_Cache::site_host());
        }
        wp_safe_redirect(add_query_arg('lec_notice', 'configured', admin_url('options-general.php?page=lucid-edge-cache'))); exit;
    }

    private static function check(string $name, array $s): void { echo '<input type="checkbox" name="lec_settings[' . esc_attr($name) . ']" value="1" ' . checked(!empty($s[$name]), true, false) . '>'; }
    private static function text(string $name, array $s, string $type = 'text'): void { echo '<input class="regular-text" type="' . esc_attr($type) . '" name="lec_settings[' . esc_attr($name) . ']" value="' . esc_attr((string) ($s[$name] ?? '')) . '">'; }
    private static function display_label(string $value): string {
        if (preg_match('/^http_(\d{3})$/', $value, $match)) return 'HTTP ' . $match[1];
        $label = ucwords(str_replace('_', ' ', $value));
        return str_replace(array('Cdn', 'Url', 'Html', 'Api'), array('CDN', 'URL', 'HTML', 'API'), $label);
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        $s = LEC_Cache::settings(); $stats = LEC_Cache::cache_stats();
        if (($_GET['lec_notice'] ?? '') === 'purged') echo '<div class="notice notice-success"><p>Local cache cleared and downstream purge requested.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'queued') echo '<div class="notice notice-success"><p>' . absint($_GET['count'] ?? 0) . ' URLs queued for preloading.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'url_queued') echo '<div class="notice notice-success"><p>The URL was cleared and queued for regeneration.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'invalid_url') echo '<div class="notice notice-error"><p>Enter a valid URL from this WordPress site.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'spaces_ok') echo '<div class="notice notice-success"><p>Spaces upload and deletion test succeeded.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'spaces_failed') echo '<div class="notice notice-error"><p>Spaces test failed. Review Recent activity and credentials.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'varnish_tested') echo '<div class="notice notice-info"><p>Varnish purge requested. Review its HTTP result below.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'configured') echo '<div class="notice notice-success"><p>Lucid Edge Cache is configured and locked. Infrastructure settings are now managed through <code>wp-config.php</code>.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'already_configured') echo '<div class="notice notice-info"><p>Configuration is already installed and locked. Setup is no longer accessible.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'system_tested') echo '<div class="notice notice-info"><p>System test completed. Review System health below; detailed activity is available in Advanced tools and diagnostics.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'cache_disabled') echo '<div class="notice notice-warning"><p>Page caching is disabled. Dynamic WordPress responses remain available.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'cache_enabled') echo '<div class="notice notice-success"><p>Page caching is enabled.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'queues_cleared') echo '<div class="notice notice-success"><p>Pending regeneration and Spaces retry queues were cleared.</p></div>';
        if (($_GET['lec_notice'] ?? '') === 'queues_retried') echo '<div class="notice notice-info"><p>Pending queue processing has been requested.</p></div>';
        ?>
        <div class="wrap"><h1>Lucid Edge Cache</h1>
        <p><strong>Status:</strong> <?php echo LEC_Cache::dropin_ok() ? 'Drop-in installed' : 'Drop-in unavailable'; ?> · <?php echo esc_html((string) $stats['count']); ?> pages · <?php echo esc_html(size_format($stats['bytes'])); ?> · Object cache: <?php echo wp_using_ext_object_cache() ? 'persistent' : 'default'; ?></p>
        <p><?php if (!empty($s['enabled'])) : ?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_cache_state&state=disable'), 'lec_cache_state')); ?>" onclick="return confirm('Disable Lucid page caching and clear local cached HTML?');">Emergency disable</a><?php else : ?><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_cache_state&state=enable'), 'lec_cache_state')); ?>">Enable page cache</a><?php endif; ?></p>
        <?php $locked = LEC_Config::is_locked(); if ($locked) echo '<div class="notice notice-info inline"><p>Infrastructure settings are managed and locked in <code>wp-config.php</code>. Cache policy and exclusions remain editable. To unlock deliberately, temporarily define <code>LEC_ALLOW_RECONFIGURE</code> as <code>true</code>.</p></div>'; ?>
        <form method="post" action="options.php"><?php settings_fields('lec'); ?>
        <h2>Page cache</h2><table class="form-table">
        <tr><th>Enable cache</th><td><?php self::check('enabled', $s); ?></td></tr>
        <tr><th>Cache lifetime</th><td>
        <?php $ttl = (int) $s['ttl']; $ttl_presets = array(14400 => '4 hours', 21600 => '6 hours', 43200 => '12 hours', 86400 => '1 day', 604800 => '1 week'); $custom_ttl = !isset($ttl_presets[$ttl]); ?>
        <input type="hidden" id="lec_ttl_value" name="lec_settings[ttl]" value="<?php echo esc_attr((string) $ttl); ?>">
        <div class="lec-ttl-options" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <?php foreach ($ttl_presets as $seconds => $label) : ?><button type="button" class="button lec-ttl-button<?php echo $ttl === $seconds ? ' button-primary' : ''; ?>" data-seconds="<?php echo esc_attr((string) $seconds); ?>"><?php echo esc_html($label); ?><?php echo $seconds === 21600 ? ' — Recommended' : ''; ?></button><?php endforeach; ?>
        <button type="button" class="button lec-ttl-button<?php echo $custom_ttl ? ' button-primary' : ''; ?>" data-seconds="custom">Custom</button>
        </div>
        <div id="lec_ttl_custom_wrap" style="margin-top:10px;<?php echo $custom_ttl ? '' : 'display:none;'; ?>"><label for="lec_ttl_custom">Seconds:</label> <input id="lec_ttl_custom" type="number" min="60" max="604800" step="60" value="<?php echo esc_attr((string) $ttl); ?>"> <span class="description">Between 60 seconds and 7 days.</span></div>
        <p class="description">This is the maximum age of cached HTML. Publishing changes still invalidates affected pages immediately.</p>
        <script>(function(){var hidden=document.getElementById('lec_ttl_value'),custom=document.getElementById('lec_ttl_custom'),wrap=document.getElementById('lec_ttl_custom_wrap'),buttons=document.querySelectorAll('.lec-ttl-button');function select(button){buttons.forEach(function(item){item.classList.remove('button-primary');});button.classList.add('button-primary');var value=button.getAttribute('data-seconds');if(value==='custom'){wrap.style.display='block';hidden.value=custom.value;}else{wrap.style.display='none';hidden.value=value;}}buttons.forEach(function(button){button.addEventListener('click',function(){select(button);});});custom.addEventListener('input',function(){var value=Math.max(60,Math.min(604800,parseInt(custom.value||'21600',10)));hidden.value=value;});})();</script>
        </td></tr>
        </table><h2>Cache safety rules</h2><p>These protections are always enabled and cannot be accidentally removed.</p><table class="widefat striped"><thead><tr><th>Protection</th><th>Requests bypassed</th><th>Status</th></tr></thead><tbody>
        <?php foreach (LEC_Cache::safety_rules() as $label => $description) : ?><tr><th><?php echo esc_html($label); ?></th><td><?php echo esc_html($description); ?></td><td><strong>Always bypassed</strong></td></tr><?php endforeach; ?>
        </tbody></table><h3>Additional exclusions</h3><table class="form-table">
        <tr><th>Additional URL paths</th><td><textarea class="large-text code" rows="5" name="lec_settings[exclude_paths]"><?php echo esc_textarea((string) $s['exclude_paths']); ?></textarea><p class="description">One path fragment per line. Use this for forms, memberships or other personalised pages.</p></td></tr>
        <tr><th>Additional cookies</th><td><textarea class="large-text code" rows="5" name="lec_settings[exclude_cookies]"><?php echo esc_textarea((string) $s['exclude_cookies']); ?></textarea><p class="description">One cookie-name fragment per line. Built-in WordPress and WooCommerce cookies are already protected.</p></td></tr>
        </table><h2>Varnish and object cache</h2><table class="form-table">
        <?php if (!$locked) : ?>
        <tr><th>Request Varnish purge</th><td><?php self::check('varnish_enabled', $s); ?> On content changes and manual purge</td></tr>
        <tr><th>Varnish purge endpoint</th><td><?php self::text('varnish_url', $s, 'url'); ?><p class="description">Cloudways commonly exposes Varnish internally on port 8080. The site hostname is sent separately in the Host header. Leave blank to test the public home URL.</p></td></tr>
        <?php else : ?><tr><th>Varnish endpoint</th><td><code><?php echo esc_html((string) $s['varnish_url']); ?></code></td></tr><?php endif; ?>
        <tr><th>Flush persistent object cache</th><td><?php self::check('flush_object_cache', $s); ?> Aggressive; leave disabled unless testing proves it is needed.</td></tr>
        </table><h2>DigitalOcean Spaces replication</h2><table class="form-table">
        <?php if (!$locked) : ?>
        <tr><th>Enable HTML replication</th><td><?php self::check('spaces_enabled', $s); ?></td></tr>
        <tr><th>Region</th><td><?php self::text('spaces_region', $s); ?></td></tr>
        <tr><th>Bucket</th><td><?php self::text('spaces_bucket', $s); ?></td></tr>
        <tr><th>Object prefix</th><td><?php self::text('spaces_prefix', $s); ?></td></tr>
        <tr><th>CDN URL</th><td><?php self::text('spaces_cdn_url', $s, 'url'); ?><p class="description">Reserved for primary-CDN mode in a later release.</p></td></tr>
        <tr><th>DigitalOcean CDN endpoint ID</th><td><?php self::text('do_cdn_id', $s); ?></td></tr>
        <?php else : ?><tr><th>Canonical namespace</th><td><code><?php echo esc_html(LEC_Cache::site_host()); ?>/</code></td></tr><tr><th>Bucket</th><td><code><?php echo esc_html((string) $s['spaces_bucket']); ?></code></td></tr><tr><th>Region</th><td><code><?php echo esc_html((string) $s['spaces_region']); ?></code></td></tr><tr><th>CDN URL</th><td><code><?php echo esc_html((string) $s['spaces_cdn_url']); ?></code></td></tr><?php endif; ?>
        </table><p>Keep secrets outside the database by defining <code>LEC_SPACES_KEY</code>, <code>LEC_SPACES_SECRET</code> and, for CDN purging, <code>LEC_DO_API_TOKEN</code> in <code>wp-config.php</code>.</p>
        <?php submit_button(); ?></form>
        <h2>Understanding cache headers</h2><p><code>X-Lucid-Cache: HIT</code> means local HTML was served. <code>MISS</code> means the response can be cached and is being generated. <code>BYPASS</code> means the request was deliberately excluded; <code>X-Lucid-Cache-Reason</code> gives a safe explanation without exposing private data.</p>
        <hr><h2>Tools</h2>
        <p><a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_purge'), 'lec_purge')); ?>">Clear and purge</a> <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_preload'), 'lec_preload')); ?>">Preload published content</a></p>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_system_test'), 'lec_system_test')); ?>">Run system test</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_test_varnish'), 'lec_test_varnish')); ?>">Test Varnish purge</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_test_spaces'), 'lec_test_spaces')); ?>">Test Spaces</a></p>
        <?php $health = LEC_Cache::health(); ?><h2>System health</h2><table class="widefat striped"><tbody>
        <?php foreach ($health as $label => $value) : ?><tr><th><?php echo esc_html($label); ?></th><td><?php echo esc_html($value); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php $preload_queue = (array) get_option('lec_preload_queue', array()); $spaces_queue = (array) get_option('lec_spaces_retry_queue', array()); $inspection = get_transient('lec_inspection_' . get_current_user_id()); delete_transient('lec_inspection_' . get_current_user_id()); ?>
        <details <?php echo is_array($inspection) ? 'open' : ''; ?> style="margin-top:20px;background:#fff;border:1px solid #c3c4c7;border-radius:4px">
        <summary style="cursor:pointer;padding:14px 16px;font-size:14px;font-weight:600">Advanced tools and diagnostics <span style="font-weight:400;color:#646970">— queues, URL inspector and activity</span></summary>
        <div style="padding:0 16px 16px;border-top:1px solid #dcdcde">
        <h2>Background queues</h2><table class="widefat striped"><thead><tr><th>Queue</th><th>Pending</th></tr></thead><tbody><tr><th>Page regeneration</th><td><?php echo esc_html((string) count($preload_queue)); ?></td></tr><tr><th>Spaces retries</th><td><?php echo esc_html((string) count($spaces_queue)); ?></td></tr></tbody></table>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_queue_action&operation=retry'), 'lec_queue_action')); ?>">Retry pending work</a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_queue_action&operation=clear'), 'lec_queue_action')); ?>" onclick="return confirm('Clear both pending queues?');">Clear queues</a></p>
        <h2 id="lec-url-inspector">URL inspector</h2><p>Check local cache state and path-based exclusions without requesting or changing the page.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="lec_inspect_url"><?php wp_nonce_field('lec_inspect_url'); ?><input class="regular-text" name="lec_inspect_url" type="url" required placeholder="<?php echo esc_attr(home_url('/example/')); ?>"> <?php submit_button('Inspect URL', 'secondary', 'submit', false); ?></form>
        <?php if (is_array($inspection)) : ?>
        <table class="widefat striped" style="margin-top:12px"><tbody><?php foreach ($inspection as $label => $value) : if ($label === 'valid') continue; ?><tr><th><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $label))); ?></th><td><?php echo esc_html((string) $value); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=lec_diagnostics'), 'lec_diagnostics')); ?>">Download safe diagnostics</a></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="lec_regenerate_url"><?php wp_nonce_field('lec_regenerate_url'); ?><label for="lec_url"><strong>Regenerate one URL</strong></label><br><input class="regular-text" id="lec_url" name="lec_url" type="url" required placeholder="<?php echo esc_attr(home_url('/example/')); ?>"> <?php submit_button('Regenerate URL', 'secondary', 'submit', false); ?></form>
        <h2>Recent activity</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Event</th><th>Status</th><th>Detail</th></tr></thead><tbody>
        <?php foreach (LEC_Cache::activity() as $row) : ?><tr><td><?php echo esc_html(wp_date('Y-m-d H:i:s', (int) ($row['time'] ?? 0))); ?></td><td><?php echo esc_html(self::display_label((string) ($row['event'] ?? ''))); ?></td><td><?php echo esc_html(self::display_label((string) ($row['status'] ?? ''))); ?></td><td><?php echo esc_html((string) ($row['detail'] ?? '')); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        </div></details>
        </div><?php
    }

    public static function setup_page(): void {
        if (!current_user_can('manage_options')) return;
        $error = get_transient('lec_setup_error_' . get_current_user_id());
        delete_transient('lec_setup_error_' . get_current_user_id());
        if ($error) echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        ?>
        <div class="wrap"><h1>Lucid Edge Cache Setup</h1><p>This one-time setup tests DigitalOcean Spaces, then writes the credentials directly to <code>wp-config.php</code>. Secrets are not stored in WordPress options, transients or activity logs.</p>
        <p><strong>Canonical hostname namespace:</strong> <code><?php echo esc_html(LEC_Cache::site_host()); ?>/</code></p>
        <?php if (LEC_Config::is_locked()) : wp_safe_redirect(admin_url('options-general.php?page=lucid-edge-cache')); exit; endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off"><input type="hidden" name="action" value="lec_onboard"><?php wp_nonce_field('lec_onboard'); ?>
        <table class="form-table"><tr><th><label for="spaces_key">Spaces Access Key ID</label></th><td><input class="regular-text" id="spaces_key" name="spaces_key" required autocomplete="off"></td></tr>
        <tr><th><label for="spaces_secret">Spaces Secret Key</label></th><td><input class="regular-text" id="spaces_secret" name="spaces_secret" type="password" required autocomplete="new-password"></td></tr>
        <tr><th><label for="spaces_bucket">Bucket</label></th><td><input class="regular-text" id="spaces_bucket" name="spaces_bucket" value="lucid-edge-cache" required></td></tr>
        <tr><th><label for="spaces_region">Region</label></th><td><input class="regular-text" id="spaces_region" name="spaces_region" value="syd1" required></td></tr>
        <tr><th><label for="spaces_cdn_url">CDN URL</label></th><td><input class="regular-text" id="spaces_cdn_url" name="spaces_cdn_url" type="url" placeholder="https://lucid-edge-cache.syd1.cdn.digitaloceanspaces.com" required></td></tr>
        <tr><th><label for="spaces_prefix">Optional object prefix</label></th><td><input class="regular-text" id="spaces_prefix" name="spaces_prefix"><p class="description">The canonical hostname is always added after this prefix.</p></td></tr>
        <tr><th><label for="do_cdn_id">DigitalOcean CDN endpoint ID</label></th><td><input class="regular-text" id="do_cdn_id" name="do_cdn_id" autocomplete="off"><p class="description">Optional; enables immediate CDN purging.</p></td></tr>
        <tr><th><label for="do_api_token">DigitalOcean API token</label></th><td><input class="regular-text" id="do_api_token" name="do_api_token" type="password" autocomplete="new-password"><p class="description">Optional; written only to wp-config.php.</p></td></tr>
        <tr><th><label for="varnish_url">Varnish endpoint</label></th><td><input class="regular-text" id="varnish_url" name="varnish_url" type="url" value="http://127.0.0.1:8080/"></td></tr>
        <tr><th>Lock configuration</th><td><label><input type="checkbox" name="lock_settings" value="1" checked> Prevent infrastructure settings being changed through WordPress</label></td></tr></table>
        <?php submit_button('Test and configure'); ?></form></div><?php
    }
}
