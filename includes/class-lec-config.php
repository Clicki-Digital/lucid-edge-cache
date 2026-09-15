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
        $lines[] = "defined('WP_CACHE') || define('WP_CACHE', true);";
        $lines[] = self::END;
        return implode("\n", $lines);
    }

    public static function write(array $values) {
        $path = self::path();
        if ($path === '' || !is_readable($path) || !is_writable($path)) return new WP_Error('lec_config_not_writable', 'wp-config.php is not writable.');
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
        if (!is_string($updated) || $updated === $original) return new WP_Error('lec_config_unchanged', 'No configuration change was produced.');
        $permissions = fileperms($path);
        $mode = $permissions === false ? 0640 : ($permissions & 0777);
        $tmp = dirname($path) . '/.' . basename($path) . '.lec-' . wp_generate_password(10, false, false);
        if (file_put_contents($tmp, $updated, LOCK_EX) === false) return new WP_Error('lec_config_temp', 'The temporary configuration file could not be written.');
        @chmod($tmp, $mode);
        $check = (string) file_get_contents($tmp);
        if (strpos($check, $block) === false || strpos($check, '<?php') !== 0 || !@rename($tmp, $path)) {
            @unlink($tmp);
            return new WP_Error('lec_config_commit', 'The configuration could not be committed; the original was left in place.');
        }
        clearstatcache(true, $path);
        if (strpos((string) file_get_contents($path), self::START) === false) return new WP_Error('lec_config_verify', 'Configuration verification failed.');
        return true;
    }

    public static function ensure_wp_cache() {
        if (defined('WP_CACHE') && WP_CACHE) return true;
        $path = self::path();
        if ($path === '' || !is_readable($path) || !is_writable($path)) return new WP_Error('lec_config_not_writable', 'wp-config.php is not writable.');
        $original = file_get_contents($path);
        if ($original === false || strpos($original, 'wp-settings.php') === false) return new WP_Error('lec_config_invalid', 'The WordPress bootstrap marker was not found.');
        if (preg_match("/define\\s*\\(\\s*['\"]WP_CACHE['\"]\\s*,/i", $original)) {
            return new WP_Error('lec_wp_cache_disabled', 'WP_CACHE is already defined but is not enabled.');
        }
        $markers = array('/* That\'s all, stop editing!', '/** Absolute path to the WordPress directory. */', "require_once ABSPATH . 'wp-settings.php';", 'require_once(ABSPATH . \'wp-settings.php\');');
        $position = false;
        foreach ($markers as $marker) {
            $position = strpos($original, $marker);
            if ($position !== false) break;
        }
        if ($position === false) return new WP_Error('lec_config_marker', 'A safe insertion point could not be found.');
        $line = "defined('WP_CACHE') || define('WP_CACHE', true);\n\n";
        $updated = substr($original, 0, $position) . $line . substr($original, $position);
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
}
