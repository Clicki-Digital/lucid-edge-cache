<?php

defined('ABSPATH') || exit;

final class LEC_Spaces {
    private const RETRY_OPTION = 'lec_spaces_retry_queue';

    public static function upload_html(string $key, string $html, array $settings): bool {
        $access = defined('LEC_SPACES_KEY') ? LEC_SPACES_KEY : '';
        $secret = defined('LEC_SPACES_SECRET') ? LEC_SPACES_SECRET : '';
        $bucket = trim((string) ($settings['spaces_bucket'] ?? ''));
        $region = trim((string) ($settings['spaces_region'] ?? 'syd1'));
        if (!$access || !$secret || !$bucket || !$region) {
            LEC_Cache::log('spaces_upload', 'configuration_error', $key);
            return false;
        }
        $prefix = trim((string) ($settings['spaces_prefix'] ?? ''), '/');
        $key = ($prefix ? $prefix . '/' : '') . ltrim($key, '/');
        $result = self::signed_request('PUT', $bucket, $region, $key, $html, $access, $secret);
        LEC_Cache::log('spaces_upload', $result ? 'success' : 'error', $key);
        if ($result) self::purge_cdn($settings, array('/' . $key));
        return $result;
    }

    public static function delete_html(string $key, array $settings): bool {
        $access = defined('LEC_SPACES_KEY') ? LEC_SPACES_KEY : '';
        $secret = defined('LEC_SPACES_SECRET') ? LEC_SPACES_SECRET : '';
        $bucket = trim((string) ($settings['spaces_bucket'] ?? ''));
        $region = trim((string) ($settings['spaces_region'] ?? 'syd1'));
        if (!$access || !$secret || !$bucket || !$region) return false;
        $prefix = trim((string) ($settings['spaces_prefix'] ?? ''), '/');
        $key = ($prefix ? $prefix . '/' : '') . ltrim($key, '/');
        $result = self::signed_request('DELETE', $bucket, $region, $key, '', $access, $secret);
        LEC_Cache::log('spaces_delete', $result ? 'success' : 'error', $key);
        if ($result) self::purge_cdn($settings, array('/' . $key));
        return $result;
    }

    public static function test(array $settings): bool {
        $key = LEC_Cache::site_host() . '/_lucid-edge-cache/health-check.html';
        $ok = self::upload_html($key, '<!doctype html><title>Lucid Edge Cache OK</title>', $settings);
        return $ok && self::delete_html($key, $settings);
    }

    public static function test_credentials(string $access, string $secret, array $settings): bool {
        $bucket = trim((string) ($settings['spaces_bucket'] ?? ''));
        $region = trim((string) ($settings['spaces_region'] ?? 'syd1'));
        if (!$access || !$secret || !$bucket || !$region) return false;
        $key = LEC_Cache::site_host() . '/_lucid-edge-cache/onboarding-test.html';
        $html = '<!doctype html><title>Lucid Edge Cache onboarding</title>';
        $ok = self::signed_request('PUT', $bucket, $region, $key, $html, $access, $secret);
        return $ok && self::signed_request('DELETE', $bucket, $region, $key, '', $access, $secret);
    }

