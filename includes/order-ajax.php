<?php
/**
 * The order screen's server side.
 *
 * Five endpoints, all guarded the same way: one nonce, one capability. They
 * are deliberately small — every rule they apply lives somewhere else, in
 * the shipping method or in the calculator registry, so there is no second
 * version of the pricing here to fall out of step with the first.
 *
 * @package DahuWeightShipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nonce action shared by every endpoint in this file.
 */
define( 'DAHU_WEIGHT_SHIPPING_NONCE', 'dahu_weight_shipping_order' );

/**
 * Checks the nonce and the capability, or ends the request.
 *
 * `manage_woocommerce` rather than `edit_shop_orders`: these endpoints read
 * zone configuration as well as orders, and that is the capability
 * WooCommerce itself requires for its shipping settings.
 *
 * @return void
 */
function dahu_weight_shipping_check_request() {

	check_ajax_referer( DAHU_WEIGHT_SHIPPING_NONCE, 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'dahu-weight-shipping' ) ) );
	}
}

/**
 * Reads the order named in the request, or ends the request.
 *
 * @return WC_Order
 */
function dahu_weight_shipping_request_order() {

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- dahu_weight_shipping_check_request() ran first.
	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$order    = $order_id ? wc_get_order( $order_id ) : false;

	if ( ! $order ) {
		wp_send_json_error( array( 'message' => __( 'Order not found.', 'dahu-weight-shipping' ) ) );
	}

	return $order;
}

/**
 * Reads the shipping line named in the request, or ends the request.
 *
 * @param WC_Order $order Order.
 * @return WC_Order_Item_Shipping
 */
function dahu_weight_shipping_request_shipping_item( $order ) {

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- dahu_weight_shipping_check_request() ran first.
	$item_id = isset( $_POST['shipping_order_item_id'] ) ? absint( $_POST['shipping_order_item_id'] ) : 0;
	$item    = $item_id ? $order->get_item( $item_id ) : false;

	if ( ! $item || ! is_a( $item, 'WC_Order_Item_Shipping' ) ) {
		wp_send_json_error( array( 'message' => __( 'Shipping line not found.', 'dahu-weight-shipping' ) ) );
	}

	return $item;
}

/**
 * Does this zone instance still exist?
 *
 * Asked of WooCommerce's own lookup, which reads the instance from the
 * database and returns false for one that has been deleted. ANY method's
 * instance counts: a method this plugin cannot price is still offered in the
 * picker, so an existence check narrowed to this plugin's own method would
 * refuse a perfectly good line.
 *
 * @param int $instance_id Zone instance id.
 * @return bool
 */
function dahu_weight_shipping_zone_instance_exists( $instance_id ) {

	$instance_id = absint( $instance_id );

	if ( ! $instance_id || ! class_exists( 'WC_Shipping_Zones' ) ) {
		return false;
	}

	return (bool) WC_Shipping_Zones::get_shipping_method( $instance_id );
}

/* -------------------------------------------------------------------------
 * The manual-cost lock
 * ---------------------------------------------------------------------- */

add_filter(
	'woocommerce_hidden_order_itemmeta',
	static function ( $hidden_meta ) {
		$hidden_meta[] = DAHU_WEIGHT_SHIPPING_MANUAL_COST_META;
		return $hidden_meta;
	}
);

/**
 * Has this shipping line's cost been edited by hand?
 *
 * Once it has, this plugin stops recomputing it — for good, until the line
 * is deleted and a new one created. A shop manager who overrides a price
 * does so for a reason the plugin cannot see, and quietly overwriting it on
 * the next recalculation is the kind of behaviour that makes people stop
 * trusting an automation entirely.
 *
 * @param WC_Order $order   Order.
 * @param int      $item_id Shipping line id.
 * @return bool
 */
function dahu_weight_shipping_is_cost_manual( $order, $item_id ) {

	$item = $order->get_item( $item_id );

	if ( ! $item || ! is_a( $item, 'WC_Order_Item_Shipping' ) ) {
		return false;
	}

	return 'yes' === $item->get_meta( DAHU_WEIGHT_SHIPPING_MANUAL_COST_META );
}

add_action( 'wp_ajax_dahu_weight_shipping_mark_manual', 'dahu_weight_shipping_ajax_mark_manual' );
/**
 * Marks a shipping line as edited by hand.
 *
 * @return void
 */
function dahu_weight_shipping_ajax_mark_manual() {

	dahu_weight_shipping_check_request();

	$order = dahu_weight_shipping_request_order();
	$item  = dahu_weight_shipping_request_shipping_item( $order );

	$item->update_meta_data( DAHU_WEIGHT_SHIPPING_MANUAL_COST_META, 'yes' );
	$item->save();

	wp_send_json_success();
}

/* -------------------------------------------------------------------------
 * Pricing
 * ---------------------------------------------------------------------- */

