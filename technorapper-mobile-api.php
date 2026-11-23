<?php
/**
 * Plugin Name: TechnoRapper Mobile API
 * Description: Versioned, read-only API for TechnoRapper mobile clients.
 * Version: 0.2.1
 * Author: TechnoRapper
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

define('TR_MOBILE_API_VERSION', '0.2.1');
define('TR_MOBILE_API_NAMESPACE', 'technorapper/v1');

require_once __DIR__ . '/includes/class-tr-mobile-api.php';
require_once __DIR__ . '/includes/class-tr-mobile-auth.php';
require_once __DIR__ . '/includes/class-tr-mobile-rate-limit.php';
require_once __DIR__ . '/includes/class-tr-mobile-settings.php';

add_action('rest_api_init', function () {
    (new TR_Mobile_API())->register_routes();
});

add_filter('rest_pre_serve_request', ['TR_Mobile_API', 'serve_request_headers'], 10, 4);

add_action('plugins_loaded', ['TR_Mobile_Settings', 'init']);

register_activation_hook(__FILE__, function () {
    TR_Mobile_Auth::ensure_token_secret();
});
