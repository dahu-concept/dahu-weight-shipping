<?php
/**
 * Reading a cart or an order as ONE billable package.
 *
 * Everything in this file exists to answer a single question — which items
 * does this shipment actually weigh, and how much — in exactly the same way
 * on both sides of the shop:
 *
 * - at checkout, from a WooCommerce package built from the cart;
 * - on the admin order screen, from order line items, where there is no cart
 *   at all.
 *
 * Keeping one answer for both is the whole point. Two implementations of
 * "what does this order weigh" drift apart on the first change, and the
 * symptom is a price that differs between the customer's checkout and the
 * shop manager's screen for reasons neither of them can see.
 *
 * The non-obvious part is bundles and kits. A kit sold as one product is one
 * parcel to the carrier, whatever the kit plugin decided to do with its
 * components: some stay visible in the cart, some become virtual, some carry
 * the aggregate weight and some do not. This file folds the components back
 * into their kit so the kit is billed once, with the weight of everything in
 * it.
 *
 * @package DahuWeightShipping
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Kit and bundle recognition
 * ---------------------------------------------------------------------- */

/**
 * Cart key of the kit an item belongs to, or '' when it belongs to none.
 *
 * Two layouts are recognised, by the meta each plugin writes:
 * `dahu_kit_parent` (Dahu Composite) and `composite_parent` (WooCommerce
 * Composite Products). Both mean the same thing, and both are read rather
 * than one being preferred: a shop may well run only one of them, and which
 * one must not change the price.
 *
 * @param array $cart_item Cart or package item.
 * @return string
 */
function dahu_weight_shipping_item_kit_parent( $cart_item ) {

	if ( ! empty( $cart_item['dahu_kit_parent'] ) ) {
		return (string) $cart_item['dahu_kit_parent'];
	}

	if ( ! empty( $cart_item['composite_parent'] ) ) {
		return (string) $cart_item['composite_parent'];
	}

	return '';
}

/**
 * Is this order line a COMPONENT of a kit, that is, attached to a parent?
 *
 * A component is never counted on its own, whatever its own shipping
 * configuration says — "packed with the kit" and "shipped individually"
 * alike. Commercially the kit is one parcel at the kit's own price, so the
 * only signal read here is the presence of the parent link.
 *
 * Deliberately NOT `_composite_item_needs_shipping`: that one varies with
 * each component's configuration, and reading it made some kits fall back
 * into the weight grid while others did not.
 *
 * @param WC_Order_Item_Product $order_item Order line.
 * @return bool
 */
function dahu_weight_shipping_is_kit_component( $order_item ) {
	return '' !== (string) $order_item->get_meta( '_composite_parent' );
}

/**
 * Is this order line the CONTAINER of a kit — the kit itself?
 *
 * Important: a container may be virtual, or need no shipping of its own,
 * depending on how the kit plugin is configured. It must still be counted,
 * because IT is the line carrying the kit's shipping class. The usual
 * `needs_shipping()` filter must therefore not be applied to it.
 *
 * @param WC_Order_Item_Product $order_item Order line.
 * @return bool
 */
function dahu_weight_shipping_is_kit_container( $order_item ) {

	if ( dahu_weight_shipping_is_kit_component( $order_item ) ) {
		return false;
	}

	$children = $order_item->get_meta( '_composite_children' );

	if ( ! empty( $children ) ) {
		return true;
	}

	return '' !== (string) $order_item->get_meta( '_composite_cart_key' );
}

/**
 * Effective weight of one unit of an order line.
 *
 * For a kit container, the weight that matters is the assembled weight, not
 * the weight of the template product — which is often zero, the container
 * being virtual. It is read from the `_composite_weight` meta the kit plugin
 * writes, which already includes the components packed with the kit; the
 * components shipped individually are added on top, because a kit is one
 * billed parcel whatever its components' shipping settings say.
 *
 * A composite weight of ZERO is treated as absent, not as a weight of zero.
 * Kit plugins do write the string "0" on a kit whose components are all
 * shipped individually, and taking that at face value made a whole kit weigh
 * nothing — a wrong and entirely plausible price. A kit with a genuinely
 * zero weight does not exist here: these are physical goods.
 *
 * @param WC_Order_Item_Product $order_item        Order line.
 * @param WC_Product            $product           Product resolved from the line.
 * @param float                 $components_weight Weight of the attached components, per unit.
 * @param float                 $individual_weight Weight of the components shipped individually, per unit.
 * @return float
 */