/**
 * Sends the outcome of a calculation.
 *
 * Every outcome is a SUCCESS carrying a verdict — a cost, 'manual' or
 * 'no_rate' — rather than an HTTP error. The browser has to tell "I could
 * not price this" apart from "something broke", and only the first of those
 * should leave the field alone without alarming anybody.
 *
 * The order weight travels WITH the price, at no extra cost, so the weight
 * panel refreshes itself on every successful calculation. A dedicated round
 * trip just to display a number would be a poor trade on a site where an
 * admin-ajax request takes seconds.
 *
 * @param WC_Order          $order          Order.
 * @param array|WP_Error    $result         Calculation outcome.
 * @param array<int, array> $items_with_qty Pairs of (order line, quantity).
 * @return void
 */
function dahu_weight_shipping_send_result( $order, $result, $items_with_qty ) {

	$weight = dahu_weight_shipping_order_total_weight( $items_with_qty );
	$shown  = dahu_weight_shipping_format_weight( $weight );

	if ( is_wp_error( $result ) ) {

		$code    = $result->get_error_code();
		$verdict = in_array( $code, array( 'manual', 'no_rate' ), true ) ? $code : 'error';

		if ( 'error' === $verdict ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				$verdict               => true,
				'message'              => $result->get_error_message(),
				'order_weight'         => $weight,
				'order_weight_display' => $shown,
			)
		);
	}

	wp_send_json_success(
		array(
			'cost'                 => $result['cost'],
			'details'              => $result['details'],
			'order_weight'         => $weight,
			'order_weight_display' => $shown,
		)
	);
}

add_action( 'wp_ajax_dahu_weight_shipping_calculate', 'dahu_weight_shipping_ajax_calculate' );
/**
 * Prices an existing shipping line — the "Recalculate" path.
 *
 * The method and instance are read FROM THE LINE, because that path carries
 * nothing else: the browser only knows which line it is.
 *
 * @return void
 */
function dahu_weight_shipping_ajax_calculate() {

	dahu_weight_shipping_check_request();

	$order = dahu_weight_shipping_request_order();
	$item  = dahu_weight_shipping_request_shipping_item( $order );

	// A cost edited by hand is never touched again: "Recalculate" then has to
	// behave exactly as WooCommerce's own does, recomputing tax and nothing
	// else.
	if ( dahu_weight_shipping_is_cost_manual( $order, $item->get_id() ) ) {
		wp_send_json_success( array( 'skip' => true ) );
	}

	$items_with_qty = dahu_weight_shipping_collect_items_with_live_qty( $order );

	dahu_weight_shipping_send_result(
		$order,
		dahu_weight_shipping_calculate(
			$order,
			$item->get_method_id(),
			(int) $item->get_instance_id(),
			$items_with_qty
		),
		$items_with_qty
	);
}

add_action( 'wp_ajax_dahu_weight_shipping_set_instance', 'dahu_weight_shipping_ajax_set_instance' );
/**
 * Attaches a freshly created shipping line to the exact zone instance chosen,
 * then prices it in the same request.
 *
 * WooCommerce's own select on the order screen can only set the method TYPE:
 * several instances of one type appear there as a single entry. The instance
 * therefore has to be written separately, once the line exists and its id is
 * known.
 *
 * set_instance_id() + save() is used, which is the intended API:
 * instance_id is a PROPERTY of WC_Order_Item_Shipping, persisted by the data
 * store. Writing a meta of the same name instead leaves the property empty,
 * and the property is what every other reader of get_instance_id() sees —
 * WooCommerce included, and third-party shipping tools with it.
 *
 * The method id and the title are persisted here too, not just the instance.
 * A line created by WooCommerce's native add_order_shipping request sits in
 * the database with both empty while the screen shows them filled: the
 * browser's own assignments only paint the DOM, and reach the server at the
 * next "Update". Anything reading the line in between sees a state that does
 * not match the screen.
 *
 * Pricing then follows in the SAME request, with the values already in hand
 * rather than read back from the line that was just written. Reading them
 * back is the only thing that ever made this need two round trips.
 *
 * @return void
 */
