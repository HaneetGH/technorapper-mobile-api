<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('tr_mobile_app_key');
delete_option('tr_mobile_min_app_version');
delete_option('tr_mobile_token_secret');
delete_option('tr_mobile_token_ttl');
