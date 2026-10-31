<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from the Plugins screen, never on
 * deactivation.
 *
 * This plugin stores almost nothing of its own, which is by design rather
 * than by luck: every setting belongs to a WooCommerce shipping zone
 * instance, and WooCommerce deletes those along with the zone that holds
 * them. There is no site-wide option to clean up.
 *
 * One thing is left behind on purpose. Each shipping line whose cost somebody
 * edited by hand carries a flag saying so, and those flags are KEPT. They are
 * inert without this plugin — nothing reads them — and an uninstall is very
 * often a step in a reinstall. Deleting them would quietly undo a decision
 * the shop made, line by line, and the next recalculation would overwrite
 * prices that had been corrected deliberately.
 *
 * A shop that genuinely wants them gone sets the
 * `dahu_weight_shipping_remove_data_on_uninstall` option to a truthy value
 * before deleting the plugin.
 *
 * @package DahuWeightShipping
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the manual-cost flags from every shipping line on one site.
 *
 * Only the flags written by this plugin, found by their own meta key. No
 * other order data is touched.
 *
 * @return void
 */
function dahu_weight_shipping_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/dahu-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'dahu_welcome_seen_dahu-weight-shipping' );
	delete_option( 'dahu_welcome_pending_dahu-weight-shipping' );
	delete_option( 'dahu_welcome_tour_dahu-weight-shipping' );

	if ( ! get_option( 'dahu_weight_shipping_remove_data_on_uninstall' ) ) {
		return;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'woocommerce_order_itemmeta';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- order item meta has no API for deleting one key across every line, and this runs once, on uninstall.
	$wpdb->delete( $table, array( 'meta_key' => '_dahu_weight_shipping_manual_cost' ) );

	delete_option( 'dahu_weight_shipping_remove_data_on_uninstall' );
}

dahu_weight_shipping_uninstall_site();

/*
 * On a multisite install with this plugin activated across the network, each
 * site is cleaned too — otherwise the flags would survive on every site but
 * the one the deletion happened to run on.
 */
if ( is_multisite() ) {

	/*
	 * `number => 0` for every site, not the first hundred: WP_Site_Query's
	 * own default caps the list at a hundred, and a network larger than that
	 * would keep this plugin's flags on every site past the cap — the welcome
	 * screen and the tour then stay silent for good there, with nothing on
	 * screen to explain why.
	 */
	$site_ids = get_sites(
		array(
			'fields'        => 'ids',
			'number'        => 0,
			'no_found_rows' => true,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		dahu_weight_shipping_uninstall_site();
		restore_current_blog();
	}
}
