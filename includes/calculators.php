<?php
/**
 * Who can price a shipping line on a manually created order.
 *
 * WooCommerce prices shipping from the cart. On an order a shop manager
 * builds by hand in the admin there is no cart, so a shipping method's own
 * `calculate_shipping()` is usually unusable there: most read
 * `WC()->cart` directly and would see either nothing or — worse — the
 * logged-in administrator's own cart.
 *
 * This plugin's own method is written so that it does not: it reads the
 * package it is handed, whoever built it. That is what lets the admin screen
 * call the very same code the checkout calls, instead of reproducing its
 * pricing rules a second time. A reproduction is what produces the classic
 * failure of this kind of feature, where the price shown to the shop manager
 * and the price shown to the customer drift apart and neither screen says
 * which one is right.
 *
 * Other carriers' methods cannot be called that way, so rather than
 * reverse-engineering any of them, this file opens the door: a carrier — or
 * a bridge plugin for one — registers a calculator for its own method id and
 * decides for itself how to price an order. Everything a calculator needs is
 * handed to it, and the one rule that must not differ between carriers,
 * free shipping by coupon, is applied here for all of them.
 *
 * @package DahuWeightShipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every method whose price this plugin can work out, as method id =>
 * callable.
 *
 * A calculator is called as:
 *
 *     calculator( WC_Order $order, int $instance_id, array $items_with_qty )
 *
 * and returns either:
 *
 *  - array( 'cost' => float, 'details' => string[] ) — the cost excluding
 *    tax, plus any number of short human-readable lines explaining how it
 *    was reached, which the order screen shows to the shop manager;
 *  - a WP_Error, whose code says what to do next: 'manual' to leave the
 *    field to be filled by hand, 'no_rate' when the method simply does not
 *    apply to this order. Any other code is reported as an error.
 *
 * `$items_with_qty` holds pairs of (order line, quantity) reflecting the
 * quantities currently on screen, which are not necessarily the ones in the
 * database.
 *
 * @return array<string, callable>
 */
function dahu_weight_shipping_get_calculators() {

	$calculators = array(
		DAHU_WEIGHT_SHIPPING_METHOD_ID => 'dahu_weight_shipping_builtin_calculator',
	);

	/**
	 * Filters the calculators available on the order screen.
	 *
	 * Add an entry to teach this plugin how to price another carrier's
	 * shipping lines. The key is the shipping method id exactly as
	 * WooCommerce stores it on the line — `$item->get_method_id()`.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, callable> $calculators Method id => callable.
	 */
	$calculators = (array) apply_filters( 'dahu_weight_shipping_calculators', $calculators );

	return array_filter( $calculators, 'is_callable' );
}

/**
 * Method ids the order screen may offer to price automatically.
 *
 * @return array<int, string>
 */
function dahu_weight_shipping_calculable_method_ids() {
	return array_keys( dahu_weight_shipping_get_calculators() );
}

/**
 * Is a free-shipping coupon applied to this order?
 *
 * WHY THIS EXISTS. At checkout nobody needs to care: WooCommerce applies
 * coupon free shipping ABOVE the methods, in
 * WC_Shipping::calculate_shipping_for_package(), once the rates have come
 * back. No method has to handle it.
 *
 * On a manually created order that machinery does not run, and nothing wraps
 * the calls made here. So it is handled once, in one place, for every
 * calculator — rather than inside each of them, which is how the same order
 * ends up free on one carrier and charged on another with nothing on screen
 * to explain it.
 *
 * The ORDER is read, not the package's `applied_coupons`: the synthetic
 * package built for the admin screen carries that key empty, and only
 * because some third-party methods walk it without guarding it.
 *
 * @param WC_Order $order Order being edited.
 * @return bool
 */
function dahu_weight_shipping_order_has_free_shipping_coupon( $order ) {

	foreach ( $order->get_coupon_codes() as $code ) {

		// WC_Coupon's constructor takes a code directly. A coupon deleted
		// since it was applied yields an empty object whose
		// get_free_shipping() is false, so shipping stays charged — which is
		// the wanted behaviour.
		$coupon = new WC_Coupon( $code );

		if ( $coupon->get_free_shipping() ) {
			return true;
		}
	}

	return false;
}

/**
 * Works out the cost of one shipping line.
 *
 * Single entry point, and the single place where coupon free shipping is
 * applied.
 *
 * @param WC_Order          $order          Order being edited.
 * @param string            $method_id      Shipping method on the line.
 * @param int               $instance_id    Zone instance attached to the line.
 * @param array<int, array> $items_with_qty Pairs of (order line, quantity).
 * @return array{cost: float, details: array<int, string>}|WP_Error
 */
