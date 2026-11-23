<?php
if (!defined('ABSPATH')) exit;

/**
 * App-only access controls: package allowlist, nonce challenge, attestation gate.
 */
final class TR_Mobile_App_Lock {
    public const OPTION_ANDROID_PACKAGE = 'tr_mobile_allowed_android_package';
    public const OPTION_ANDROID_CERTS = 'tr_mobile_allowed_android_cert_sha256';
    public const OPTION_IOS_BUNDLE = 'tr_mobile_allowed_ios_bundle_id';
    public const OPTION_REQUIRE_ATTESTATION = 'tr_mobile_require_app_attestation';
    public const OPTION_ACCEPT_SHARED_BEARER = 'tr_mobile_accept_shared_bearer';

    public static function accept_shared_bearer(): bool {
        // Default ON so existing apps keep working until admin locks production.
        $raw = get_option(self::OPTION_ACCEPT_SHARED_BEARER, '1');
        return (string) $raw === '1' || $raw === 1 || $raw === true;
    }

    public static function require_attestation(): bool {
        $raw = get_option(self::OPTION_REQUIRE_ATTESTATION, '0');
        return (string) $raw === '1' || $raw === 1 || $raw === true;
    }

    public static function allowed_android_package(): string {
        return trim((string) get_option(self::OPTION_ANDROID_PACKAGE, 'com.technorapper.app'));
    }

    public static function allowed_ios_bundle(): string {
        return trim((string) get_option(self::OPTION_IOS_BUNDLE, 'com.technorapper.app'));
    }

