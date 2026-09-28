<?php

defined('ABSPATH') || exit;

final class LEC_Config {
    public const START = '// BEGIN Lucid Edge Cache';
    public const END = '// END Lucid Edge Cache';

    public static function path(): string {
        $local = ABSPATH . 'wp-config.php';
        if (is_file($local)) return $local;
        $parent = dirname(rtrim(ABSPATH, '/\\')) . '/wp-config.php';
        return is_file($parent) ? $parent : '';
    }

    public static function is_managed(): bool {
        $path = self::path();
        return $path !== '' && strpos((string) file_get_contents($path), self::START) !== false;
    }

    public static function is_locked(): bool {
        return self::is_configured()
            && (self::is_managed() || (defined('LEC_LOCK_SETTINGS') && LEC_LOCK_SETTINGS))
            && !(defined('LEC_ALLOW_RECONFIGURE') && LEC_ALLOW_RECONFIGURE);
    }

    public static function is_configured(): bool {
        if (!self::is_managed()) return false;
        foreach (array('LEC_SPACES_KEY', 'LEC_SPACES_SECRET', 'LEC_SPACES_BUCKET', 'LEC_SPACES_REGION', 'LEC_SPACES_CDN_URL') as $constant) {
            if (!defined($constant) || trim((string) constant($constant)) === '') return false;
        }
        return true;
    }

    public static function access_report(): array {
        $path = self::path();
        $directory = $path !== '' ? dirname($path) : '';
        $permissions = $path !== '' ? fileperms($path) : false;
        return array(
            'Resolved file' => $path !== '' ? $path : 'Not found in the WordPress root or its parent directory',
            'Readable by PHP' => $path !== '' && is_readable($path) ? 'Yes' : 'No',
            'Writable by PHP' => $path !== '' && is_writable($path) ? 'Yes' : 'No',
            'Directory writable' => $directory !== '' && is_writable($directory) ? 'Yes' : 'No',
            'File permissions' => $permissions === false ? 'Unavailable' : sprintf('%04o', $permissions & 0777),
        );
    }

    public static function block(array $values): string {
        $lines = array(self::START);
        $map = array(
            'spaces_key' => 'LEC_SPACES_KEY',
            'spaces_secret' => 'LEC_SPACES_SECRET',
            'spaces_bucket' => 'LEC_SPACES_BUCKET',
            'spaces_region' => 'LEC_SPACES_REGION',
            'spaces_cdn_url' => 'LEC_SPACES_CDN_URL',
            'spaces_prefix' => 'LEC_SPACES_PREFIX',
            'do_cdn_id' => 'LEC_DO_CDN_ID',
            'do_api_token' => 'LEC_DO_API_TOKEN',
            'varnish_url' => 'LEC_VARNISH_URL',
        );
        foreach ($map as $key => $constant) {
            $value = (string) ($values[$key] ?? '');
            $lines[] = "defined('" . $constant . "') || define('" . $constant . "', " . var_export($value, true) . ');';
        }
        $lines[] = "defined('LEC_LOCK_SETTINGS') || define('LEC_LOCK_SETTINGS', " . (!empty($values['lock_settings']) ? 'true' : 'false') . ');';
        $lines[] = self::END;
        return implode("\n", $lines);
    }

    public static function write(array $values) {
        $path = self::path();
        $access = self::write_access_error($path);
        if (is_wp_error($access)) return $access;
        $original = file_get_contents($path);
        if ($original === false || strpos($original, 'wp-settings.php') === false) return new WP_Error('lec_config_invalid', 'The WordPress bootstrap marker was not found.');
        $block = self::block($values);
        $pattern = '/\R?' . preg_quote(self::START, '/') . '.*?' . preg_quote(self::END, '/') . '\R?/s';
        if (preg_match($pattern, $original)) {
            $updated = preg_replace($pattern, "\n" . $block . "\n", $original, 1);
        } else {
            $markers = array('/* That\'s all, stop editing!', '/** Absolute path to the WordPress directory. */', "require_once ABSPATH . 'wp-settings.php';", 'require_once(ABSPATH . \'wp-settings.php\');');
            $position = false;
            foreach ($markers as $marker) {
                $position = strpos($original, $marker);
                if ($position !== false) break;
            }
            if ($position === false) return new WP_Error('lec_config_marker', 'A safe insertion point could not be found.');
            $updated = substr($original, 0, $position) . $block . "\n\n" . substr($original, $position);
        }
        if (!is_string($updated)) return new WP_Error('lec_config_unchanged', 'No configuration change was produced.');
        $updated = self::canonicalise_wp_cache($updated);
        if (is_wp_error($updated)) return $updated;
        if ($updated === $original) return new WP_Error('lec_config_unchanged', 'No configuration change was produced.');
        $permissions = fileperms($path);
        $mode = $permissions === false ? 0640 : ($permissions & 0777);
        $tmp = dirname($path) . '/.' . basename($path) . '.lec-' . wp_generate_password(10, false, false);
        if (file_put_contents($tmp, $updated, LOCK_EX) === false) return new WP_Error('lec_config_temp', 'The temporary configuration file could not be written.');
        @chmod($tmp, $mode);
        $check = (string) file_get_contents($tmp);
        if (strpos($check, $block) === false || strpos($check, self::wp_cache_line()) === false || strpos($check, '<?php') !== 0 || !@rename($tmp, $path)) {
            @unlink($tmp);
            return new WP_Error('lec_config_commit', 'The configuration could not be committed; the original was left in place.');
        }
        clearstatcache(true, $path);
        if (strpos((string) file_get_contents($path), self::START) === false) return new WP_Error('lec_config_verify', 'Configuration verification failed.');
        return true;
    }

