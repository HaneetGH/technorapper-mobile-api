<?php
if (!defined('ABSPATH')) exit;

/**
 * App-key bootstrap + short-lived Bearer access tokens.
 *
 * Content routes require Authorization: Bearer <token>.
 * Only POST /auth/token accepts X-TR-App-Key to mint tokens.
 */
final class TR_Mobile_Auth {
    public const HEADER_APP_KEY = 'X-TR-App-Key';
    public const OPTION_APP_KEY = 'tr_mobile_app_key';
    public const OPTION_TOKEN_SECRET = 'tr_mobile_token_secret';
    public const OPTION_TOKEN_TTL = 'tr_mobile_token_ttl';

    private const DEFAULT_TTL = 3600;
    private const MIN_TTL = 300;
    private const MAX_TTL = 86400;

    /**
     * Mint a token. Requires a configured app key matching X-TR-App-Key.
     *
     * @return array|WP_Error
     */
    public static function issue_token(WP_REST_Request $request) {
        $configured = self::configured_app_key();
        if ($configured === '') {
            return new WP_Error(
                'tr_not_configured',
                'Mobile API is not configured. Set tr_mobile_app_key before use.',
                ['status' => 503]
            );
        }

        $provided = (string) $request->get_header(self::HEADER_APP_KEY);
        if ($provided === '' || !hash_equals($configured, $provided)) {
            return new WP_Error('tr_unauthorized', 'Invalid app credentials.', ['status' => 401]);
        }

        $ttl = self::token_ttl();
        $now = time();
        $payload = [
            'iss' => 'technorapper-mobile',
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => wp_generate_password(16, false, false),
        ];

        $token = self::encode_token($payload);
        if (is_wp_error($token)) {
            return $token;
        }

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'expires_at' => gmdate('c', $payload['exp']),
        ];
    }

    /**
     * Validate Bearer token for content routes. Fail closed if app key unset.
     *
     * @return true|WP_Error
     */
    public static function authenticate(WP_REST_Request $request) {
        if (self::configured_app_key() === '') {
            return new WP_Error(
                'tr_not_configured',
                'Mobile API is not configured. Set tr_mobile_app_key before use.',
                ['status' => 503]
            );
        }

        $token = self::bearer_token($request);
        if ($token === '') {
            return new WP_Error(
                'tr_unauthorized',
                'Missing or invalid Authorization Bearer token.',
                ['status' => 401]
            );
        }

        $payload = self::decode_token($token);
        if (is_wp_error($payload)) {
            return $payload;
        }

        return true;
    }

    public static function configured_app_key(): string {
        return (string) get_option(self::OPTION_APP_KEY, '');
    }

    public static function token_ttl(): int {
        $ttl = (int) get_option(self::OPTION_TOKEN_TTL, self::DEFAULT_TTL);
        return min(max($ttl, self::MIN_TTL), self::MAX_TTL);
    }

    public static function ensure_token_secret(): string {
        $secret = (string) get_option(self::OPTION_TOKEN_SECRET, '');
        if ($secret !== '' && strlen($secret) >= 32) {
            return $secret;
        }
        $secret = wp_generate_password(64, true, true);
        update_option(self::OPTION_TOKEN_SECRET, $secret, false);
        return $secret;
    }

    private static function bearer_token(WP_REST_Request $request): string {
        $header = (string) $request->get_header('Authorization');
        if ($header === '') {
            return '';
        }
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * @param array $payload
     * @return string|WP_Error
     */
    private static function encode_token(array $payload) {
        $secret = self::ensure_token_secret();
        $body = self::b64url(wp_json_encode($payload));
        if ($body === '') {
            return new WP_Error('tr_token_error', 'Unable to issue access token.', ['status' => 500]);
        }
        $sig = self::b64url(hash_hmac('sha256', $body, $secret, true));
        return $body . '.' . $sig;
    }

    /**
     * @return array|WP_Error
     */
    private static function decode_token(string $token) {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return new WP_Error('tr_unauthorized', 'Invalid access token.', ['status' => 401]);
        }

        [$body, $sig] = $parts;
        $secret = self::ensure_token_secret();
        $expected = self::b64url(hash_hmac('sha256', $body, $secret, true));
        if (!hash_equals($expected, $sig)) {
            return new WP_Error('tr_unauthorized', 'Invalid access token.', ['status' => 401]);
        }

        $json = self::b64url_decode($body);
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['exp'], $payload['iat'], $payload['iss'])) {
            return new WP_Error('tr_unauthorized', 'Invalid access token.', ['status' => 401]);
        }

        if (($payload['iss'] ?? '') !== 'technorapper-mobile') {
            return new WP_Error('tr_unauthorized', 'Invalid access token.', ['status' => 401]);
        }

        if ((int) $payload['exp'] < time()) {
            return new WP_Error('tr_token_expired', 'Access token expired.', ['status' => 401]);
        }

        return $payload;
    }

    private static function b64url(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $value): string {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
    }
}
