<?php
/**
 * Plugin Name:       Dahu Weight Shipping
 * Plugin URI:        https://dahu-concept.fr
 * Description:       A weight-based WooCommerce shipping method configurable per zone, with a per-zone price grid, shipping-class rules, and one-click shipping lines on manually created orders.
 * Version:           1.0.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-weight-shipping
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 *
 * @package DahuWeightShipping
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_WEIGHT_SHIPPING_VERSION', '1.0.0' );
define( 'DAHU_WEIGHT_SHIPPING_FILE', __FILE__ );
define( 'DAHU_WEIGHT_SHIPPING_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_WEIGHT_SHIPPING_URL', plugin_dir_url( __FILE__ ) );

/**
 * Shipping method identifier, as WooCommerce stores it on every zone instance
 * and on every order shipping line.
 *
 * Declared as a constant rather than read from the method class: that class
 * extends WC_Shipping_Method and therefore cannot be declared before
 * WooCommerce has loaded. Code that needs the identifier before then — the
 * admin screen, the AJAX endpoints — would otherwise depend on a load order,
 * which is exactly the kind of assumption that produces a fatal error on an
 * early request.
 */
define( 'DAHU_WEIGHT_SHIPPING_METHOD_ID', 'dahu_weight_shipping' );

/**
 * Meta key written on a shipping line as soon as somebody edits its cost by
 * hand. Once present, this plugin never recomputes that line's cost again.
 */
define( 'DAHU_WEIGHT_SHIPPING_MANUAL_COST_META', '_dahu_weight_shipping_manual_cost' );

/* -------------------------------------------------------------------------
 * Translations
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'dahu-weight-shipping',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

/* -------------------------------------------------------------------------
 * Modules
 * ---------------------------------------------------------------------- */

require_once DAHU_WEIGHT_SHIPPING_PATH . 'includes/grid.php';
require_once DAHU_WEIGHT_SHIPPING_PATH . 'includes/packages.php';
require_once DAHU_WEIGHT_SHIPPING_PATH . 'includes/shipping-method.php';
require_once DAHU_WEIGHT_SHIPPING_PATH . 'includes/calculators.php';
require_once DAHU_WEIGHT_SHIPPING_PATH . 'includes/diagnostics.php';

if ( is_admin() ) {
	require_once DAHU_WEIGHT_SHIPPING_PATH . 'includes/order-ajax.php';
	require_once DAHU_WEIGHT_SHIPPING_PATH . 'includes/order-screen.php';
}

/* -------------------------------------------------------------------------
 * Compatibility declarations
 * ---------------------------------------------------------------------- */

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/* -------------------------------------------------------------------------
 * Plugins screen
 * ---------------------------------------------------------------------- */

/**
 * URL of the WooCommerce shipping settings screen.
 *
 * This plugin deliberately has no settings screen of its own: every setting
 * belongs to a zone instance, and WooCommerce already owns that screen. A
 * second place to configure shipping would only invite the two to disagree.
 *
 * @return string
 */
function dahu_weight_shipping_settings_url() {
	return admin_url( 'admin.php?page=wc-settings&tab=shipping' );
}

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$page = sprintf(
			'<a href="%s">%s</a>',
			esc_url( dahu_weight_shipping_settings_url() ),
			esc_html__( 'Shipping zones', 'dahu-weight-shipping' )
		);
		array_unshift( $links, $page );
		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( 'https://dahu-concept.fr' ),
			esc_html__( 'Support', 'dahu-weight-shipping' )
		);

		return $links;
	},
	10,
	2
);
