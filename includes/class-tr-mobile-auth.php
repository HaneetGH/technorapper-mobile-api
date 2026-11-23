<?php
if (!defined('ABSPATH')) exit;

/**
 * Auth flow (production app-only):
 * 1) GET /auth/challenge → nonce
 * 2) POST /auth/register + Play Integrity / App Attest → install_id + install_secret
 * 3) POST /auth/token → short-lived Bearer with iid claim
 *
 * Staging: shared Bearer and/or app-key register when accept_shared_bearer / staging IPs allow.
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
     * Create a new long-lived mobile API token (stored as tr_mobile_app_key).
     */
    public static function generate_mobile_token(): string {
        $token = 'tr_mob_' . wp_generate_password(40, false, false);
        update_option(self::OPTION_APP_KEY, $token, false);
        return $token;
    }

    /**
     * @return array|WP_Error
     */
    public static function register_install(WP_REST_Request $request) {
        if (self::configured_app_key() === '') {
            return self::not_configured();
        }

        $require = TR_Mobile_App_Lock::require_attestation();
        $staging = $require && TR_Mobile_Attestation::is_staging_ip();

        if ($require && $staging) {
            $gate = self::require_app_key($request);
            if (is_wp_error($gate)) {
                return $gate;
            }
        } elseif (!$require) {
            // Legacy / staging without attestation: still require app key.
            $gate = self::require_app_key($request);
            if (is_wp_error($gate)) {
                return $gate;
            }
        }
        // Production attestation ON: no X-TR-App-Key path (except staging above).

        $lock = TR_Mobile_App_Lock::validate_register($request);
        if (is_wp_error($lock)) {
            return $lock;
        }

        $package = sanitize_text_field((string) ($request->get_param('package_name') ?: $request->get_param('bundle_id') ?: ''));
        $created = TR_Mobile_Installs::register([
            'platform' => (string) $request->get_param('platform'),
            'app_version' => (string) $request->get_param('app_version'),
            'device_label' => (string) $request->get_param('device_label'),
            'package_name' => $package,
            'attest_key_id' => (string) $request->get_param('_tr_attest_key_id'),
            'attest_public_key' => (string) $request->get_param('_tr_attest_public_key'),
        ]);
        if (is_wp_error($created)) {
            return $created;
        }

        return [
            'install_id' => $created['install_id'],
            'install_secret' => $created['install_secret'],
            'token_path' => '/' . TR_MOBILE_API_NAMESPACE . '/auth/token',
            'note' => 'Store install_secret securely. It is shown only once. Revoke from WP admin if compromised.',
        ];
    }

    /**
     * Mint a token using install_id + install_secret (JSON body or params).
     *
     * @return array|WP_Error
     */
    public static function issue_token(WP_REST_Request $request) {
        if (self::configured_app_key() === '') {
            return self::not_configured();
        }

        $install_id = trim((string) ($request->get_param('install_id') ?: ''));
        $install_secret = (string) ($request->get_param('install_secret') ?: '');
        if ($install_id === '' || $install_secret === '') {
            return new WP_Error(
                'tr_unauthorized',
                'install_id and install_secret are required.',
                ['status' => 401]
            );
        }

        $verified = TR_Mobile_Installs::verify_credentials($install_id, $install_secret);
        if (is_wp_error($verified)) {
            return $verified;
        }

        $attest = TR_Mobile_App_Lock::validate_token_attestation($request, $install_id);
        if (is_wp_error($attest)) {
            return $attest;
        }

        $ttl = self::token_ttl();
        $now = time();
        $payload = [
            'iss' => 'technorapper-mobile',
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => wp_generate_password(16, false, false),
            'iid' => $install_id,
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
            'install_id' => $install_id,
        ];
    }

    /**
     * Validate Bearer for content routes. Fail closed if no mobile token configured.
     *
     * @return true|WP_Error
     */
    public static function authenticate(WP_REST_Request $request) {
        $configured = self::configured_app_key();
        if ($configured === '') {
            return self::not_configured();
        }

        $token = self::bearer_token($request);
        if ($token === '') {
            return new WP_Error(
                'tr_unauthorized',
                'Missing or invalid Authorization Bearer token.',
                ['status' => 401]
            );
        }

        // Shared long-lived token — disable in production (app-only / install JWTs).
        if (hash_equals($configured, $token)) {
            if (!TR_Mobile_App_Lock::accept_shared_bearer()) {
                return new WP_Error(
                    'tr_shared_bearer_disabled',
                    'Shared Mobile API Token is disabled. Use a short-lived install access token.',
                    ['status' => 401]
                );
            }
            return true;
        }

        $payload = self::decode_token($token);
        if (is_wp_error($payload)) {
            return new WP_Error('tr_unauthorized', 'Invalid access token.', ['status' => 401]);
        }

        $install_id = (string) ($payload['iid'] ?? '');
        if ($install_id === '') {
            return new WP_Error('tr_unauthorized', 'Invalid access token.', ['status' => 401]);
        }

        $active = TR_Mobile_Installs::assert_active($install_id);
        if (is_wp_error($active)) {
            return $active;
        }

        return true;
    }

    /**
     * @return true|WP_Error
     */
    public static function require_app_key(WP_REST_Request $request) {
        $configured = self::configured_app_key();
        if ($configured === '') {
            return self::not_configured();
        }
        $provided = (string) $request->get_header(self::HEADER_APP_KEY);
        if ($provided === '' || !hash_equals($configured, $provided)) {
            return new WP_Error('tr_unauthorized', 'Invalid app credentials.', ['status' => 401]);
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

    private static function not_configured(): WP_Error {
        return new WP_Error(
            'tr_not_configured',
            'Mobile API is not configured. Generate a Mobile API Token under Settings → TR Mobile API.',
            ['status' => 503]
        );
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
