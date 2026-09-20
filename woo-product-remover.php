<?php
/**
 * Plugin Name:          Greateck Product Catalog Remover for WooCommerce
 * Plugin URI:           https://github.com/mcfarhat/Woo-Product-Remover
 * Description:          Remove all WooCommerce products, variations and their leftover data in one click. Runs in batches with a progress bar, so it will not time out on large catalogs.
 * Version:              2.0.1
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Mohammad Farhat
 * Author URI:           https://www.greateck.com
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woo-product-remover
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.1
 *
 * @package WooProductRemover
 */

defined( 'ABSPATH' ) || exit;

define( 'WPR_VERSION', '2.0.1' );
define( 'WPR_FILE', __FILE__ );
define( 'WPR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPR_URL', plugin_dir_url( __FILE__ ) );

require_once WPR_PATH . 'includes/class-wpr-remover.php';
require_once WPR_PATH . 'includes/class-wpr-admin.php';

/**
 * Declare compatibility with WooCommerce feature flags.
 *
 * This plugin only ever touches product data, never orders, so it is safe
 * under High-Performance Order Storage. Without this declaration WooCommerce
 * lists the plugin as "incompatible" and blocks users from enabling HPOS.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPR_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPR_FILE, true );
	}
);

/**
 * Boot the admin side.
 *
 * Translations are loaded automatically by WordPress for plugins hosted on
 * WordPress.org, so load_plugin_textdomain() is deliberately not called here:
 * calling it this early triggers the "just-in-time translation loading"
 * notice introduced in WordPress 6.7.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( is_admin() ) {
			WPR_Admin::instance()->init();
		}
	}
);