    /**
     * @return string[] lowercase hex digests without separators
     */
    public static function allowed_android_certs(): array {
        $raw = (string) get_option(self::OPTION_ANDROID_CERTS, '');
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $part) {
            $hex = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $part) ?? '');
            if ($hex !== '') {
                $out[] = $hex;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @return array{nonce:string,expires_at:string}
     */
    public static function issue_challenge(): array {
        $nonce = wp_generate_password(32, false, false);
        $expires = time() + 5 * MINUTE_IN_SECONDS;
        set_transient(self::nonce_key($nonce), [
            'expires' => $expires,
            'ip' => TR_Mobile_Rate_Limit::client_ip(),
        ], 5 * MINUTE_IN_SECONDS);

        return [
            'nonce' => $nonce,
            'expires_at' => gmdate('c', $expires),
        ];
    }

    /**
     * Consume nonce (single use).
     *
     * @return true|WP_Error
     */
    public static function consume_nonce(string $nonce) {
        $nonce = trim($nonce);
        if ($nonce === '' || strlen($nonce) < 16) {
            return new WP_Error('tr_invalid_nonce', 'Missing or invalid nonce. Call GET /auth/challenge first.', ['status' => 400]);
        }
        $key = self::nonce_key($nonce);
        $row = get_transient($key);
        delete_transient($key);
        if (!is_array($row) || empty($row['expires']) || (int) $row['expires'] < time()) {
            return new WP_Error('tr_invalid_nonce', 'Nonce expired or already used.', ['status' => 400]);
        }
        return true;
    }

    /**
     * Validate register body for app-only policy.
     *
     * @return true|WP_Error
     */
    public static function validate_register(WP_REST_Request $request) {
        $platform = sanitize_key((string) $request->get_param('platform'));
        $package = sanitize_text_field((string) ($request->get_param('package_name') ?: $request->get_param('bundle_id') ?: ''));
        $nonce = (string) $request->get_param('nonce');
        $attestation = $request->get_param('attestation');
        if (!is_array($attestation)) {
            $attestation = [];
        }

        $android_pkg = self::allowed_android_package();
        $ios_bundle = self::allowed_ios_bundle();
        $lock_packages = ($android_pkg !== '' || $ios_bundle !== '');
        $require = self::require_attestation();
        $staging_bypass = $require && TR_Mobile_Attestation::is_staging_ip();

        if ($lock_packages || $require) {
            $ok = self::consume_nonce($nonce);
            if (is_wp_error($ok)) {
                return $ok;
            }
        }

        if ($platform === 'android') {
            if ($android_pkg !== '') {
                if ($package === '' || !hash_equals($android_pkg, $package)) {
                    return new WP_Error(
                        'tr_app_not_allowed',
                        'This Android package is not allowed to register.',
                        ['status' => 403]
                    );
                }
            }
        } elseif ($platform === 'ios') {
            if ($ios_bundle !== '') {
                if ($package === '' || !hash_equals($ios_bundle, $package)) {
                    return new WP_Error(
                        'tr_app_not_allowed',
                        'This iOS bundle is not allowed to register.',
                        ['status' => 403]
                    );
                }
            }
        } elseif ($lock_packages || $require) {
            return new WP_Error('tr_invalid_platform', 'platform must be android or ios.', ['status' => 400]);
        }

        if ($require && $staging_bypass) {
            // Emulator / CI: valid app key required; skip Integrity/App Attest.
            return true;
        }

        if ($require) {
            $provider = sanitize_key((string) ($attestation['provider'] ?? ''));
            $token = (string) ($attestation['token'] ?? '');
            $assertion = (string) ($attestation['assertion'] ?? '');
            if (
                ($token === '' && $assertion === '')
                || ($provider !== 'play_integrity' && $provider !== 'app_attest')
            ) {
                return new WP_Error(
                    'tr_attestation_required',
                    'Valid Play Integrity / App Attest attestation is required to register.',
                    ['status' => 403]
                );
            }

            $verified = apply_filters('tr_mobile_verify_attestation', null, [
                'provider' => $provider,
                'token' => $token !== '' ? $token : $assertion,
                'assertion' => $assertion,
                'key_id' => (string) ($attestation['key_id'] ?? ''),
                'nonce' => $nonce,
                'platform' => $platform,
                'package_name' => $package,
                'cert_sha256' => sanitize_text_field((string) ($attestation['cert_sha256'] ?? '')),
                'request' => $request,
            ]);

            if ($verified === null) {
                return new WP_Error(
                    'tr_attestation_unverified',
                    'Attestation verification is required but not configured on the server.',
                    ['status' => 503]
                );
            }
            if ($verified !== true) {
                if (is_wp_error($verified)) {
                    return $verified;
                }
                return new WP_Error('tr_attestation_failed', 'App attestation failed.', ['status' => 403]);
            }

            // Cert allowlist is enforced inside Play Integrity verifier from token digests.
            // Client-supplied cert_sha256 is not trusted as the sole check when attestation ran.
        }

        return true;
    }

    /**
     * Optional Integrity / App Attest gate for POST /auth/token.
     *
     * @return true|WP_Error
     */
    public static function validate_token_attestation(WP_REST_Request $request, string $install_id) {
        if (!TR_Mobile_Attestation::require_on_token()) {
            return true;
        }
        if (TR_Mobile_Attestation::is_staging_ip()) {
            return true;
        }

        $nonce = (string) $request->get_param('nonce');
        $ok = self::consume_nonce($nonce);
        if (is_wp_error($ok)) {
            return $ok;
        }

        $attestation = $request->get_param('attestation');
        if (!is_array($attestation)) {
            $attestation = [];
        }
        $provider = sanitize_key((string) ($attestation['provider'] ?? ''));
        $token = (string) ($attestation['token'] ?? '');
        $assertion = (string) ($attestation['assertion'] ?? '');
        if (
            ($token === '' && $assertion === '')
            || ($provider !== 'play_integrity' && $provider !== 'app_attest')
        ) {
            return new WP_Error(
                'tr_attestation_required',
                'Fresh Play Integrity / App Attest attestation is required to mint a token.',
                ['status' => 403]
            );
        }

        $row = TR_Mobile_Installs::find($install_id);
        $package = $row && !empty($row->package_name) ? (string) $row->package_name : '';
        $platform = $row ? sanitize_key((string) $row->platform) : '';

        $verified = apply_filters('tr_mobile_verify_attestation', null, [
            'provider' => $provider,
            'token' => $token !== '' ? $token : $assertion,
            'assertion' => $assertion,
            'key_id' => (string) ($attestation['key_id'] ?? ($row->attest_key_id ?? '')),
            'nonce' => $nonce,
            'platform' => $platform,
            'package_name' => $package,
            'install_id' => $install_id,
            'public_key_pem' => $row ? (string) ($row->attest_public_key ?? '') : '',
            'request' => $request,
        ]);

        if ($verified === null) {
            return new WP_Error(
                'tr_attestation_unverified',
                'Attestation verification is required but not configured on the server.',
                ['status' => 503]
            );
        }
        if ($verified !== true) {
            if (is_wp_error($verified)) {
                return $verified;
            }
            return new WP_Error('tr_attestation_failed', 'App attestation failed.', ['status' => 403]);
        }
        return true;
    }

    private static function nonce_key(string $nonce): string {
        return 'tr_mob_nonce_' . md5($nonce);
    }
}
