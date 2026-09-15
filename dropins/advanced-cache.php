<?php
// LUCID_EDGE_CACHE_DROPIN
// Runs before normal WordPress plugin loading. Keep this file dependency-free.

if (PHP_SAPI === 'cli' || defined('WP_CLI') || defined('DOING_CRON') || defined('DOING_AJAX')) return;
$config_file = WP_CONTENT_DIR . '/cache/lucid-edge-cache/config.php';
if (!is_readable($config_file)) return;
$config = include $config_file;
if (empty($config['enabled']) || empty($config['cache_dir'])) return;

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, array('GET', 'HEAD'), true)) return;
if (!empty($_SERVER['HTTP_X_LUCID_CACHE_PRELOAD'])) return;
if (!empty($_SERVER['QUERY_STRING'])) return;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
if (preg_match('#/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php)(/|$)#i', $uri)) return;
$request_path = (string) parse_url($uri, PHP_URL_PATH);
foreach (array_merge((array) ($config['protected_paths'] ?? array()), (array) ($config['exclude_paths'] ?? array())) as $excluded) {
    if ($excluded !== '' && stripos($request_path, $excluded) !== false) return;
}

$cookie_names = strtolower(implode(';', array_keys($_COOKIE ?? array())));
foreach (array_merge((array) ($config['protected_cookies'] ?? array()), (array) ($config['exclude_cookies'] ?? array())) as $excluded) {
    if ($excluded !== '' && stripos($cookie_names, strtolower($excluded)) !== false) return;
}

$host = preg_replace('/[^a-z0-9.-]/i', '_', strtolower((string) ($config['site_host'] ?? ($_SERVER['HTTP_HOST'] ?? 'default'))));
$path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
$path = trim((string) preg_replace('#/+#', '/', $path), '/');
$segments = $path === '' ? array() : array_map(static function ($part) {
    $part = preg_replace('/[^A-Za-z0-9_.-]/', '-', $part);
    return trim((string) $part, '-');
}, explode('/', $path));
$segments = array_values(array_filter($segments, 'strlen'));
$relative = $host . '/' . ($segments ? implode('/', $segments) . '/' : '') . 'index.html';
$file = rtrim((string) $config['cache_dir'], '/') . '/' . $relative;
$ttl = max(60, (int) ($config['ttl'] ?? 21600));
$fresh = is_file($file) && (filemtime($file) + $ttl) >= time();
if (!$fresh) {
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0750, true);
    $lock_path = $file . '.lock';
    $lock = @fopen($lock_path, 'c');
    if (is_resource($lock) && @flock($lock, LOCK_EX | LOCK_NB)) {
        @touch($lock_path);
        $GLOBALS['lec_cache_lock_handle'] = $lock;
        $GLOBALS['lec_cache_lock_path'] = $lock_path;
        header('X-Lucid-Cache-Lock: ACQUIRED');
        return;
    }
    if (is_resource($lock)) @fclose($lock);
    if (is_file($file) && (filemtime($file) + $ttl + 300) >= time()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Lucid-Cache: STALE');
        header('X-Lucid-Cache-Age: ' . max(0, time() - (int) filemtime($file)));
        header('X-Lucid-Cache-Lock: BUSY');
        if ($method === 'GET') readfile($file);
        exit;
    }
    $wait_until = microtime(true) + 1.5;
    do {
        usleep(100000);
        clearstatcache(true, $file);
        if (is_file($file) && (filemtime($file) + $ttl) >= time()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Lucid-Cache: HIT');
            header('X-Lucid-Cache-Lock: WAITED');
            if ($method === 'GET') readfile($file);
            exit;
        }
    } while (microtime(true) < $wait_until);
    header('X-Lucid-Cache-Lock: BUSY');
    return;
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Lucid-Cache: HIT');
header('X-Lucid-Cache-Age: ' . max(0, time() - (int) filemtime($file)));
header('Cache-Control: public, max-age=0, s-maxage=' . $ttl);
header('Content-Length: ' . filesize($file));
if ($method === 'GET') readfile($file);
exit;
