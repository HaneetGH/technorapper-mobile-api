<?php
/**
 * Plugin Name: TechnoRapper Mobile API
 * Description: Versioned, read-only API for TechnoRapper mobile clients.
 * Version: 0.1.0
 * Author: TechnoRapper
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

define('TR_MOBILE_API_VERSION', '0.1.0');
define('TR_MOBILE_API_NAMESPACE', 'technorapper/v1');

require_once __DIR__ . '/includes/class-tr-mobile-api.php';
require_once __DIR__ . '/includes/class-tr-mobile-auth.php';
require_once __DIR__ . '/includes/class-tr-mobile-rate-limit.php';

add_action('rest_api_init', function () {
    (new TR_Mobile_API())->register_routes();
});

add_filter('rest_pre_serve_request', ['TR_Mobile_API', 'serve_request_headers'], 10, 4);
