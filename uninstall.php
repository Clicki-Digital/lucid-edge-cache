<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if (file_exists($dropin) && strpos((string) file_get_contents($dropin), 'LUCID_EDGE_CACHE_DROPIN') !== false) unlink($dropin);
delete_option('lec_settings'); delete_option('lec_version'); delete_option('lec_wp_cache_error'); delete_option('lec_dropin_conflict'); delete_option('lec_preload_queue'); delete_option('lec_spaces_retry_queue'); delete_option('lec_last_system_test'); delete_option('lec_activity');
delete_transient('lec_activation_redirect'); delete_transient('lec_preload_lock'); delete_transient('lec_spaces_retry_lock');
wp_clear_scheduled_hook('lec_preload_batch'); wp_clear_scheduled_hook('lec_spaces_retry_batch'); wp_clear_scheduled_hook('lec_cache_cleanup');
