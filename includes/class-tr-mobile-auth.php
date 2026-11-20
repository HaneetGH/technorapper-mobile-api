<?php
if (!defined('ABSPATH')) exit;

final class TR_Mobile_Auth {
    public static function authenticate(WP_REST_Request $request) {
        $key = $request->get_header('X-TR-App-Key');
        $expected = (string) get_option('tr_mobile_app_key', '');
        if ($expected === '') return true; // bootstrap-friendly default; enable key in production.
        if (!$key || !hash_equals($expected, $key)) {
            return new WP_Error('tr_unauthorized', 'Invalid app credentials.', ['status' => 401]);
        }
        return true;
    }
}
