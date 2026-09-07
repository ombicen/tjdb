<?php

/**
 * Plugin Name: Top Jewellery Diamond Builder
 * Plugin URI: https://topjewellery.co.uk/
 * Description: Lets customers build a custom ring by picking a diamond-compatible setting and a live diamond from the Nivoda marketplace, combined into a single priced cart line item.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 8.0
 * Author: Top Jewellery
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: topjewellery-diamond-builder
 * Domain Path: /languages
 * Network: false
 *
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.4.2
 *
 * @package TopJewelleryDiamondBuilder
 */

if (! defined('ABSPATH')) {
    exit;
}

define('TJDB_VERSION', '1.0.0');
define('TJDB_PLUGIN_FILE', __FILE__);
define('TJDB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TJDB_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once TJDB_PLUGIN_DIR . 'includes/Plugin.php';

function tjdb_init()
{
    $plugin = new \TopJewelleryDiamondBuilder\Plugin(__FILE__);
    $plugin->run();
}
add_action('plugins_loaded', 'tjdb_init');

register_activation_hook(__FILE__, function () {
    add_option('tjdb_nivoda_settings', [
        'endpoint' => 'https://intg-customer-staging.nivodaapi.net/api/diamonds',
        'username' => '',
        'password' => '',
    ]);
    add_option('tjdb_margin_tiers', [
        ['carat_from' => 0.0, 'carat_to' => 0.5, 'margin_percent' => 30.0],
        ['carat_from' => 0.5, 'carat_to' => 1.0, 'margin_percent' => 25.0],
        ['carat_from' => 1.0, 'carat_to' => 2.0, 'margin_percent' => 20.0],
        ['carat_from' => 2.0, 'carat_to' => null, 'margin_percent' => 15.0],
    ]);
    add_option('tjdb_margin_default_percent', 25.0);
    add_option('tjdb_eligible_categories', []);
    add_option('tjdb_eligible_tags', []);

    tjdb_ensure_page('tjdb_archive_page_id', __('Diamond Ring Settings', 'topjewellery-diamond-builder'), '[tjdb_settings_archive]');
    tjdb_ensure_page('tjdb_builder_page_id', __('Build Your Own', 'topjewellery-diamond-builder'), '[tjdb_builder]');
});

/**
 * Creates the page holding $shortcode if the stored option doesn't already
 * point at a real, non-trashed page, and stores its ID in $option_name.
 */
function tjdb_ensure_page(string $option_name, string $title, string $shortcode): void
{
    $existing_id = (int) get_option($option_name, 0);

    if ($existing_id && get_post_status($existing_id) && get_post_status($existing_id) !== 'trash') {
        return;
    }

    $page_id = wp_insert_post([
        'post_title' => $title,
        'post_content' => $shortcode,
        'post_status' => 'publish',
        'post_type' => 'page',
    ]);

    if ($page_id && ! is_wp_error($page_id)) {
        update_option($option_name, $page_id);
    }
}