function dahu_weight_shipping_calculate( $order, $method_id, $instance_id, $items_with_qty ) {

	$calculators = dahu_weight_shipping_get_calculators();

	if ( ! isset( $calculators[ $method_id ] ) ) {
		return new WP_Error(
			'manual',
			__( 'The price for this shipping method has to be filled in by hand.', 'dahu-weight-shipping' )
		);
	}

	$result = call_user_func( $calculators[ $method_id ], $order, (int) $instance_id, $items_with_qty );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) || ! isset( $result['cost'] ) ) {
		return new WP_Error(
			'calculator_failed',
			__( 'The calculator for this shipping method returned nothing usable.', 'dahu-weight-shipping' )
		);
	}

	$cost    = (float) $result['cost'];
	$details = isset( $result['details'] ) ? array_values( (array) $result['details'] ) : array();

	if ( dahu_weight_shipping_order_has_free_shipping_coupon( $order ) ) {
		$cost      = 0.0;
		$details[] = __( 'cost brought down to zero by a free-shipping coupon', 'dahu-weight-shipping' );
	}

	return array(
		'cost'    => round( $cost, 2 ),
		'details' => $details,
	);
}

/**
 * Calculator for this plugin's own shipping method.
 *
 * Calls the SAME `calculate_shipping()` WooCommerce calls at checkout, on
 * the SAME zone instance the line is attached to. Not one pricing rule is
 * repeated here: the grid, the class costs, the class rules and free
 * shipping are read once, where they are written.
 *
 * @param WC_Order          $order          Order being edited.
 * @param int               $instance_id    Zone instance attached to the line.
 * @param array<int, array> $items_with_qty Pairs of (order line, quantity).
 * @return array{cost: float, details: array<int, string>}|WP_Error
 */
function dahu_weight_shipping_builtin_calculator( $order, $instance_id, $items_with_qty ) {

	/*
	 * The instance is read from the LINE itself, never guessed as "the first
	 * instance of this method in the zone". That guess is wrong the moment a
	 * shop runs two instances — one for bulky goods, one free above a
	 * threshold — and it produces a plausible price computed from the wrong
	 * grid, which is the worst possible outcome.
	 *
	 * So a line with no instance attached is refused rather than guessed at.
	 * That happens to a line added through WooCommerce's own button instead
	 * of this plugin's.
	 */
	if ( ! $instance_id ) {
		return new WP_Error(
			'manual',
			__( 'This line is not attached to any shipping zone instance, so its price cannot be worked out. Delete it and add it again with "Add shipping", or type the price in by hand.', 'dahu-weight-shipping' )
		);
	}

	$method = dahu_weight_shipping_get_method( $instance_id );

	if ( ! $method ) {
		return new WP_Error(
			'instance_not_found',
			__( 'No shipping method was found for that zone instance. It may have been removed from the zone since this line was created.', 'dahu-weight-shipping' )
		);
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the endpoint verified the nonce before calling.
	$live_country = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';

	$package = dahu_weight_shipping_build_order_package( $order, $items_with_qty, $live_country );

	$method->calculate_shipping( $package );

	if ( empty( $method->rates ) ) {
		/*
		 * A NORMAL outcome, not a failure: the instance's required classes
		 * are not satisfied, or an excluded class is in the order. This
		 * method simply does not apply.
		 *
		 * So no cost is returned at all. Writing 0 would read as free
		 * shipping, which is a different statement entirely.
		 */
		return new WP_Error(
			'no_rate',
			__( 'This method does not apply to what this order contains, because of its required or excluded shipping classes. The price has been left alone.', 'dahu-weight-shipping' )
		);
	}

	/*
	 * Only the cost excluding tax is read. The taxes add_rate() works out
	 * depend on the current customer, a context that is absent or wrong in
	 * the admin: tax stays the business of WooCommerce's own "Recalculate".
	 *
	 * A method may call add_rate() more than once, so the rate carrying this
	 * instance's own rate id is the one taken, falling back to the first.
	 */
	$rate_id = $method->get_rate_id();
	$rate    = isset( $method->rates[ $rate_id ] ) ? $method->rates[ $rate_id ] : reset( $method->rates );

	return array(
		'cost'    => (float) $rate->get_cost(),
		'details' => array(),
	);
}
