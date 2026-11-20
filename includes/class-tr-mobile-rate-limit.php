<?php
if (!defined('ABSPATH')) exit;

final class TR_Mobile_Rate_Limit {
    public static function check(WP_REST_Request $request) {
        $route = $request->get_route();
        $bucket = sanitize_key(str_replace(['/technorapper/v1/', '/'], ['', '_'], $route));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'tr_api_rl_' . md5($ip . '|' . $bucket);
        $count = (int) get_transient($key);
        $limit = str_contains($route, '/search') ? 20 : 60;
        if ($count >= $limit) return new WP_Error('tr_rate_limited', 'Too many requests.', ['status' => 429]);
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }
}