function dahu_weight_shipping_effective_item_weight( $order_item, $product, $components_weight = 0, $individual_weight = 0 ) {

	$composite_weight = $order_item->get_meta( '_composite_weight' );

	if ( '' !== $composite_weight && null !== $composite_weight && 0.0 !== (float) $composite_weight ) {
		return (float) $composite_weight + (float) $individual_weight;
	}

	return (float) $product->get_weight() + (float) $components_weight;
}

/* -------------------------------------------------------------------------
 * The billable package, at checkout
 * ---------------------------------------------------------------------- */

/**
 * The BILLABLE package: the WooCommerce package with every still-visible kit
 * component folded back into its kit.
 *
 * A component shipped individually stays in the WooCommerce package — kit
 * plugins only fold away the ones packed with the kit. It then looks like an
 * ordinary item with NO shipping class, which has three consequences, all
 * wrong and none visible: one more unclassed item in the cart, its weight
 * entering the grid a second time, and a class rule firing on an item the
 * shop manager never put there.
 *
 * After this, a component is never a billed item on either side, and its
 * weight belongs to its kit. A component whose kit is NOT in this package is
 * dropped without carrying its weight anywhere, which is what the order side
 * does too.
 *
 * The original package is never modified: the container's product is cloned,
 * exactly as the kit plugins themselves do when they publish an aggregate
 * weight.
 *
 * @param array $package WooCommerce package.
 * @return array
 */
function dahu_weight_shipping_billable_package( $package ) {

	if ( empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
		return $package;
	}

	$contents = $package['contents'];
	$folded   = array();

	foreach ( $contents as $key => $item ) {

		$parent_key = dahu_weight_shipping_item_kit_parent( $item );

		if ( '' === $parent_key ) {
			continue;
		}

		unset( $contents[ $key ] );

		if ( ! empty( $item['data'] ) && is_object( $item['data'] ) ) {

			if ( ! isset( $folded[ $parent_key ] ) ) {
				$folded[ $parent_key ] = 0.0;
			}

			$folded[ $parent_key ] += (float) $item['data']->get_weight() * (float) $item['quantity'];
		}
	}

	foreach ( $folded as $parent_key => $weight ) {

		if ( ! $weight || empty( $contents[ $parent_key ]['data'] ) ) {
			continue;
		}

		$container = $contents[ $parent_key ];
		$qty       = max( 1, (int) $container['quantity'] );
		$data      = clone $container['data'];

		$data->set_weight( (float) $container['data']->get_weight() + $weight / $qty );

		$contents[ $parent_key ]['data'] = $data;
	}

	$package['contents'] = $contents;

	return $package;
}

/* -------------------------------------------------------------------------
 * The billable package, on an order
 * ---------------------------------------------------------------------- */

/**
 * Runs a calculation while reading order lines the way WooCommerce Product
 * Bundles ships them.
 *
 * In the cart, Product Bundles rebuilds a bundle's package according to ITS
 * own settings: an item "packed with the bundle" becomes virtual and its
 * weight is added to the bundle only if "bundle weight = sum of contents" is
 * chosen, while an item "shipped individually" stays an item of the package.
 * On an order it records that result and provides the filter that reads it
 * back — `get_product_from_item()`, the same one it enables for its own
 * shipping integrations.
 *
 * Without that filter the RAW products are read: an item packed with its
 * bundle is counted on top of the bundle, with its weight and without a
 * class. With it, the order is read exactly as the cart was, for that bundle,
 * whatever its weight setting — so this plugin makes no assumption about
 * that setting at all.
 *
 * @param callable $callback Calculation to run.
 * @return mixed Whatever the callback returns.
 */
function dahu_weight_shipping_with_bundle_view( $callback ) {

	// No isset(): WC_PB()->order is served through __get(), where isset()
	// answers false and the filter would never be attached.
	$pb_order = ( function_exists( 'WC_PB' ) && is_object( WC_PB() ) ) ? WC_PB()->order : null;
	$filter   = ( is_object( $pb_order ) && is_callable( array( $pb_order, 'get_product_from_item' ) ) )
		? array( $pb_order, 'get_product_from_item' )
		: null;

	// Already active — we are running inside Product Bundles' own integration
	// context. Add nothing, and above all remove nothing on the way out.
	if ( ! $filter || has_filter( 'woocommerce_order_item_product', $filter ) ) {
		return call_user_func( $callback );
	}

	add_filter( 'woocommerce_order_item_product', $filter, 10, 2 );

	try {
		return call_user_func( $callback );
	} finally {
		remove_filter( 'woocommerce_order_item_product', $filter, 10 );
	}
}

