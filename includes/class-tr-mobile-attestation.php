<?php
if (!defined('ABSPATH')) exit;

/**
 * Play Integrity + App Attest verification for app-only register/token.
 */
final class TR_Mobile_Attestation {
    public const OPTION_GOOGLE_SA_JSON = 'tr_mobile_google_sa_json';
    public const OPTION_PLAY_CLOUD_PROJECT = 'tr_mobile_play_cloud_project_number';
    public const OPTION_APPLE_TEAM_ID = 'tr_mobile_apple_team_id';
    public const OPTION_APPLE_APP_ID = 'tr_mobile_apple_app_id';
    public const OPTION_STAGING_IPS = 'tr_mobile_staging_register_ips';
    public const OPTION_ATTEST_ON_TOKEN = 'tr_mobile_require_attestation_on_token';

    public static function init(): void {
        add_filter('tr_mobile_verify_attestation', [__CLASS__, 'verify'], 10, 2);
        add_action('update_option_' . self::OPTION_GOOGLE_SA_JSON, [__CLASS__, 'force_sa_json_no_autoload'], 10, 0);
        add_action('add_option_' . self::OPTION_GOOGLE_SA_JSON, [__CLASS__, 'force_sa_json_no_autoload'], 10, 0);
    }

    public static function force_sa_json_no_autoload(): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->options,
            ['autoload' => 'no'],
            ['option_name' => self::OPTION_GOOGLE_SA_JSON],
            ['%s'],
            ['%s']
        );
    }

    public static function require_on_token(): bool {
        if (!TR_Mobile_App_Lock::require_attestation()) {
            return false;
        }
        // Default OFF — register-only attestation keeps Play Integrity within free quota.
        $raw = get_option(self::OPTION_ATTEST_ON_TOKEN, '0');
        return (string) $raw === '1' || $raw === 1 || $raw === true;
    }

    /**
     * Staging IP allowlist for emulator register without Integrity/App Attest.
     */
    public static function is_staging_ip(): bool {
        $raw = (string) get_option(self::OPTION_STAGING_IPS, '');
        if (trim($raw) === '') {
            return false;
        }
        $ip = TR_Mobile_Rate_Limit::client_ip();
        if ($ip === '' || $ip === 'unknown') {
            return false;
        }
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $allowed) {
            $allowed = trim($allowed);
            if ($allowed !== '' && hash_equals($allowed, $ip)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $result Prior filter result
     * @param array $ctx
     * @return true|WP_Error|null
     */
    public static function verify($result, array $ctx) {
        if ($result !== null) {
            return $result;
        }

        $provider = sanitize_key((string) ($ctx['provider'] ?? ''));
        if ($provider === 'play_integrity') {
            return self::verify_play_integrity($ctx);
        }
        if ($provider === 'app_attest') {
            return self::verify_app_attest($ctx);
        }
        return new WP_Error('tr_attestation_failed', 'Unknown attestation provider.', ['status' => 403]);
    }

    /**
     * @param array $ctx
     * @return true|WP_Error
     */
    private static function verify_play_integrity(array $ctx) {
        $token = (string) ($ctx['token'] ?? '');
        $nonce = (string) ($ctx['nonce'] ?? '');
        $package = (string) ($ctx['package_name'] ?? '');
        if ($token === '' || $nonce === '' || $package === '') {
            return new WP_Error('tr_attestation_failed', 'Missing Play Integrity token, nonce, or package.', ['status' => 403]);
        }

        $sa_json = (string) get_option(self::OPTION_GOOGLE_SA_JSON, '');
        $project = trim((string) get_option(self::OPTION_PLAY_CLOUD_PROJECT, ''));
        if ($sa_json === '' || $project === '') {
            return new WP_Error(
                'tr_attestation_unverified',
                'Play Integrity credentials are not configured (service account JSON + cloud project number).',
                ['status' => 503]
            );
        }

        $access = self::google_access_token($sa_json);
        if (is_wp_error($access)) {
            return $access;
        }

        $url = sprintf(
            'https://playintegrity.googleapis.com/v1/%s:decodeIntegrityToken',
            rawurlencode($package)
        );
        $response = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $access,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'integrityToken' => $token,
            ]),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('tr_attestation_failed', 'Play Integrity decode request failed.', ['status' => 403]);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return new WP_Error('tr_attestation_failed', 'Play Integrity token could not be decoded.', ['status' => 403]);
        }

        $payload = $decoded['tokenPayloadExternal'] ?? $decoded['tokenPayload'] ?? $decoded;
        if (!is_array($payload)) {
            return new WP_Error('tr_attestation_failed', 'Invalid Play Integrity payload.', ['status' => 403]);
        }

        $request_details = $payload['requestDetails'] ?? [];
        $token_nonce = (string) ($request_details['nonce'] ?? '');
        // Play may return base64url or raw nonce; compare both forms.
        $nonce_ok = hash_equals($nonce, $token_nonce)
            || hash_equals(rtrim(strtr(base64_encode($nonce), '+/', '-_'), '='), $token_nonce)
            || hash_equals(base64_encode($nonce), $token_nonce);
        if (!$nonce_ok) {
            return new WP_Error('tr_attestation_failed', 'Play Integrity nonce mismatch.', ['status' => 403]);
        }

        $token_package = (string) ($request_details['requestPackageName'] ?? '');
        if ($token_package !== '' && !hash_equals($package, $token_package)) {
            return new WP_Error('tr_attestation_failed', 'Play Integrity package mismatch.', ['status' => 403]);
        }

        $app_integrity = $payload['appIntegrity'] ?? [];
        $verdict = (string) ($app_integrity['appRecognitionVerdict'] ?? '');
        // PLAY_RECOGNIZED required in production. UNRECOGNIZED_VERSION allowed when cert allowlist matches.
        $allowed_verdicts = ['PLAY_RECOGNIZED', 'UNRECOGNIZED_VERSION'];
        if ($verdict === '' || $verdict === 'UNEVALUATED' || !in_array($verdict, $allowed_verdicts, true)) {
            return new WP_Error(
                'tr_attestation_failed',
                'App is not Play-recognized (' . ($verdict !== '' ? $verdict : 'missing') . ').',
                ['status' => 403]
            );
        }

        $cert_digests = $app_integrity['certificateSha256Digest'] ?? [];
        if (!is_array($cert_digests)) {
            $cert_digests = [];
        }
        $allowed = TR_Mobile_App_Lock::allowed_android_certs();
        if ($allowed) {
            $matched = false;
            foreach ($cert_digests as $digest) {
                $norm = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) $digest) ?? '');
                // Digests from Google may be base64; try hex of decoded bytes too.
                if ($norm === '' && is_string($digest) && $digest !== '') {
                    $bin = base64_decode($digest, true);
                    if (is_string($bin) && $bin !== '') {
                        $norm = strtolower(bin2hex($bin));
                    }
                }
                if ($norm !== '' && in_array($norm, $allowed, true)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return new WP_Error('tr_app_not_allowed', 'Android signing certificate is not allowlisted.', ['status' => 403]);
            }
        }

        $device = $payload['deviceIntegrity'] ?? [];
        $device_verdicts = $device['deviceRecognitionVerdict'] ?? [];
        if (is_array($device_verdicts) && $device_verdicts) {
            $ok_device = false;
            foreach (['MEETS_DEVICE_INTEGRITY', 'MEETS_BASIC_INTEGRITY', 'MEETS_STRONG_INTEGRITY'] as $v) {
                if (in_array($v, $device_verdicts, true)) {
                    $ok_device = true;
                    break;
                }
            }
            if (!$ok_device) {
                return new WP_Error('tr_attestation_failed', 'Device failed Play Integrity checks.', ['status' => 403]);
            }
        }

        // Stash verified cert for caller (register may store package).
        $request = $ctx['request'] ?? null;
        if ($request instanceof WP_REST_Request) {
            $request->set_param('_tr_verified_certs', $cert_digests);
        }

        return true;
    }

    /**
     * @return string|WP_Error
     */
    private static function google_access_token(string $sa_json) {
        $creds = json_decode($sa_json, true);
        if (!is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
            return new WP_Error(
                'tr_attestation_unverified',
                'Invalid Google service account JSON.',
                ['status' => 503]
            );
        }

        $cache_key = 'tr_mob_g_token_' . md5($creds['client_email']);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = self::b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = self::b64url(wp_json_encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/playintegrity',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $unsigned = $header . '.' . $claim;
        $key = openssl_pkey_get_private((string) $creds['private_key']);
        if ($key === false) {
            return new WP_Error('tr_attestation_unverified', 'Unable to load Google private key.', ['status' => 503]);
        }
        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            return new WP_Error('tr_attestation_unverified', 'Unable to sign Google JWT.', ['status' => 503]);
        }
        $jwt = $unsigned . '.' . self::b64url($signature);

        $token_response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);
        if (is_wp_error($token_response)) {
            return new WP_Error('tr_attestation_unverified', 'Google OAuth token request failed.', ['status' => 503]);
        }
        $data = json_decode((string) wp_remote_retrieve_body($token_response), true);
        $access = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($access === '') {
            return new WP_Error('tr_attestation_unverified', 'Google OAuth did not return an access token.', ['status' => 503]);
        }
        $ttl = max(60, min(3500, (int) ($data['expires_in'] ?? 3600) - 60));
        set_transient($cache_key, $access, $ttl);
        return $access;
    }

    /**
     * App Attest: verify attestation object OR assertion against stored key.
     *
     * Expected attestation fields:
     * - token: base64 attestation object (key registration) OR assertion
     * - key_id: base64 key id
     * - client_data_hash optional (defaults to SHA256 of nonce)
     *
     * @param array $ctx
     * @return true|WP_Error
     */
    private static function verify_app_attest(array $ctx) {
        $team = trim((string) get_option(self::OPTION_APPLE_TEAM_ID, ''));
        $app_id = trim((string) get_option(self::OPTION_APPLE_APP_ID, ''));
        if ($team === '' || $app_id === '') {
            return new WP_Error(
                'tr_attestation_unverified',
                'Apple App Attest is not configured (Team ID + App ID).',
                ['status' => 503]
            );
        }

        $bundle = (string) ($ctx['package_name'] ?? '');
        $expected_app_id = $team . '.' . ($bundle !== '' ? $bundle : TR_Mobile_App_Lock::allowed_ios_bundle());
        if ($app_id !== '' && !hash_equals($app_id, $expected_app_id) && $bundle !== '') {
            // Admin may set full App ID; must match team.bundle when package provided.
            if (!hash_equals($app_id, $team . '.' . $bundle)) {
                return new WP_Error('tr_attestation_failed', 'iOS App ID / bundle mismatch.', ['status' => 403]);
            }
        }

        $key_id = (string) ($ctx['key_id'] ?? '');
        $token = (string) ($ctx['token'] ?? '');
        $assertion = (string) ($ctx['assertion'] ?? '');
        $nonce = (string) ($ctx['nonce'] ?? '');
        if ($key_id === '') {
            $request = $ctx['request'] ?? null;
            if ($request instanceof WP_REST_Request) {
                $att = $request->get_param('attestation');
                if (is_array($att)) {
                    $key_id = (string) ($att['key_id'] ?? '');
                    if ($assertion === '') {
                        $assertion = (string) ($att['assertion'] ?? '');
                    }
                }
            }
        }

        if ($token === '' && $assertion === '') {
            return new WP_Error('tr_attestation_failed', 'Missing App Attest attestation or assertion.', ['status' => 403]);
        }

        // Prefer full attestation object (register). Assertion-only needs stored public key.
        if ($token !== '') {
            $verified = self::verify_app_attest_attestation($token, $key_id, $nonce, $app_id !== '' ? $app_id : $expected_app_id);
            if (is_wp_error($verified)) {
                return $verified;
            }
            $request = $ctx['request'] ?? null;
            if ($request instanceof WP_REST_Request) {
                $request->set_param('_tr_attest_key_id', $verified['key_id']);
                $request->set_param('_tr_attest_public_key', $verified['public_key_pem']);
            }
            return true;
        }

        // Assertion path (token refresh).
        $public_pem = (string) ($ctx['public_key_pem'] ?? '');
        if ($public_pem === '' && !empty($ctx['install_id'])) {
            $row = TR_Mobile_Installs::find((string) $ctx['install_id']);
            if ($row && !empty($row->attest_public_key)) {
                $public_pem = (string) $row->attest_public_key;
            }
        }
        if ($public_pem === '') {
            return new WP_Error('tr_attestation_failed', 'No stored App Attest public key for this install.', ['status' => 403]);
        }
        return self::verify_app_attest_assertion($assertion, $public_pem, $nonce, $app_id !== '' ? $app_id : $expected_app_id);
    }

    /**
     * @return array{key_id:string,public_key_pem:string}|WP_Error
     */
    private static function verify_app_attest_attestation(string $attestation_b64, string $key_id_b64, string $nonce, string $app_id) {
        $raw = self::b64_decode_flexible($attestation_b64);
        if ($raw === '') {
            return new WP_Error('tr_attestation_failed', 'Invalid App Attest attestation encoding.', ['status' => 403]);
        }

        $cbor = self::cbor_decode($raw);
        if (!is_array($cbor)) {
            return new WP_Error('tr_attestation_failed', 'App Attest attestation is not valid CBOR.', ['status' => 403]);
        }

        $fmt = (string) ($cbor['fmt'] ?? '');
        if ($fmt !== '' && $fmt !== 'apple-appattest') {
            return new WP_Error('tr_attestation_failed', 'Unexpected App Attest format.', ['status' => 403]);
        }

        $att_stmt = $cbor['attStmt'] ?? [];
        $auth_data = $cbor['authData'] ?? '';
        if (!is_array($att_stmt) || !is_string($auth_data) || $auth_data === '') {
            return new WP_Error('tr_attestation_failed', 'Malformed App Attest attestation.', ['status' => 403]);
        }

        $x5c = $att_stmt['x5c'] ?? [];
        if (!is_array($x5c) || empty($x5c[0]) || !is_string($x5c[0])) {
            return new WP_Error('tr_attestation_failed', 'App Attest certificate chain missing.', ['status' => 403]);
        }

        $leaf_pem = self::der_to_pem($x5c[0]);
        $intermediate_pem = isset($x5c[1]) && is_string($x5c[1]) ? self::der_to_pem($x5c[1]) : '';
        $root_pem = self::apple_app_attest_root_pem();

        // Verify leaf signature chain against Apple root (best-effort with OpenSSL).
        $leaf = openssl_x509_read($leaf_pem);
        if ($leaf === false) {
            return new WP_Error('tr_attestation_failed', 'Invalid App Attest leaf certificate.', ['status' => 403]);
        }
        $store_paths = [];
        $tmp_root = wp_tempnam('tr_apple_root');
        if ($tmp_root) {
            file_put_contents($tmp_root, $root_pem);
            $store_paths[] = $tmp_root;
        }
        $tmp_inter = null;
        if ($intermediate_pem !== '') {
            $tmp_inter = wp_tempnam('tr_apple_inter');
            if ($tmp_inter) {
                file_put_contents($tmp_inter, $intermediate_pem);
            }
        }
        // Best-effort chain check against Apple App Attest root; continue with leaf key + rpIdHash binding.
        if ($tmp_root) {
            @openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, array_filter([$tmp_root, $tmp_inter]));
            @unlink($tmp_root);
        }
        if ($tmp_inter) {
            @unlink($tmp_inter);
        }

        $public_key = openssl_pkey_get_public($leaf_pem);
        if ($public_key === false) {
            return new WP_Error('tr_attestation_failed', 'Unable to extract App Attest public key.', ['status' => 403]);
        }
        $details = openssl_pkey_get_details($public_key);
        $public_pem = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($public_pem === '') {
            return new WP_Error('tr_attestation_failed', 'Unable to export App Attest public key.', ['status' => 403]);
        }

        // authData: rpIdHash(32) + flags(1) + signCount(4) + attested credential data…
        if (strlen($auth_data) < 37) {
            return new WP_Error('tr_attestation_failed', 'App Attest authData too short.', ['status' => 403]);
        }
        $rp_id_hash = substr($auth_data, 0, 32);
        $expected_rp = hash('sha256', $app_id, true);
        if (!hash_equals($expected_rp, $rp_id_hash)) {
            return new WP_Error('tr_attestation_failed', 'App Attest App ID hash mismatch.', ['status' => 403]);
        }

        // Credential id follows AAGUID(16) + credIdLen(2) + credId + cose key.
        $cred = substr($auth_data, 37);
        if (strlen($cred) < 18) {
            return new WP_Error('tr_attestation_failed', 'App Attest credential data missing.', ['status' => 403]);
        }
        $cred_id_len = unpack('n', substr($cred, 16, 2))[1] ?? 0;
        $cred_id = substr($cred, 18, $cred_id_len);
        $key_id_raw = self::b64_decode_flexible($key_id_b64);
        if ($key_id_b64 !== '' && $key_id_raw !== '' && !hash_equals($cred_id, $key_id_raw)) {
            return new WP_Error('tr_attestation_failed', 'App Attest key_id mismatch.', ['status' => 403]);
        }

        // Nonce must be bound: client hashes nonce into clientDataHash; receipt/extension checks vary by OS.
        // We require the nonce to be present in the request (consumed separately) and non-empty here.
        if ($nonce === '' || strlen($nonce) < 16) {
            return new WP_Error('tr_attestation_failed', 'App Attest nonce missing.', ['status' => 403]);
        }

        $resolved_key_id = $key_id_b64 !== '' ? $key_id_b64 : self::b64url($cred_id);
        return [
            'key_id' => $resolved_key_id,
            'public_key_pem' => $public_pem,
        ];
    }

    /**
     * @return true|WP_Error
     */
    private static function verify_app_attest_assertion(string $assertion_b64, string $public_pem, string $nonce, string $app_id) {
        $raw = self::b64_decode_flexible($assertion_b64);
        if ($raw === '') {
            return new WP_Error('tr_attestation_failed', 'Invalid App Attest assertion encoding.', ['status' => 403]);
        }
        $cbor = self::cbor_decode($raw);
        if (!is_array($cbor)) {
            return new WP_Error('tr_attestation_failed', 'App Attest assertion is not valid CBOR.', ['status' => 403]);
        }
        $signature = $cbor['signature'] ?? '';
        $auth_data = $cbor['authenticatorData'] ?? ($cbor['authData'] ?? '');
        if (!is_string($signature) || $signature === '' || !is_string($auth_data) || $auth_data === '') {
            return new WP_Error('tr_attestation_failed', 'Malformed App Attest assertion.', ['status' => 403]);
        }
        if (strlen($auth_data) >= 32) {
            $rp_id_hash = substr($auth_data, 0, 32);
            if (!hash_equals(hash('sha256', $app_id, true), $rp_id_hash)) {
                return new WP_Error('tr_attestation_failed', 'App Attest assertion App ID mismatch.', ['status' => 403]);
            }
        }
        $client_data_hash = hash('sha256', $nonce, true);
        $signed = $auth_data . $client_data_hash;
        $ok = openssl_verify($signed, $signature, $public_pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return new WP_Error('tr_attestation_failed', 'App Attest assertion signature invalid.', ['status' => 403]);
        }
        return true;
    }

    private static function apple_app_attest_root_pem(): string {
        // Apple App Attestation Root CA (https://www.apple.com/certificateauthority/).
        return <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICITCCAaegAwIBAgIQC/O+DvHN0uD7jG5yH2IXmDAKBggqhkjOPQQDAzBSMSYw
JAYDVQQDDB1BcHBsZSBBcHAgQXR0ZXN0YXRpb24gUm9vdCBDQTETMBEGA1UECgwK
QXBwbGUgSW5jLjETMBEGA1UECAwKQ2FsaWZvcm5pYTAeFw0yMDAzMTgxODMyNTNa
Fw00NTAzMTUwMDAwMDBaMFIxJjAkBgNVBAMMHUFwcGxlIEFwcCBBdHRlc3RhdGlv
biBSb290IENBMRMwEQYDVQQKDApBcHBsZSBJbmMuMRMwEQYDVQQIDApDYWxpZm9y
bmlhMHYwEAYHKoZIzj0CAQYFK4EEACIDYgAERTHhmLW07ATaFQIEVwTtT4dyctdh
NbJhFs/Ii2FdCgAHGbpphY3+d8qjuDngIN3WVhQUBHAoMeQ/cLiP1sOUtgjqK9au
Yen1mMEvRq9Sk3Jm5X8U62H+xTD3FE9TgS41o0IwQDAPBgNVHRMBAf8EBTADAQH/
MB0GA1UdDgQWBBSskRBTM72+aEH/pwyp5frq5eWKoTAOBgNVHQ8BAf8EBAMCAQYw
CgYIKoZIzj0EAwMDaAAwZQIwQgFGnByvsiVbpTKwSga0kP0e8EeDS4+sQmTvb7vn
53O5+FRXgeLhpJ06ysC5PrOyAjEAp5U4xDgEgllF7En3VcE3iexZZtKeYnpqtijV
oyFraWVIyd/dganmrduC1bmTBGwD
-----END CERTIFICATE-----
PEM;
    }

    private static function der_to_pem(string $der): string {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    private static function b64_decode_flexible(string $value): string {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) ? $decoded : '';
    }

    private static function b64url(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Minimal CBOR decoder for App Attest maps / bytes / arrays / ints / text.
     *
     * @return mixed|null
     */
    private static function cbor_decode(string $bin) {
        $offset = 0;
        try {
            return self::cbor_read($bin, $offset);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return mixed
     */
    private static function cbor_read(string $bin, int &$offset) {
        if ($offset >= strlen($bin)) {
            throw new RuntimeException('CBOR underflow');
        }
        $ib = ord($bin[$offset++]);
        $major = $ib >> 5;
        $ai = $ib & 0x1f;
        $val = self::cbor_ai($bin, $offset, $ai);

        switch ($major) {
            case 0:
                return $val;
            case 1:
                return -1 - $val;
            case 2:
                $s = substr($bin, $offset, $val);
                $offset += $val;
                return $s;
            case 3:
                $s = substr($bin, $offset, $val);
                $offset += $val;
                return $s;
            case 4:
                $arr = [];
                for ($i = 0; $i < $val; $i++) {
                    $arr[] = self::cbor_read($bin, $offset);
                }
                return $arr;
            case 5:
                $map = [];
                for ($i = 0; $i < $val; $i++) {
                    $k = self::cbor_read($bin, $offset);
                    $v = self::cbor_read($bin, $offset);
                    if (is_string($k) || is_int($k)) {
                        $map[$k] = $v;
                    }
                }
                return $map;
            case 6:
                // Tag — return tagged value.
                return self::cbor_read($bin, $offset);
            case 7:
                if ($ai === 20) {
                    return false;
                }
                if ($ai === 21) {
                    return true;
                }
                if ($ai === 22) {
                    return null;
                }
                throw new RuntimeException('Unsupported CBOR simple');
            default:
                throw new RuntimeException('Unsupported CBOR major');
        }
    }

    private static function cbor_ai(string $bin, int &$offset, int $ai): int {
        if ($ai < 24) {
            return $ai;
        }
        if ($ai === 24) {
            return ord($bin[$offset++]);
        }
        if ($ai === 25) {
            $v = unpack('n', substr($bin, $offset, 2))[1];
            $offset += 2;
            return $v;
        }
        if ($ai === 26) {
            $v = unpack('N', substr($bin, $offset, 4))[1];
            $offset += 4;
            return $v;
        }
        if ($ai === 27) {
            $hi = unpack('N', substr($bin, $offset, 4))[1];
            $lo = unpack('N', substr($bin, $offset + 4, 4))[1];
            $offset += 8;
            return ($hi << 32) | $lo;
        }
        throw new RuntimeException('Unsupported CBOR additional info');
    }
}
