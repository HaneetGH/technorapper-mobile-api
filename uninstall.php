<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

require_once __DIR__ . '/includes/class-tr-mobile-installs.php';

delete_option('tr_mobile_app_key');
delete_option('tr_mobile_min_app_version');
delete_option('tr_mobile_token_secret');
delete_option('tr_mobile_token_ttl');
TR_Mobile_Installs::drop_table();
