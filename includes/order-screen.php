<?php
/**
 * The order screen: the weight panel, and the assets driving the rest.
 *
 * @package DahuWeightShipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * Screen ids of the order edit screen.
 *
 * BOTH are returned. A shop may store orders the modern way or the legacy
 * way, and switching between them is a checkbox in WooCommerce's settings:
 * targeting one screen only would make this plugin's panel and button vanish
 * without a single message to say why.
 *
 * wc_get_page_screen_id() rather than the string 'woocommerce_page_wc-orders'
 * written out: that identifier is an implementation detail of WooCommerce,
 * not a contract.
 *
 * @return array<int, string>
 */
function dahu_weight_shipping_order_screen_ids() {

	$screens = array( 'shop_order' );

	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$screens[] = wc_get_page_screen_id( 'shop-order' );
	}

	return array_values( array_unique( array_filter( $screens ) ) );
}

/**
 * Are we on the order edit screen?
 *
 * @return bool
 */
function dahu_weight_shipping_is_order_screen() {

	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}

	$screen = get_current_screen();

	return $screen && in_array( $screen->id, dahu_weight_shipping_order_screen_ids(), true );
}

/* -------------------------------------------------------------------------
 * The weight panel
 * ---------------------------------------------------------------------- */

add_action( 'add_meta_boxes', 'dahu_weight_shipping_register_weight_panel' );
/**
 * Declares the panel on both order screens.
 *
 * @return void
 */
function dahu_weight_shipping_register_weight_panel() {

	foreach ( dahu_weight_shipping_order_screen_ids() as $screen ) {
		add_meta_box(
			'dahu_weight_shipping_order_weight',
			__( 'Shipping weight', 'dahu-weight-shipping' ),
			'dahu_weight_shipping_render_weight_panel',
			$screen,
			'side',
			'default'
		);
	}
}

/**
 * The panel's contents.
 *
 * WHY IT IS NOT LIVE. The weight cannot be worked out in the browser: neither
 * the unit weights nor the kit meta appear anywhere in the order screen's
 * markup. Every refresh is therefore a round trip to the server, and on a
 * modest host an admin-ajax request takes seconds. Refreshing on every
 * keystroke in a quantity field would make the screen unusable.
 *
 * Hence the trade: the weight travels free with every price calculation, a
 * quantity change marks it stale without calling anything, and one button
 * makes the only dedicated request — when somebody asks for it.
 *
 * The number shown at page load is computed from the quantities IN THE
 * DATABASE, the only source available at that moment. It goes stale as soon
 * as a quantity is edited on screen, which is what the stale marker says.
 *
 * @param WP_Post|WC_Order $post_or_order Object supplied by WooCommerce, whose
 *                                        type depends on which screen is in use.
 * @return void
 */
function dahu_weight_shipping_render_weight_panel( $post_or_order ) {

	$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );

	$items = $order ? dahu_weight_shipping_collect_items_with_live_qty( $order ) : array();

	$weight = $order ? dahu_weight_shipping_order_total_weight( $items ) : 0;

	$unit = get_option( 'woocommerce_weight_unit', 'kg' );

	?>
	<div class="dahu-ui dahu-ws-panel" id="dahu-ws-weight-panel">
		<p class="dahu-ws-panel__figure">
			<strong class="dahu-ws-panel__value" id="dahu-ws-weight-value"><?php echo esc_html( dahu_weight_shipping_format_weight( $weight ) ); ?></strong>
			<span class="dahu-ws-panel__unit"><?php echo esc_html( $unit ); ?></span>
		</p>
		<p class="dahu-ws-panel__stale" id="dahu-ws-weight-stale" hidden>
			<?php esc_html_e( 'Quantities have changed on screen, so this weight is out of date.', 'dahu-weight-shipping' ); ?>
		</p>
		<p class="dahu-ws-panel__actions">
			<button type="button" class="dahu-ui-btn" id="dahu-ws-weight-refresh">
				<?php esc_html_e( 'Refresh', 'dahu-weight-shipping' ); ?>
			</button>
		</p>
		<p class="dahu-ws-panel__note">
			<?php
			/*
			 * A new order has no lines yet, and the panel's own sentence is
			 * about a weight that does not exist: a bold 0 under "this is
			 * exactly the weight that enters the price grid" reads as a
			 * figure that failed to arrive rather than as an order nobody has
			 * filled in. "No line has a weight" is a different nothing and
			 * keeps the usual sentence, because there the figure is true.
			 */
			if ( ! $items ) {
				echo '<span class="dahu-ui-hint">'
					. esc_html__( 'No items on this order yet: the weight appears as soon as you add one.', 'dahu-weight-shipping' )
					. '</span>';
			} else {
				esc_html_e( 'This is exactly the weight that enters the price grid, kit and bundle rules included.', 'dahu-weight-shipping' );
			}
			?>
		</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

add_action( 'admin_enqueue_scripts', 'dahu_weight_shipping_enqueue_order_assets' );
/**
 * Loads the order screen's stylesheet and script.
 *
 * Proper enqueued files rather than a block injected into admin_footer. That
 * is not a matter of tidiness: an inline block is one failed interpolation
 * away from a SyntaxError that kills every line after it, including the
 * button created at the very end, and there is no way to tell from the
 * screen that it happened. A localised script cannot fail that way.
 *
 * @return void
 */