function dahu_weight_shipping_ajax_set_instance() {

	dahu_weight_shipping_check_request();

	$order = dahu_weight_shipping_request_order();
	$item  = dahu_weight_shipping_request_shipping_item( $order );

	$instance_id  = isset( $_POST['instance_id'] ) ? absint( $_POST['instance_id'] ) : 0;
	$method_id    = isset( $_POST['method_id'] ) ? sanitize_text_field( wp_unslash( $_POST['method_id'] ) ) : '';
	$method_title = isset( $_POST['method_title'] ) ? sanitize_text_field( wp_unslash( $_POST['method_title'] ) ) : '';

	if ( ! $instance_id ) {
		wp_send_json_error( array( 'message' => __( 'No shipping zone instance was given.', 'dahu-weight-shipping' ) ) );
	}

	/*
	 * The instance has to still exist in a zone, and that is checked BEFORE it
	 * is written to the line.
	 *
	 * The browser sends an instance id it read when the page was opened, or
	 * prefetched before that. An instance deleted from its zone in between
	 * leaves that id pointing at nothing, and writing it anyway binds the line
	 * to a dead instance for good: nothing can price it afterwards, and no
	 * screen says which instance it is waiting for. The same shape of failure
	 * has been seen in production elsewhere, where a deleted instance made a
	 * shipping price fall back to a grid nobody had configured.
	 *
	 * Refused instead, with the one instruction that resolves it. Nothing is
	 * written, so the line stays as WooCommerce created it and can be attached
	 * again once the picker has been reloaded.
	 */
	if ( ! dahu_weight_shipping_zone_instance_exists( $instance_id ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'That shipping zone instance no longer exists: it has been removed from its zone since this screen was opened. Reload the order and add the shipping line again.', 'dahu-weight-shipping' ),
			)
		);
	}

	$item->set_instance_id( $instance_id );

	// Written only when supplied: a call carrying nothing but the instance
	// must not blank out a title that is already there.
	if ( '' !== $method_id ) {
		$item->set_method_id( $method_id );
	}

	if ( '' !== $method_title ) {
		$item->set_method_title( $method_title );
	}

	$item->save();

	/*
	 * Persistence is acquired and saved before this point, so nothing below
	 * can undo it. Everything from here returns a success carrying a verdict,
	 * never a bare error — otherwise the browser would wrongly believe the
	 * line was left unattached.
	 */
	$items_with_qty = dahu_weight_shipping_collect_items_with_live_qty( $order );

	dahu_weight_shipping_send_result(
		$order,
		dahu_weight_shipping_calculate(
			$order,
			'' !== $method_id ? $method_id : $item->get_method_id(),
			$instance_id,
			$items_with_qty
		),
		$items_with_qty
	);
}

/* -------------------------------------------------------------------------
 * Zone instances available for this order
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_dahu_weight_shipping_zone_methods', 'dahu_weight_shipping_ajax_zone_methods' );
/**
 * Lists the shipping method instances of the zone matching this order's
 * destination.
 *
 * Only ENABLED instances, and each one marked with whether this plugin can
 * price it. An instance it cannot price is still offered: adding the line
 * and typing the price by hand is faster than WooCommerce's own flow, which
 * is reason enough to list it.
 *
 * @return void
 */
function dahu_weight_shipping_ajax_zone_methods() {

	dahu_weight_shipping_check_request();

	$country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';

	$zone = WC_Shipping_Zones::get_zone_matching_package(
		array(
			'destination' => array(
				'country'  => $country,
				'state'    => '',
				'postcode' => '',
			),
		)
	);

	if ( ! $zone ) {
		wp_send_json_success( array( 'methods' => array() ) );
	}

	$calculable = dahu_weight_shipping_calculable_method_ids();
	$methods    = array();

	// true: enabled instances only — offering one that is switched off in
	// the zone would make no sense.
	foreach ( $zone->get_shipping_methods( true ) as $method ) {

		$title = (string) $method->get_title();

		// Fallback when an instance has no title of its own: without it the
		// entry would be blank and unreadable in the list.
		if ( '' === trim( $title ) ) {
			$title = sprintf(
				/* translators: 1: shipping method name, 2: zone instance id. */
				__( '%1$s #%2$d', 'dahu-weight-shipping' ),
				$method->method_title,
				(int) $method->instance_id
			);
		}

		$methods[] = array(
			'id'          => $method->id,
			'instance_id' => (int) $method->instance_id,
			'title'       => $title,
			'calculable'  => in_array( $method->id, $calculable, true ),
		);
	}

	wp_send_json_success(
		array(
			'methods'   => $methods,
			'zone_name' => $zone->get_zone_name(),
		)
	);
}

/* -------------------------------------------------------------------------
 * The weight panel
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_dahu_weight_shipping_order_weight', 'dahu_weight_shipping_ajax_order_weight' );
/**
 * Returns the order's total weight from the quantities on screen.
 *
 * The ONLY network call dedicated to the weight panel, and it only fires
 * when somebody presses its button. The common case — a price calculation —
 * delivers the weight without a request of its own.
 *
 * @return void
 */
function dahu_weight_shipping_ajax_order_weight() {

	dahu_weight_shipping_check_request();

	$order  = dahu_weight_shipping_request_order();
	$weight = dahu_weight_shipping_order_total_weight(
		dahu_weight_shipping_collect_items_with_live_qty( $order )
	);

	wp_send_json_success(
		array(
			'order_weight'         => $weight,
			'order_weight_display' => dahu_weight_shipping_format_weight( $weight ),
		)
	);
}
