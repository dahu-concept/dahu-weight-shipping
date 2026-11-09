<?php
/**
 * Plugin Name:       DahuConcept Weight Shipping
 * Description:       A weight-based WooCommerce shipping method configurable per zone, with a per-zone price grid, shipping-class rules, and one-click shipping lines on manually created orders.
 * Version:           1.1.5
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahuconcept-weight-shipping
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 *
 * @package UbacShipping
 */

defined( 'ABSPATH' ) || exit;

define( 'UBAC_SHIPPING_VERSION', '1.1.5' );
define( 'UBAC_SHIPPING_FILE', __FILE__ );
define( 'UBAC_SHIPPING_PATH', plugin_dir_path( __FILE__ ) );
define( 'UBAC_SHIPPING_URL', plugin_dir_url( __FILE__ ) );

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
define( 'UBAC_SHIPPING_METHOD_ID', 'ubac_shipping' );

/**
 * Meta key written on a shipping line as soon as somebody edits its cost by
 * hand. Once present, this plugin never recomputes that line's cost again.
 */
define( 'UBAC_SHIPPING_MANUAL_COST_META', '_ubac_shipping_manual_cost' );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Modules
 * ---------------------------------------------------------------------- */

require_once UBAC_SHIPPING_PATH . 'includes/grid.php';
require_once UBAC_SHIPPING_PATH . 'includes/packages.php';
require_once UBAC_SHIPPING_PATH . 'includes/shipping-method.php';
require_once UBAC_SHIPPING_PATH . 'includes/calculators.php';
require_once UBAC_SHIPPING_PATH . 'includes/diagnostics.php';

if ( is_admin() ) {
	require_once UBAC_SHIPPING_PATH . 'includes/order-ajax.php';
	require_once UBAC_SHIPPING_PATH . 'includes/order-screen.php';
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
function ubac_shipping_settings_url() {
	return admin_url( 'admin.php?page=wc-settings&tab=shipping' );
}

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$page = sprintf(
			'<a href="%s">%s</a>',
			esc_url( ubac_shipping_settings_url() ),
			esc_html__( 'Shipping zones', 'dahuconcept-weight-shipping' )
		);
		array_unshift( $links, $page );
		return $links;
	}
);

/**
 * The uninstall choice, on WooCommerce's own Shipping options screen.
 *
 * This plugin has no settings screen of its own, on purpose (see above), so
 * the one global choice it does need goes on WooCommerce's screen rather than
 * in a second place that could disagree with the first. WooCommerce's settings
 * API writes the field's `id` straight to an option of that name, which is the
 * option `uninstall.php` reads.
 *
 * Off by default, and the description says what is lost, because the data in
 * question is a per-line decision a human made by hand.
 *
 * @param array $settings WooCommerce's shipping settings fields.
 * @return array
 */
function ubac_shipping_uninstall_setting( $settings ) {
	$settings[] = array(
		'title' => __( 'DahuConcept Weight Shipping', 'dahuconcept-weight-shipping' ),
		'type'  => 'title',
		'id'    => 'ubac_shipping_uninstall_title',
	);

	$settings[] = array(
		'title'    => __( 'Uninstalling', 'dahuconcept-weight-shipping' ),
		'desc'     => __( "Delete this plugin's data when the plugin is deleted", 'dahuconcept-weight-shipping' ),
		'desc_tip' => __( 'Off, and worth leaving off. What goes is the manually corrected shipping cost on every order line that has one — a price someone set by hand because the calculated one was wrong. Nothing reads those values without this plugin, and deleting the plugin is usually a step in reinstalling it.', 'dahuconcept-weight-shipping' ),
		'id'       => 'ubac_shipping_delete_data_on_uninstall',
		'type'     => 'checkbox',
		'default'  => 'no',
	);

	$settings[] = array(
		'type' => 'sectionend',
		'id'   => 'ubac_shipping_uninstall_title',
	);

	return $settings;
}

add_filter( 'woocommerce_shipping_settings', 'ubac_shipping_uninstall_setting' );

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( 'https://dahu-concept.fr' ),
			esc_html__( 'Support', 'dahuconcept-weight-shipping' )
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
require_once UBAC_SHIPPING_PATH . 'includes/ubac-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Ubac_Welcome::mark_activated( 'ubac-shipping' );
	}
);

add_action(
	'init',
	static function () {
		Ubac_Welcome::register(
			array(
				'slug'       => 'ubac-shipping',
				'name'       => __( 'Weight shipping', 'dahuconcept-weight-shipping' ),
				'tagline'    => __( 'Keeps the order weight right, so the shipping price is too.', 'dahuconcept-weight-shipping' ),
				'file'       => UBAC_SHIPPING_FILE,
				'version'    => UBAC_SHIPPING_VERSION,
				'textdomain' => 'ubac-shipping',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M56 18 L56 96 M26 96 L86 96',
						'M12 36 L100 36',
						'M12 36 L2 62 Q12 72 22 62 Z M100 36 L90 62 Q100 72 110 62 Z',
						'M43.0 2.0 H69.0 A5.0 5.0 0 0 1 74.0 7.0 V19.0 A5.0 5.0 0 0 1 69.0 24.0 H43.0 A5.0 5.0 0 0 1 38.0 19.0 V7.0 A5.0 5.0 0 0 1 43.0 2.0 Z',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'dahuconcept-weight-shipping' ),
					'done'   => __( 'Done', 'dahuconcept-weight-shipping' ),
					'skip'   => __( 'Skip', 'dahuconcept-weight-shipping' ),
					'of'     => __( 'of', 'dahuconcept-weight-shipping' ),
					'denied' => __( 'You are not allowed to view this page.', 'dahuconcept-weight-shipping' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Computed from the lines', 'dahuconcept-weight-shipping' ),
					'text'  => __( 'The weight follows what is actually in the order.', 'dahuconcept-weight-shipping' ),
				),
				array(
					'title' => __( 'Visible on the order', 'dahuconcept-weight-shipping' ),
					'text'  => __( 'Shown where somebody packing can check it.', 'dahuconcept-weight-shipping' ),
				),
				array(
					'title' => __( 'Recalculated on demand', 'dahuconcept-weight-shipping' ),
					'text'  => __( 'One button when the order changed.', 'dahuconcept-weight-shipping' ),
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
						'selector' => '#ubac-ws-weight-value',
						'title'    => __( 'The order weight', 'dahuconcept-weight-shipping' ),
						'text'     => __( 'Worked out from the lines of this order.', 'dahuconcept-weight-shipping' ),
					),
					array(
						'selector' => '#ubac-ws-weight-refresh',
						'title'    => __( 'Refresh after edits', 'dahuconcept-weight-shipping' ),
						'text'     => __( 'Click it once the quantities have changed.', 'dahuconcept-weight-shipping' ),
					),
					array(
						'selector' => '.ubac-ws-panel__note',
						'title'    => __( 'The weight that is priced', 'dahuconcept-weight-shipping' ),
						'text'     => __( 'This is the weight that enters the price grid.', 'dahuconcept-weight-shipping' ),
					),
				),
			)
		);
	}
);
