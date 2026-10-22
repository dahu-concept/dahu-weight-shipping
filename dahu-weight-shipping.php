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

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once DAHU_WEIGHT_SHIPPING_PATH . 'includes/dahu-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Welcome::mark_activated( 'dahu-weight-shipping' );
	}
);

add_action(
	'init',
	static function () {
		Dahu_Welcome::register(
			array(
				'slug'       => 'dahu-weight-shipping',
				'name'       => __( 'Weight shipping', 'dahu-weight-shipping' ),
				'tagline'    => __( 'Keeps the order weight right, so the shipping price is too.', 'dahu-weight-shipping' ),
				'file'       => DAHU_WEIGHT_SHIPPING_FILE,
				'version'    => DAHU_WEIGHT_SHIPPING_VERSION,
				'textdomain' => 'dahu-weight-shipping',
				/*
				 * Les mots de l'accueil viennent d'ici : le fichier partagé est
				 * recopié dans toute la famille et ne peut donc porter aucun
				 * domaine de traduction.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'dahu-weight-shipping' ),
					'done'   => __( 'Done', 'dahu-weight-shipping' ),
					'skip'   => __( 'Skip', 'dahu-weight-shipping' ),
					'of'     => __( 'of', 'dahu-weight-shipping' ),
					'denied' => __( 'You are not allowed to view this page.', 'dahu-weight-shipping' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Computed from the lines', 'dahu-weight-shipping' ),
					'text'  => __( 'The weight follows what is actually in the order.', 'dahu-weight-shipping' ),
				),
				array(
					'title' => __( 'Visible on the order', 'dahu-weight-shipping' ),
					'text'  => __( 'Shown where somebody packing can check it.', 'dahu-weight-shipping' ),
				),
				array(
					'title' => __( 'Recalculated on demand', 'dahu-weight-shipping' ),
					'text'  => __( 'One button when the order changed.', 'dahu-weight-shipping' ),
				),
				),

				/*
				 * The one-time tour.
				 *
				 * This plugin has no screen of its own: its only interface is the
				 * "Shipping weight" panel on the order edit screen, so that is
				 * where the tour plays. The hook below is the order screen of a
				 * shop using high-performance order storage; a shop on the legacy
				 * storage edits orders on post.php and does not see the tour.
				 *
				 * Every selector points at markup the panel always renders: it
				 * holds no condition on data or settings. The "out of date"
				 * notice is left out on purpose, it is hidden until a quantity
				 * changes.
				 */
				'tour_screen' => 'woocommerce_page_wc-orders',
				'tour'        => array(
					array(
						'selector' => '#dahu-ws-weight-value',
						'title'    => __( 'The order weight', 'dahu-weight-shipping' ),
						'text'     => __( 'Worked out from the lines of this order.', 'dahu-weight-shipping' ),
					),
					array(
						'selector' => '#dahu-ws-weight-refresh',
						'title'    => __( 'Refresh after edits', 'dahu-weight-shipping' ),
						'text'     => __( 'Click it once the quantities have changed.', 'dahu-weight-shipping' ),
					),
					array(
						'selector' => '.dahu-ws-panel__note',
						'title'    => __( 'The weight that is priced', 'dahu-weight-shipping' ),
						'text'     => __( 'This is the weight that enters the price grid.', 'dahu-weight-shipping' ),
					),
				),
			)
		);
	}
);
