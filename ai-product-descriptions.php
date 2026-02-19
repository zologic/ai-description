<?php
defined('ABSPATH') || exit;

/**
 * Plugin Name: AI Product Description Generator
 * Description: SEO-safe WooCommerce product descriptions. Enterprise Dutch prompts, similarity governance, Action Scheduler queue, provider abstraction, health monitoring.
 * Version: 6.0.0
 * Author: Your Store
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */

define('APDG_VERSION',    '6.0.0');
define('APDG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('APDG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('APDG_INCLUDES',   APDG_PLUGIN_DIR . 'includes/');

if (!file_exists(APDG_INCLUDES . 'class-loader.php')) {
    error_log('APDG: class-loader.php missing.');
    return;
}

require_once APDG_INCLUDES . 'class-loader.php';

register_activation_hook(__FILE__,   ['APDG_Loader', 'activate']);
register_deactivation_hook(__FILE__, ['APDG_Loader', 'deactivate']);

add_action('plugins_loaded', ['APDG_Loader', 'init']);