/**
 * Pairs of (order line, quantity), preferring the quantities currently shown
 * on screen over the ones stored in the database.
 *
 * On an order being edited, the database is behind the screen: a quantity
 * typed but not yet saved is the one the shop manager expects the price to
 * reflect. The browser therefore sends the quantities it is displaying, and
 * the stored order is the fallback for any call made outside a browser.
 *
 * The browser sends the ORDER LINE id, not a product id scraped from the
 * item's link: that link always points at the PARENT product, never at the
 * variation actually ordered. Going through `$order->get_item()` resolves
 * the exact variation, with its own shipping class, instead of falling back
 * to a parent that usually has none.
 *
 * @param WC_Order $order Order being edited.
 * @return array<int, array{0: WC_Order_Item_Product, 1: int}>
 */
function dahu_weight_shipping_collect_items_with_live_qty( $order ) {

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies the nonce before reaching this point.
	$live_items_raw = isset( $_POST['live_items'] ) ? wp_unslash( $_POST['live_items'] ) : '';
	$live_items     = is_string( $live_items_raw ) && '' !== $live_items_raw
		? json_decode( $live_items_raw, true )
		: null;

	$items_with_qty = array();

	if ( is_array( $live_items ) && ! empty( $live_items ) ) {

		foreach ( $live_items as $live_item ) {

			if ( ! is_array( $live_item ) ) {
				continue;
			}

			$order_item_id = isset( $live_item['order_item_id'] ) ? absint( $live_item['order_item_id'] ) : 0;
			$qty           = isset( $live_item['quantity'] ) ? absint( $live_item['quantity'] ) : 0;

			if ( ! $order_item_id || ! $qty ) {
				continue;
			}

			$order_item = $order->get_item( $order_item_id );

			if ( ! $order_item || ! is_a( $order_item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$items_with_qty[] = array( $order_item, $qty );
		}

		return $items_with_qty;
	}

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$items_with_qty[] = array( $item, (int) $item->get_quantity() );
	}

	return $items_with_qty;
}

/**
 * Transposes order lines into the `contents` format of a WooCommerce
 * package — the one `calculate_shipping()` expects — applying the kit rules.
 *
 * Two callers: the price calculation, which builds its package from these
 * contents, and the order weight panel, which only sums their weights. One
 * single place where the kit rules are written, so the weight SHOWN and the
 * weight BILLED cannot disagree.
 *
 * @param array<int, array> $items_with_qty Pairs of (order line, quantity).
 * @return array Indexed contents.
 */
function dahu_weight_shipping_build_package_contents( $items_with_qty ) {

	return dahu_weight_shipping_with_bundle_view(
		static function () use ( $items_with_qty ) {
			return dahu_weight_shipping_build_package_contents_raw( $items_with_qty );
		}
	);
}

/**
 * Body of dahu_weight_shipping_build_package_contents(), to be called under
 * dahu_weight_shipping_with_bundle_view().
 *
 * @param array<int, array> $items_with_qty Pairs of (order line, quantity).
 * @return array
 */
function dahu_weight_shipping_build_package_contents_raw( $items_with_qty ) {

	/*
	 * First pass: the weight of each kit's components, indexed by the key
	 * they are attached to. Used as the fallback for a kit whose container
	 * published no aggregate weight.
	 *
	 * The components shipped individually are also kept apart, because they
	 * are absent from that aggregate weight and have to be added to it.
	 */
	$components_weight_by_parent = array();
	$individual_weight_by_parent = array();

	foreach ( $items_with_qty as $pair ) {

		list( $order_item, $qty ) = $pair;

		if ( ! dahu_weight_shipping_is_kit_component( $order_item ) ) {
			continue;
		}

		$product = $order_item->get_product();

		if ( ! $product ) {
			continue;
		}

		$parent_key = (string) $order_item->get_meta( '_composite_parent' );

		if ( ! isset( $components_weight_by_parent[ $parent_key ] ) ) {
			$components_weight_by_parent[ $parent_key ] = 0.0;
			$individual_weight_by_parent[ $parent_key ] = 0.0;
		}

		$components_weight_by_parent[ $parent_key ] += (float) $product->get_weight() * $qty;

		if ( 'yes' === $order_item->get_meta( '_composite_item_needs_shipping' ) ) {
			$individual_weight_by_parent[ $parent_key ] += (float) $product->get_weight() * $qty;
		}
	}

	/*
	 * Second pass: the transposition itself.
	 *
	 * Only two keys are ever consumed downstream — 'data' (the WC_Product)
	 * and 'quantity'. No others are invented: a made-up key would give the
	 * illusion of a complete package without being one.
	 */
	$package_contents = array();

	foreach ( $items_with_qty as $pair ) {

		list( $order_item, $qty ) = $pair;

		$product = $order_item->get_product();

		if ( ! $product ) {
			continue;
		}

		// A kit component is never counted on its own.
		if ( dahu_weight_shipping_is_kit_component( $order_item ) ) {
			continue;
		}

		$is_container = dahu_weight_shipping_is_kit_container( $order_item );

		// A kit container may be virtual and must still be counted: it is the
		// line carrying the kit's shipping class.
		if ( ! $is_container && ! $product->needs_shipping() ) {
			continue;
		}

		$components_weight = 0.0;
		$individual_weight = 0.0;

		if ( $is_container ) {

			$cart_key = (string) $order_item->get_meta( '_composite_cart_key' );

			if ( '' !== $cart_key && isset( $components_weight_by_parent[ $cart_key ] ) ) {
				// Brought back to ONE kit: the sums above cover every kit on
				// the line, and the weight below is a UNIT weight that the
				// package then multiplies by the quantity again.
				$per_kit           = max( 1, (int) $qty );
				$components_weight = $components_weight_by_parent[ $cart_key ] / $per_kit;
				$individual_weight = $individual_weight_by_parent[ $cart_key ] / $per_kit;
			}
		}

		$effective_weight = dahu_weight_shipping_effective_item_weight( $order_item, $product, $components_weight, $individual_weight );

		/*
		 * The delicate point of this transposition.
		 *
		 * Everything downstream reads the weight through
		 * $product->get_weight(), that is the product's OWN weight. The
		 * effective weight of a kit comes from the ORDER LINE instead, and
		 * does not exist on the container product at all.
		 *
		 * So a CLONE carrying the effective weight is passed along. The clone
		 * is never saved: nothing changes in the database, and the
		 * substitution lives only for the duration of this calculation.
		 *
		 * Without it, a kit with a virtual container enters the grid weighing
		 * zero — a wrong and perfectly plausible price.
		 */
		$product_for_package = $product;

		if ( (float) $product->get_weight() !== (float) $effective_weight ) {
			$product_for_package = clone $product;
			$product_for_package->set_weight( $effective_weight );
		}

		$package_contents[ 'dahu_weight_shipping_' . $order_item->get_id() ] = array(
			'data'     => $product_for_package,
			'quantity' => $qty,
		);
	}

	return $package_contents;
}

/**
 * Total weight of an order, kit rules included.
 *
 * This is EXACTLY the weight that enters the price grid: same source, same
 * rules, to the line. A panel showing a weight computed any other way would
 * display a plausible, wrong number — the opposite of what a control
 * indicator is for.
 *
 * @param array<int, array> $items_with_qty Pairs of (order line, quantity).
 * @return float Weight in the shop's weight unit, rounded to the gram.
 */
function dahu_weight_shipping_order_total_weight( $items_with_qty ) {

	$weight = 0.0;

	foreach ( dahu_weight_shipping_build_package_contents( $items_with_qty ) as $line ) {
		$weight += (float) $line['data']->get_weight() * (float) $line['quantity'];
	}

	return round( $weight, 3 );
}

/**
 * Builds a synthetic WooCommerce package from an order.
 *
 * `applied_coupons` is present but empty on purpose: some third-party
 * methods walk that key without guarding it, and its absence would raise a
 * PHP warning inside somebody else's code. `destination` and `contents_cost`
 * are supplied for the same reason — this plugin's own method reads neither,
 * geography being the zone's business, but the package stays faithful to
 * what the API documents.
 *
 * @param WC_Order          $order          Order being edited.
 * @param array<int, array> $items_with_qty Pairs of (order line, quantity).
 * @param string            $country        Country shown on screen, if any.
 * @return array
 */
function dahu_weight_shipping_build_order_package( $order, $items_with_qty, $country = '' ) {

	$country = $country ? $country : ( $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country() );

	return array(
		'destination'     => array(
			'country'  => $country,
			'state'    => $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state(),
			'postcode' => $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
			'city'     => $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
		),
		'contents'        => dahu_weight_shipping_build_package_contents( $items_with_qty ),
		'contents_cost'   => 0,
		'applied_coupons' => array(),
	);
}

/**
 * Formats a weight for display, in the site's locale.
 *
 * Formatted on the SERVER and shipped alongside the raw number, rather than
 * formatted in the browser from a separator passed along for the purpose.
 * The browser has no business guessing a locale convention that WordPress
 * already knows, and the one separator available to it — WooCommerce's price
 * decimal separator — is a different setting that a shop is free to set
 * differently.
 *
 * @param float $weight Weight.
 * @return string
 */
function dahu_weight_shipping_format_weight( $weight ) {
	return number_format_i18n( (float) $weight, 3 );
}