    public static function ensure_wp_cache() {
        $path = self::path();
        if ($path === '') return new WP_Error('lec_config_missing', 'wp-config.php was not found in the WordPress root or its parent directory.');
        if (!is_readable($path)) return new WP_Error('lec_config_not_readable', 'PHP cannot read wp-config.php at ' . $path . '. Check its ownership and permissions.');
        $original = file_get_contents($path);
        if ($original === false || strpos($original, 'wp-settings.php') === false) return new WP_Error('lec_config_invalid', 'The WordPress bootstrap marker was not found.');
        if (self::wp_cache_is_enabled_once($original)) return true;
        $updated = self::canonicalise_wp_cache($original);
        if (is_wp_error($updated)) return $updated;
        if ($updated === $original) return true;
        $access = self::write_access_error($path);
        if (is_wp_error($access)) return $access;
        $line = self::wp_cache_line();
        $permissions = fileperms($path);
        $mode = $permissions === false ? 0640 : ($permissions & 0777);
        $tmp = dirname($path) . '/.' . basename($path) . '.lec-cache-' . wp_generate_password(10, false, false);
        if (file_put_contents($tmp, $updated, LOCK_EX) === false) return new WP_Error('lec_config_temp', 'The temporary configuration file could not be written.');
        @chmod($tmp, $mode);
        $check = (string) file_get_contents($tmp);
        if (strpos($check, $line) === false || strpos($check, '<?php') !== 0 || !@rename($tmp, $path)) {
            @unlink($tmp);
            return new WP_Error('lec_config_commit', 'WP_CACHE could not be enabled; the original file was left in place.');
        }
        clearstatcache(true, $path);
        return true;
    }

    private static function wp_cache_line(): string {
        return "define('WP_CACHE', true);";
    }

    private static function wp_cache_is_enabled_once(string $config): bool {
        $definitions = preg_match_all("~define\s*\(\s*(['\"])WP_CACHE\\1\s*,~i", $config);
        return $definitions === 1 && (bool) preg_match("~^[\t ]*define\s*\(\s*(['\"])WP_CACHE\\1\s*,\s*true\s*\)\s*;\s*(?:(?://|#)[^\r\n]*)?$~mi", $config);
    }

    private static function write_access_error(string $path) {
        if ($path === '') return new WP_Error('lec_config_missing', 'wp-config.php was not found in the WordPress root or its parent directory.');
        if (!is_readable($path)) return new WP_Error('lec_config_not_readable', 'PHP cannot read wp-config.php at ' . $path . '. Check its ownership and permissions.');
        $permissions = fileperms($path);
        $mode = $permissions === false ? 'unknown' : sprintf('%04o', $permissions & 0777);
        if (!is_writable($path)) return new WP_Error('lec_config_file_not_writable', 'PHP can read wp-config.php at ' . $path . ' but cannot write to it. Current permissions: ' . $mode . '. Make the file writable by the application user, then retry.');
        $directory = dirname($path);
        if (!is_writable($directory)) return new WP_Error('lec_config_directory_not_writable', 'PHP can write to wp-config.php, but cannot create the verified temporary file in ' . $directory . '. The containing directory must be writable by the application user during setup.');
        return true;
    }

    private static function canonicalise_wp_cache(string $config) {
        $patterns = array(
            "~^[\t ]*defined\s*\(\s*(['\"])WP_CACHE\\1\s*\)\s*\|\|\s*define\s*\(\s*(['\"])WP_CACHE\\2\s*,[^;\r\n]*\)\s*;\s*(?:(?://|#)[^\r\n]*)?\R?~mi",
            "~^[\t ]*define\s*\(\s*(['\"])WP_CACHE\\1\s*,[^;\r\n]*\)\s*;\s*(?:(?://|#)[^\r\n]*)?\R?~mi",
        );
        $without = preg_replace($patterns, '', $config);
        if (!is_string($without)) return new WP_Error('lec_wp_cache_parse', 'The existing WP_CACHE definition could not be read safely.');
        if (preg_match("~define\s*\(\s*(['\"])WP_CACHE\\1\s*,~i", $without)) {
            return new WP_Error('lec_wp_cache_unsupported', 'WP_CACHE uses an unsupported multi-line definition. Update it manually to define WP_CACHE as true.');
        }
        $markers = array('/* That\'s all, stop editing!', '/** Absolute path to the WordPress directory. */', "require_once ABSPATH . 'wp-settings.php';", 'require_once(ABSPATH . \'wp-settings.php\');');
        $position = false;
        foreach ($markers as $marker) {
            $position = strpos($without, $marker);
            if ($position !== false) break;
        }
        if ($position === false) return new WP_Error('lec_config_marker', 'A safe insertion point could not be found.');
        return substr($without, 0, $position) . self::wp_cache_line() . "\n\n" . substr($without, $position);
    }
}