    public static function queue_retry(string $operation, string $key, string $unused_body = ''): void {
        if (!in_array($operation, array('upload', 'delete'), true) || $key === '') return;
        $queue = (array) get_option(self::RETRY_OPTION, array());
        $id = hash('sha256', $operation . '|' . $key);
        $queue[$id] = array('operation' => $operation, 'key' => ltrim($key, '/'), 'attempts' => (int) ($queue[$id]['attempts'] ?? 0));
        update_option(self::RETRY_OPTION, $queue, false);
        if (!wp_next_scheduled('lec_spaces_retry_batch')) wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'lec_spaces_retry_batch');
        LEC_Cache::log('spaces_retry', 'queued', $operation . ' ' . $key);
    }

    public static function retry_batch(): void {
        if (get_transient('lec_spaces_retry_lock')) return;
        set_transient('lec_spaces_retry_lock', 1, 2 * MINUTE_IN_SECONDS);
        $queue = (array) get_option(self::RETRY_OPTION, array());
        $settings = LEC_Cache::settings();
        $batch = array_slice($queue, 0, 3, true);
        foreach ($batch as $id => $item) {
            unset($queue[$id]);
            $key = (string) ($item['key'] ?? '');
            $operation = (string) ($item['operation'] ?? '');
            $ok = false;
            if ($operation === 'delete') {
                $ok = self::delete_html($key, $settings);
            } elseif ($operation === 'upload') {
                $file = rtrim(LEC_CACHE_DIR, '/\\') . '/pages/' . ltrim($key, '/');
                if (is_readable($file)) {
                    $html = file_get_contents($file);
                    $ok = is_string($html) && self::upload_html($key, $html, $settings);
                } else {
                    LEC_Cache::log('spaces_retry', 'cancelled', $key . ' no longer exists locally');
                    $ok = true;
                }
            }
            if (!$ok) {
                $item['attempts'] = (int) ($item['attempts'] ?? 0) + 1;
                if ($item['attempts'] < 5) $queue[$id] = $item;
                else LEC_Cache::log('spaces_retry', 'abandoned', $operation . ' ' . $key);
            } else {
                LEC_Cache::log('spaces_retry', 'success', $operation . ' ' . $key);
            }
        }
        update_option(self::RETRY_OPTION, $queue, false);
        delete_transient('lec_spaces_retry_lock');
        if ($queue && !wp_next_scheduled('lec_spaces_retry_batch')) wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, 'lec_spaces_retry_batch');
    }

    private static function signed_request(string $method, string $bucket, string $region, string $key, string $body, string $access, string $secret): bool {
        $service = 's3';
        $host = $bucket . '.' . $region . '.digitaloceanspaces.com';
        $uri = '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $now = gmdate('Ymd\THis\Z'); $date = substr($now, 0, 8);
        $hash = hash('sha256', $body);
        $acl_header = $method === 'PUT' ? "x-amz-acl:public-read\n" : '';
        $headers = "content-type:text/html; charset=UTF-8\nhost:$host\n" . $acl_header . "x-amz-content-sha256:$hash\nx-amz-date:$now\n";
        $signed = $method === 'PUT' ? 'content-type;host;x-amz-acl;x-amz-content-sha256;x-amz-date' : 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonical = "$method\n$uri\n\n$headers\n$signed\n$hash";
        $scope = "$date/$region/$service/aws4_request";
        $to_sign = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canonical);
        $k_date = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $to_sign, $k_signing);
        $auth = "AWS4-HMAC-SHA256 Credential=$access/$scope, SignedHeaders=$signed, Signature=$signature";
        $response = wp_remote_request('https://' . $host . $uri, array(
            'method' => $method, 'timeout' => 15, 'body' => $body,
            'headers' => array_filter(array('Content-Type' => 'text/html; charset=UTF-8', 'Host' => $host, 'x-amz-acl' => $method === 'PUT' ? 'public-read' : '', 'x-amz-content-sha256' => $hash, 'x-amz-date' => $now, 'Authorization' => $auth, 'Cache-Control' => $method === 'PUT' ? 'public, max-age=300' : '')),
        ));
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300;
    }

    public static function purge_cdn(array $settings, array $files): bool {
        $token = defined('LEC_DO_API_TOKEN') ? LEC_DO_API_TOKEN : '';
        $cdn_id = trim((string) ($settings['do_cdn_id'] ?? ''));
        if (!$token || !$cdn_id) return false;
        $response = wp_remote_request('https://api.digitalocean.com/v2/cdn/endpoints/' . rawurlencode($cdn_id) . '/cache', array(
            'method' => 'DELETE', 'timeout' => 10,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('files' => $files)),
        ));
        if (is_wp_error($response)) {
            LEC_Cache::log('cdn_purge', 'error', $response->get_error_message());
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        LEC_Cache::log('cdn_purge', 'http_' . $code, implode(', ', $files));
        return in_array($code, array(200, 204), true);
    }
}
