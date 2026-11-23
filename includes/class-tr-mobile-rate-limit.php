<?php
if (!defined('ABSPATH')) exit;

/**
 * Best-effort origin rate limit. Cloudflare must enforce the real limits.
 */
final class TR_Mobile_Rate_Limit {
    /**
     * @return true|WP_Error
     */
    public static function check(WP_REST_Request $request) {
        $route = (string) $request->get_route();
        $bucket = sanitize_key(str_replace(['/technorapper/v1/', '/'], ['', '_'], $route));
        if ($bucket === '') {
            $bucket = 'root';
        }

        $ip = self::client_ip();
        $key = 'tr_api_rl_' . md5($ip . '|' . $bucket);
        $count = (int) get_transient($key);

        if (str_contains($route, '/auth/token')) {
            $limit = 10;
        } elseif (str_contains($route, '/search')) {
            $limit = 20;
        } else {
            $limit = 60;
        }

        if ($count >= $limit) {
            return new WP_Error('tr_rate_limited', 'Too many requests.', [
                'status' => 429,
                'headers' => ['Retry-After' => '60'],
            ]);
        }

        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Prefer Cloudflare's connecting IP when present and well-formed.
     */
    public static function client_ip(): string {
        $cf = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']) : '';
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return 'unknown';
    }
}
