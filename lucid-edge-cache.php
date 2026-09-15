<?php
/**
 * Plugin Name: Lucid Edge Cache
 * Plugin URI: https://clickidigital.com.au/plugins/lucid-edge-cache/
 * Description: Safe full-page HTML caching with Varnish purging and optional DigitalOcean Spaces replication.
 * Version: 0.7.1
 * Author: Lucid Solutions
 * Author URI: https://clickidigital.com.au/
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Update URI: https://clickidigital.com.au/plugins/lucid-edge-cache/
 */

defined('ABSPATH') || exit;

define('LEC_VERSION', '0.7.1');
define('LEC_FILE', __FILE__);
define('LEC_DIR', plugin_dir_path(__FILE__));
define('LEC_CACHE_DIR', WP_CONTENT_DIR . '/cache/lucid-edge-cache');
defined('LEC_GITHUB_REPOSITORY') || define('LEC_GITHUB_REPOSITORY', 'Clicki-Digital/lucid-edge-cache');

require_once LEC_DIR . 'includes/class-lec-config.php';
require_once LEC_DIR . 'includes/class-lec-spaces.php';
require_once LEC_DIR . 'includes/class-lec-cache.php';
require_once LEC_DIR . 'includes/class-lec-admin.php';
require_once LEC_DIR . 'includes/class-lec-updater.php';

register_activation_hook(__FILE__, array('LEC_Cache', 'activate'));
register_deactivation_hook(__FILE__, array('LEC_Cache', 'deactivate'));

LEC_Cache::boot();
LEC_Updater::boot();
if (is_admin()) {
    LEC_Admin::boot();
}
