<?php
/**
 * The weight price grid: its text format, its parser, and the lookup.
 *
 * The grid is stored as the text the shop manager typed, one bracket per
 * line, and parsed on read. Storing the text rather than a parsed array is
 * deliberate: it is what WooCommerce's own settings API hands over, it
 * survives a round trip through the settings screen without reformatting
 * what somebody wrote, and it keeps the stored value readable by a human
 * looking at the database during an incident.
 *
 * @package DahuWeightShipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starting grid, offered as the default on a freshly added zone instance.
 *
 * Deliberately not empty: an empty grid prices every weight at zero, which
 * looks like free shipping rather than like a method nobody has configured
 * yet. A visible starting point invites editing; a silent zero does not.
 *
 * @return array<int, array{min: float, max: float, price: float}>
 */
function dahu_weight_shipping_default_grid() {

	$grid = array(
		array(
			'min'   => 0,
			'max'   => 2,
			'price' => 10,
		),
		array(
			'min'   => 2,
			'max'   => 5,
			'price' => 15,
		),
		array(
			'min'   => 5,
			'max'   => 10,
			'price' => 20,
		),
		array(
			'min'   => 10,
			'max'   => 30,
			'price' => 35,
		),
	);

	/**
	 * Filters the grid offered as the default on a new zone instance.
	 *
	 * Only ever read when an instance has no grid of its own, so changing it
	 * never alters what an already configured zone charges.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, array{min: float, max: float, price: float}> $grid Brackets.
	 */
	return (array) apply_filters( 'dahu_weight_shipping_default_grid', $grid );
}

/**
 * Formats one number for the grid text: 18 rather than 18.00, 2.5 rather
 * than 2.500.
 *
 * Always a decimal point, never a comma. Input accepts both; storage
 * normalises to one form so the parser never has to guess which convention
 * a given line follows.
 *
 * @param float $value Number.
 * @return string
 */
function dahu_weight_shipping_format_grid_number( $value ) {
	return rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' );
}

/**
 * Turns a parsed grid back into the text format of the settings field.
 *
 * @param array<int, array> $grid Brackets.
 * @return string One bracket per line, "min-max:price".
 */
function dahu_weight_shipping_format_grid( $grid ) {

	$lines = array();

	foreach ( (array) $grid as $bracket ) {

		if ( ! isset( $bracket['min'], $bracket['max'], $bracket['price'] ) ) {
			continue;
		}

		$lines[] = dahu_weight_shipping_format_grid_number( $bracket['min'] )
			. '-' . dahu_weight_shipping_format_grid_number( $bracket['max'] )
			. ':' . dahu_weight_shipping_format_grid_number( $bracket['price'] );
	}

	return implode( "\n", $lines );
}

/**
 * Parses the grid text and returns the valid brackets.
 *
 * Malformed lines are skipped AND reported through $errors — never swallowed
 * in silence. The caller decides what to do with them: the settings screen
 * shows them to the shop manager, a front-end read ignores them. A grid that
 * silently drops a line prices orders from a grid nobody believes is in use.
 *
 * Decimal separator: both the point and the comma are accepted on input, so
 * a French keyboard does not need re-education; everything is normalised to
 * a float.
 *
 * @param string             $text   Field contents.
 * @param array<int, string> $errors Filled with a message per rejected line.
 * @return array<int, array{min: float, max: float, price: float}>
 */
function dahu_weight_shipping_parse_grid( $text, &$errors = array() ) {

	$grid   = array();
	$errors = array();

	$lines = preg_split( '/\r\n|\r|\n/', (string) $text );

	if ( ! is_array( $lines ) ) {
		return $grid;
	}

	foreach ( $lines as $number => $line ) {

		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		$pattern = '/^([0-9]+(?:[.,][0-9]+)?)\s*-\s*([0-9]+(?:[.,][0-9]+)?)\s*:\s*([0-9]+(?:[.,][0-9]+)?)$/';

		if ( ! preg_match( $pattern, $line, $matches ) ) {
			$errors[] = sprintf(
				/* translators: 1: line number, 2: the rejected line as typed. */
				__( 'Line %1$d ignored ("%2$s"): the expected format is "min-max:price", for example "0-2:18".', 'dahu-weight-shipping' ),
				$number + 1,
				$line
			);
			continue;
		}

		$min   = (float) str_replace( ',', '.', $matches[1] );
		$max   = (float) str_replace( ',', '.', $matches[2] );
		$price = (float) str_replace( ',', '.', $matches[3] );

		if ( $max <= $min ) {
			$errors[] = sprintf(
				/* translators: 1: line number, 2: the rejected line as typed. */
				__( 'Line %1$d ignored ("%2$s"): the maximum weight must be greater than the minimum weight.', 'dahu-weight-shipping' ),
				$number + 1,
				$line
			);
			continue;
		}

		$grid[] = array(
			'min'   => $min,
			'max'   => $max,
			'price' => $price,
		);
	}

	return $grid;
}

/**
 * Finds the price for a given weight.
 *
 * Bracket convention: the minimum is EXCLUSIVE, the maximum INCLUSIVE. So
 * "0-2:18" covers anything above 0 and up to 2 included. This matters, and
 * it is the one thing not to change casually: flipping it moves the price of
 * every order landing exactly on a boundary, which is a large share of real
 * orders, and nothing on screen would say why.
 *
 * Beyond the last bracket the last bracket's price applies, rather than
 * nothing. A grid that stops short would otherwise price a heavy order at
 * zero — a plausible-looking number that costs the shop money on every
 * order over the limit. Charging the top bracket is visibly wrong instead of
 * invisibly wrong, and a shop wanting to refuse those orders outright can
 * add an excluded class or a dedicated zone.
 *
 * @param float             $weight Weight, in the shop's weight unit.
 * @param array<int, array> $grid   Brackets to search.
 * @return float
 */
function dahu_weight_shipping_price_for_weight( $weight, $grid ) {

	if ( empty( $grid ) ) {
		return 0.0;
	}

	foreach ( $grid as $bracket ) {
		if ( $weight > (float) $bracket['min'] && $weight <= (float) $bracket['max'] ) {
			return (float) $bracket['price'];
		}
	}

	if ( $weight <= 0 ) {
		return 0.0;
	}

	$last = end( $grid );

	return isset( $last['price'] ) ? (float) $last['price'] : 0.0;
}
