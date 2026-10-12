<?php
/**
 * A single guard: say so when a cart ends up with no shipping method at all.
 *
 * @package DahuWeightShipping
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_package_rates', 'dahu_weight_shipping_warn_when_no_rate', 1000, 2 );
/**
 * Records that a cart was left without a single offerable shipping method,
 * which blocks checkout.
 *
 * Deliberately NO fallback rate is invented. A cart with no method means a
 * zone is configured incompletely, and making up a price would hide that
 * while charging the customer a figure matching no carrier contract. The
 * shop would discover it on the invoice, months later.
 *
 * Priority 1000: after every method in the zone, third-party ones included.
 * Logged once per request, because this filter runs several times per cart
 * or checkout render.
 *
 * @param array $rates   Available rates.
 * @param array $package WooCommerce shipping package.
 * @return array The rates, unchanged in every case.
 */
function dahu_weight_shipping_warn_when_no_rate( $rates, $package ) {

	static $already_logged = false;

	if ( ! empty( $rates ) || $already_logged ) {
		return $rates;
	}

	$country = isset( $package['destination']['country'] ) ? (string) $package['destination']['country'] : '';

	/**
	 * Filters the countries this guard watches.
	 *
	 * An empty array — the default — watches every country. A shop selling
	 * to a handful of countries and deliberately offering nothing elsewhere
	 * can list the ones it serves, so the log stays about its own shipping
	 * rather than filling up with carts it never meant to quote.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $countries ISO country codes. Empty means all.
	 */
	$watched = (array) apply_filters( 'dahu_weight_shipping_watched_countries', array() );

	if ( ! empty( $watched ) && ! in_array( $country, $watched, true ) ) {
		return $rates;
	}

	$already_logged = true;

	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log(
			sprintf(
				'[Dahu Weight Shipping] no shipping method available for this cart (destination: %s); check the shipping zone configuration.',
				'' !== $country ? $country : 'unknown'
			)
		);
	}

	/**
	 * Fires when a cart is left with no shipping method.
	 *
	 * Offered so a shop can react to it the way it wants — an email, an
	 * entry in its own monitoring — rather than only finding it in a log
	 * file nobody reads.
	 *
	 * @since 1.0.0
	 *
	 * @param array $package WooCommerce shipping package.
	 */
	do_action( 'dahu_weight_shipping_no_rate_available', $package );

	return $rates;
}
