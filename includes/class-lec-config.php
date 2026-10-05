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
        return self::missing_constants() === array();
    }

    public static function missing_constants(): array {
        $missing = array();
        foreach (array('LEC_SPACES_KEY', 'LEC_SPACES_SECRET', 'LEC_SPACES_BUCKET', 'LEC_SPACES_REGION', 'LEC_SPACES_CDN_URL') as $constant) {
            if (!defined($constant) || trim((string) constant($constant)) === '') $missing[] = $constant;
        }
        return $missing;
    }

    public static function access_report(): array {
        $path = self::path();
        $directory = $path !== '' ? dirname($path) : '';
        $permissions = $path !== '' ? fileperms($path) : false;
        $file_writable = $path !== '' && is_writable($path);
        $directory_writable = $directory !== '' && is_writable($directory);
        return array(
            'Resolved file' => $path !== '' ? $path : 'Not found in the WordPress root or its parent directory',
            'Readable by PHP' => $path !== '' && is_readable($path) ? 'Yes' : 'No',
            'Writable by PHP' => $file_writable ? 'Yes' : 'No',
            'Directory writable' => $directory_writable ? 'Yes' : 'No',
            'File permissions' => $permissions === false ? 'Unavailable' : sprintf('%04o', $permissions & 0777),
            'Automatic write method' => $directory_writable
                ? ($file_writable ? 'Atomic replacement with locked direct-write fallback' : 'Atomic verified replacement')
                : ($file_writable ? 'Locked direct update with verification' : 'Unavailable'),
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
        return self::commit_contents($path, $updated, $original, static function (string $check) use ($block): bool {
            return strpos($check, $block) !== false
                && strpos($check, self::wp_cache_line()) !== false
                && strpos($check, '<?php') === 0
                && strpos($check, 'wp-settings.php') !== false;
        }, 'The Lucid configuration could not be committed.');
    }

    public static function ensure_wp_cache() {
        $path = self::path();
        if ($path === '') return new WP_Error('lec_config_missing', 'wp-config.php was not found in the WordPress root or its parent directory.');
        if (!is_readable($path)) return new WP_Error('lec_config_not_readable', 'PHP cannot read wp-config.php at ' . $path . '. Check its ownership and permissions.');
        $original = file_get_contents($path);
        if ($original === false || strpos($original, 'wp-settings.php') === false) return new WP_Error('lec_config_invalid', 'The WordPress bootstrap marker was not found.');
        $already_enabled = self::wp_cache_is_enabled_once($original);
        if ($already_enabled && !is_writable($path) && !is_writable(dirname($path))) return true;
        $updated = self::canonicalise_wp_cache($original);
        if (is_wp_error($updated)) return $updated;
        if ($updated === $original) return true;
        $access = self::write_access_error($path);
        if (is_wp_error($access)) return $access;
        return self::commit_contents($path, $updated, $original, static function (string $check): bool {
            return strpos($check, self::wp_cache_line()) !== false
                && strpos($check, '<?php') === 0
                && strpos($check, 'wp-settings.php') !== false;
        }, 'WP_CACHE could not be enabled.');
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
        $directory = dirname($path);
        if (!is_writable($path) && !is_writable($directory)) return new WP_Error('lec_config_not_writable', 'PHP can read wp-config.php at ' . $path . ' but can neither update the file nor replace it through the containing directory. Current file permissions: ' . $mode . '. Make either the file writable by the application user or allow temporary-file creation in ' . $directory . ', then retry.');
        return true;
    }

    private static function commit_contents(string $path, string $updated, string $original, callable $validator, string $failure_message) {
        $directory = dirname($path);
        if (is_writable($directory)) {
            $permissions = fileperms($path);
            $mode = $permissions === false ? 0640 : ($permissions & 0777);
            $tmp = $directory . '/.' . basename($path) . '.lec-' . wp_generate_password(10, false, false);
            if (file_put_contents($tmp, $updated, LOCK_EX) === false) {
                if (is_writable($path)) return self::commit_direct($path, $updated, $original, $validator, $failure_message, 'Temporary-file creation failed, so Lucid tried the locked direct-write fallback.');
                return new WP_Error('lec_config_temp', 'The verified temporary configuration file could not be written, and wp-config.php is not directly writable.');
            }
            @chmod($tmp, $mode);
            $check = (string) file_get_contents($tmp);
            if (!$validator($check)) {
                @unlink($tmp);
                return new WP_Error('lec_config_temp_verify', $failure_message . ' The temporary file failed validation, so the original file was left in place.');
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                if (is_writable($path)) return self::commit_direct($path, $updated, $original, $validator, $failure_message, 'Atomic replacement was refused by the host, so Lucid tried the locked direct-write fallback.');
                return new WP_Error('lec_config_rename', $failure_message . ' The host refused atomic replacement and wp-config.php is not directly writable. The original file was left in place.');
            }
            clearstatcache(true, $path);
            if (!$validator((string) file_get_contents($path))) return new WP_Error('lec_config_verify', $failure_message . ' Verification failed after the atomic replacement.');
            return true;
        }

        if (!is_writable($path)) return new WP_Error('lec_config_file_not_writable', $failure_message . ' PHP cannot write to wp-config.php and its containing directory does not permit atomic replacement.');
        return self::commit_direct($path, $updated, $original, $validator, $failure_message, 'The containing directory does not permit atomic replacement, so Lucid used a locked direct update.');
    }

    private static function commit_direct(string $path, string $updated, string $original, callable $validator, string $failure_message, string $method_detail) {
        if (!self::locked_write($path, $updated)) {
            $restored = self::locked_write($path, $original);
            clearstatcache(true, $path);
            if ($restored && (string) file_get_contents($path) === $original) return new WP_Error('lec_config_direct_write', $failure_message . ' ' . $method_detail . ' The direct update was incomplete, so the original wp-config.php content was restored.');
            return new WP_Error('lec_config_restore_failed', $failure_message . ' ' . $method_detail . ' The direct update failed and the original content could not be confirmed as restored. Restore wp-config.php from your hosting backup before continuing.');
        }
        clearstatcache(true, $path);
        if ($validator((string) file_get_contents($path))) return true;

        $restored = self::locked_write($path, $original);
        clearstatcache(true, $path);
        if ($restored && (string) file_get_contents($path) === $original) return new WP_Error('lec_config_direct_verify', $failure_message . ' ' . $method_detail . ' Verification failed, so the original wp-config.php content was restored.');
        return new WP_Error('lec_config_restore_failed', $failure_message . ' ' . $method_detail . ' Verification failed and the original content could not be confirmed as restored. Restore wp-config.php from your hosting backup before continuing.');
    }

    private static function locked_write(string $path, string $contents): bool {
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) return false;
        if (!@flock($handle, LOCK_EX)) { @fclose($handle); return false; }
        $success = @ftruncate($handle, 0) && @rewind($handle);
        $length = strlen($contents);
        $offset = 0;
        while ($success && $offset < $length) {
            $written = @fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) { $success = false; break; }
            $offset += $written;
        }
        if ($success) $success = @fflush($handle);
        if ($success && function_exists('fsync')) @fsync($handle);
        @flock($handle, LOCK_UN);
        @fclose($handle);
        return $success && $offset === $length;
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
