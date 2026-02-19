<?php
/**
 * Plugin Name: Smart B2B Pricing for WooCommerce
 * Description: Complete B2B solution for WooCommerce: business registration with approval workflow and advanced customer-type pricing rules (global, category, brand, product, and user-level). Automatically ignores sale prices and calculates the lowest applicable wholesale price for approved B2B users.
 * Version: 1.2.4.4
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.1
 * WC tested up to: 10.4
 * Author: Adnan Habib
 * Author URI: https://freelancer.com/u/csehabiburr183
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: srp
 */

if (!defined('ABSPATH')) { exit; }

define('SRP_VERSION', '1.2.4.4');
define('SRP_PLUGIN_FILE', __FILE__);
define('SRP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SRP_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', SRP_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', SRP_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('order_attribution', SRP_PLUGIN_FILE, true);
    }
});

require_once SRP_PLUGIN_DIR . 'includes/class-srp-db.php';
require_once SRP_PLUGIN_DIR . 'includes/class-srp-taxonomies.php';
require_once SRP_PLUGIN_DIR . 'includes/class-srp-user.php';
require_once SRP_PLUGIN_DIR . 'includes/class-srp-pricing.php';
require_once SRP_PLUGIN_DIR . 'includes/class-srp-admin.php';
require_once SRP_PLUGIN_DIR . 'public/class-srp-shortcodes.php';

register_activation_hook(SRP_PLUGIN_FILE, function () {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(SRP_PLUGIN_FILE));
        wp_die(
            '<p><strong>Smart B2B Pricing</strong> requires WooCommerce to be installed and active.</p>',
            'WooCommerce required',
            ['back_link' => true]
        );
    }
    SRP_DB::activate();
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Smart B2B Pricing</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    SRP_Taxonomies::init();
    SRP_User::init();
    SRP_Pricing::init();
    SRP_Admin::init();
    SRP_Shortcodes::init();
});

add_filter('plugin_action_links_' . plugin_basename(SRP_PLUGIN_FILE), function ($links) {
    if (!current_user_can('manage_woocommerce')) return $links;
    $url = admin_url('admin.php?page=srp-smart-b2b-pricing');
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'srp') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
