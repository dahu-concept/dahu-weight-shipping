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
 * A shop that genuinely wants them gone ticks "Delete this plugin's data
 * when it is deleted" on WooCommerce's own Shipping > Shipping options
 * screen, which writes `ubac_shipping_delete_data_on_uninstall`, before
 * deleting the plugin. Until 06/11/2026 this option was named
 * `ubac_shipping_remove_data_on_uninstall` and nothing anywhere wrote it, so
 * the branch below could never run: the promise was unreachable. The name now
 * carries the same `delete_data_on_uninstall` fragment as every other plugin
 * of the family, so one search finds them all.
 *
 * @package UbacShipping
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
function ubac_shipping_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/ubac-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'ubac_welcome_seen_ubac-shipping' );
	delete_option( 'ubac_welcome_pending_ubac-shipping' );
	delete_option( 'ubac_welcome_tour_ubac-shipping' );

	// Compared against 'yes', never read as a boolean. WooCommerce's checkbox
	// stores the STRING 'no' when it is unticked, and 'no' is truthy in PHP, so
	// `! get_option( ... )` would have deleted the data of every shop that left
	// the box alone -- the exact opposite of what the box is for.
	$ubac_shipping_wipe = ( 'yes' === get_option( 'ubac_shipping_delete_data_on_uninstall', 'no' ) );

	/*
	 * The flag itself is configuration, so it goes whatever it says -- and
	 * BEFORE the early return, which is what kept it behind: a shop that left
	 * the box unticked had WooCommerce write 'no' on its first save of the
	 * Shipping screen, and that row then outlived the plugin for good, while
	 * this file's own header promised there was no site-wide option left to
	 * clean up. Deleted here, the second pass of the get_sites() loop over the
	 * current site reads the default and returns, as before.
	 *
	 * The old name goes too: it was never written by anything, but a shop that
	 * followed the previous header and set it by hand would otherwise keep an
	 * orphan row that no longer does anything.
	 */
	delete_option( 'ubac_shipping_delete_data_on_uninstall' );
	delete_option( 'ubac_shipping_remove_data_on_uninstall' );

	if ( ! $ubac_shipping_wipe ) {
		return;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'woocommerce_order_itemmeta';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- order item meta has no API for deleting one key across every line, and this runs once, on uninstall.
	$wpdb->delete( $table, array( 'meta_key' => '_ubac_shipping_manual_cost' ) );
}

ubac_shipping_uninstall_site();

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
	$ubac_shipping_site_ids = get_sites(
		array(
			'fields'        => 'ids',
			'number'        => 0,
			'no_found_rows' => true,
		)
	);

	foreach ( $ubac_shipping_site_ids as $ubac_shipping_site_id ) {
		switch_to_blog( $ubac_shipping_site_id );
		ubac_shipping_uninstall_site();
		restore_current_blog();
	}
}
