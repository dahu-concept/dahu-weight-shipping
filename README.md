# Dahu Weight Shipping

A weight-based WooCommerce shipping method, configurable per zone, with
per-class rules and one-click shipping lines on manually created orders.

MIT licensed. Requires WordPress 6.0, PHP 7.4 and WooCommerce 7.0.

## What it is for

A carrier contract is usually a table: up to two kilos, this much; up to five,
that much. This plugin lets you paste that table into a field and prices every
cart from it. The table belongs to the shipping zone, so a second country with
a second contract is a second instance with its own table rather than a
conditional rule.

## The grid

One bracket per line, `min-max:price`, weights in the shop's own unit and
prices excluding tax:

```
0-2:10
2-5:15
5-10:20
10-30:35
```

The minimum is **excluded** and the maximum **included**: `0-2:10` covers
anything above 0 and up to 2. Past the last bracket, the last bracket's price
applies.

Malformed lines are reported on screen rather than skipped silently, and a
grid where no line is valid is not saved — the previous one is kept, because
an empty grid prices every weight at zero and reads as free shipping.

## Rules, as settings rather than as code

Per zone instance:

| Setting | Effect |
|---|---|
| Show only if the cart contains | The method appears only for carts holding one of these classes |
| Hide if the cart contains | The method never appears for carts holding one of these classes |
| Kept out of the weight grid | These products' weight never enters the grid; they are billed by their flat cost alone |
| Cancels the weight grid | One such product in the cart and NO weight enters the grid, its own or anything else's |
| Earns free shipping | Free for the whole order, or only for an order made of nothing else |
| Flat cost per class | Charged per unit, on top of the grid |

## Manual orders

An **Add shipping** button on the order screen lists the methods available for
that order's destination, names the zone they came from, and marks which ones
this plugin can price. Choosing one creates the line, attaches it to that exact
zone instance and prices it, in a single request.

The price comes from the same `calculate_shipping()` the checkout calls, on the
same instance — not from a second copy of the pricing rules. A correction made
by hand switches automatic pricing off for that line for good.

A side panel shows the weight that enters the grid, from the same code, so the
figure shown and the figure billed cannot disagree.

## Kits and bundles

A kit sold as one product is one parcel. Components are folded back into their
kit on both sides — cart and order — so the kit is weighed and billed once.
Recognised through the meta each plugin publishes: Dahu Composite
(`dahu_kit_parent`), WooCommerce Composite Products (`composite_parent`,
`_composite_*`) and WooCommerce Product Bundles (through its own
`get_product_from_item` filter).

## Extending

### Pricing another carrier on manual orders

```php
add_filter( 'dahu_weight_shipping_calculators', function ( $calculators ) {
	$calculators['my_carrier'] = function ( $order, $instance_id, $items_with_qty ) {
		// Return the cost excluding tax, plus any lines explaining it,
		// or a WP_Error with code 'manual' or 'no_rate'.
		return array(
			'cost'    => 12.5,
			'details' => array( 'flat rate from my carrier' ),
		);
	};
	return $calculators;
} );
```

Free shipping by coupon is applied to every calculator's result, in one place,
so one order cannot come out free on one method and charged on another.

### Other hooks

| Hook | Kind | Purpose |
|---|---|---|
| `dahu_weight_shipping_cost` | filter | Last word on a cost, after grid, class costs and free shipping. For a surcharge nothing here models |
| `dahu_weight_shipping_default_grid` | filter | The grid offered on a NEW instance; never read by a configured one |
| `dahu_weight_shipping_calculators` | filter | Method id => callable, for manual orders |
| `dahu_weight_shipping_watched_countries` | filter | Countries the "no method available" guard watches. Empty, the default, means all |
| `dahu_weight_shipping_no_rate_available` | action | Fires when a cart ends up with no shipping method at all |

## Deliberate limits

* No dimensions, volumetric weight or parcel count.
* No carrier API, labels or tracking.
* No splitting a cart into several parcels.
* A cart left with no shipping method is **recorded**, never papered over with
  an invented price.

## Data

Nothing site-wide. Every setting belongs to a WooCommerce zone instance, which
WooCommerce deletes with its zone. The only thing written elsewhere is a flag
on a shipping line whose cost was edited by hand, and uninstalling keeps it —
deleting it would quietly undo that decision, and the next recalculation would
overwrite a price somebody corrected on purpose. Set the
`dahu_weight_shipping_remove_data_on_uninstall` option to remove those flags
too.

## Licence

MIT. See `LICENSE`.