function dahu_weight_shipping_enqueue_order_assets() {

	if ( ! dahu_weight_shipping_is_order_screen() ) {
		return;
	}

	// The family sheet first, and its own second: the plugin's own rules
	// are written to sit on top of the shared components, so the order is
	// a dependency and not a hope. One handle across the family -- the
	// file is byte-identical everywhere, so whichever plugin loads it
	// first serves all of them, and a shop with six of these installed
	// downloads one copy.
	wp_enqueue_style(
		'dahu-ui',
		DAHU_WEIGHT_SHIPPING_URL . 'assets/css/dahu-ui.css',
		array(),
		DAHU_WEIGHT_SHIPPING_VERSION
	);

	wp_enqueue_style(
		'dahu-weight-shipping-order',
		DAHU_WEIGHT_SHIPPING_URL . 'assets/css/admin-order.css',
		array( 'dahu-ui' ),
		DAHU_WEIGHT_SHIPPING_VERSION
	);

	wp_enqueue_script(
		'dahu-weight-shipping-order',
		DAHU_WEIGHT_SHIPPING_URL . 'assets/js/admin-order.js',
		array( 'jquery' ),
		DAHU_WEIGHT_SHIPPING_VERSION,
		true
	);

	/*
	 * Decimal separator of the site's PRICES.
	 *
	 * Read straight from the option, without going through any WooCommerce
	 * function. get_option() belongs to WordPress core: always defined,
	 * whatever version of WooCommerce is installed, whatever state it is in
	 * and whatever hook is running. That matters here because this value
	 * decides which characters survive in a price field — see
	 * formatPriceForInput in the script — and a failure at this point would
	 * silently multiply a shipping cost by ten or a hundred.
	 *
	 * Accepted nuance: WooCommerce filters this value before using it. A shop
	 * filtering its decimal separator would see a mismatch between its own
	 * display and what this script writes. There is no such filter here, and
	 * a display mismatch is in no way comparable to a wrong amount.
	 */
	$decimal_sep = (string) get_option( 'woocommerce_price_decimal_sep', ',' );

	if ( '' === $decimal_sep ) {
		$decimal_sep = ',';
	}

	wp_localize_script(
		'dahu-weight-shipping-order',
		'dahuWeightShipping',
		array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( DAHU_WEIGHT_SHIPPING_NONCE ),
			'decimalSep' => $decimal_sep,
			'methodIds'  => dahu_weight_shipping_calculable_method_ids(),
			/*
			 * How long to wait for WooCommerce's own add-order-shipping
			 * request to produce the new row: 90 attempts, half a second
			 * apart, so 45 seconds.
			 *
			 * Generous on purpose. That request regularly runs past 20
			 * seconds on a shared host, and a polling window shorter than the
			 * request means the row is eventually created server-side and
			 * never filled in — which is how empty "Shipping / N/A" lines end
			 * up in the database.
			 */
			'maxAttempts'   => 90,
			'attemptDelay'  => 500,
			'weightUnit'    => get_option( 'woocommerce_weight_unit', 'kg' ),
			'currencySymbol' => get_woocommerce_currency_symbol(),
			'i18n'          => array(
				'addShipping'      => __( 'Add shipping', 'dahu-weight-shipping' ),
				'chooseMethod'     => __( 'Choose a shipping method', 'dahu-weight-shipping' ),
				'autoPriced'       => __( 'priced automatically', 'dahu-weight-shipping' ),
				'manualPriced'     => __( 'price typed in by hand', 'dahu-weight-shipping' ),
				'zoneLabel'        => __( 'Zone: %s', 'dahu-weight-shipping' ),
				'creatingLine'     => __( 'Creating the shipping line…', 'dahu-weight-shipping' ),
				'loadingMethods'   => __( 'Loading the shipping methods…', 'dahu-weight-shipping' ),
				'calculating'      => __( 'Working out the shipping cost…', 'dahu-weight-shipping' ),
				'refreshing'       => __( 'Calculating…', 'dahu-weight-shipping' ),
				'refresh'          => __( 'Refresh', 'dahu-weight-shipping' ),
				/* translators: %s: formatted amount, currency included. */
				'costCalculated'   => __( 'Shipping cost: %s', 'dahu-weight-shipping' ),
				'saveFirst'        => __( 'Please save this order once, with Create or Update, before adding a shipping line.', 'dahu-weight-shipping' ),
				'networkError'     => __( 'The shipping methods could not be loaded. Check the connection and try again.', 'dahu-weight-shipping' ),
				'noMethods'        => __( 'No shipping method is enabled for the zone matching this order\'s country. Check the country on the order, and the zone under WooCommerce, Settings, Shipping.', 'dahu-weight-shipping' ),
				'slowCreation'     => __( 'Creating the shipping line is taking longer than expected. Check whether an empty shipping line has appeared on the order and delete it before trying again.', 'dahu-weight-shipping' ),
				'dismiss'          => __( 'Dismiss', 'dahu-weight-shipping' ),
			),
		)
	);
}
