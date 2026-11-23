<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

require_once __DIR__ . '/includes/class-tr-mobile-installs.php';

$options = [
    'tr_mobile_app_key',
    'tr_mobile_min_app_version',
    'tr_mobile_token_secret',
    'tr_mobile_token_ttl',
    'tr_mobile_allowed_android_package',
    'tr_mobile_allowed_android_cert_sha256',
    'tr_mobile_allowed_ios_bundle_id',
    'tr_mobile_require_app_attestation',
    'tr_mobile_accept_shared_bearer',
    'tr_mobile_google_sa_json',
    'tr_mobile_play_cloud_project_number',
    'tr_mobile_apple_team_id',
    'tr_mobile_apple_app_id',
    'tr_mobile_staging_register_ips',
    'tr_mobile_require_attestation_on_token',
];
foreach ($options as $option) {
    delete_option($option);
}

TR_Mobile_Installs::drop_table();
