=== Dahu Weight Shipping ===
Contributors: hugovialjaime
Tags: woocommerce, shipping, weight, shipping zones, shipping classes
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Price WooCommerce shipping on the real weight of the cart, with a grid per zone and class rules — plus one-click shipping lines on manual orders.

== Description ==

A carrier contract is usually a table: up to two kilos, this much; up to five,
that much. Expressing that in WooCommerce means either a flat rate that is
wrong on most orders, or a table-rate plugin that asks you to model your whole
catalogue before it will quote anything.

Dahu Weight Shipping is the small version. You paste the carrier's table into
a field, one bracket per line, and the method prices every cart from it. The
table belongs to the shipping zone, so a second country with a second contract
is a second instance with its own table — not a conditional rule.

= The grid =

One bracket per line, as `min-max:price`:

`0-2:10`
`2-5:15`
`5-10:20`
`10-30:35`

The minimum is **excluded** from the bracket and the maximum **included**, so
`0-2:10` covers anything above 0 kg and up to 2 kg. Past the last bracket, the
last bracket's price applies — a grid that stopped short would otherwise quote
zero for a heavy order, which is a plausible-looking number that costs you
money on every order over the limit.

A malformed line is refused with a message on screen rather than skipped in
silence, and a grid where nothing is valid is not saved at all: the previous
one is kept.

= Rules from shipping classes =

Everything beyond the grid is expressed through shipping classes, per zone
instance:

* **Show only if** the cart contains one of these classes. That is how one
  instance gets dedicated to one kind of product.
* **Hide if** the cart contains one of these classes — to route a cart to
  another carrier rather than quote it here.
* **Kept out of the grid**: products whose weight never enters the table, and
  which are billed by their own flat cost instead. For goods a separate carrier
  handles at a contract price, whose weight would otherwise push the rest of
  the order into a higher bracket.
* **Cancels the grid**: as soon as one such product is in the cart, no weight
  at all enters the table — not its own, and not that of the items beside it.
  For a product whose shipping is already priced into it and which carries
  whatever ships with it.
* **Earns free shipping**, either for the whole order as soon as one such
  product is present, or only for an order made of nothing else.
* **A flat cost per class**, charged per unit, on top of the grid.

= Manual orders =

Pricing shipping on an order you build by hand in the admin is where this kind
of plugin usually gives up, because WooCommerce prices shipping from the cart
and there is no cart there.

This plugin adds an **Add shipping** button to the order screen which lists the
methods actually available for that order's destination, naming the zone they
came from, and says for each whether it will be priced automatically. Choose
one and the line is created, attached to that exact zone instance, and priced —
in one request.

The price comes from the **same** code the checkout runs, on the same instance.
Not a copy of its rules: a copy is what makes the price shown to you and the
price shown to your customer drift apart, with nothing on either screen to say
which is right.

If you correct a price by hand, that line is left alone from then on.
"Recalculate" will recompute its tax, like WooCommerce's own, and never
overwrite your figure.

= The shipping weight panel =

A panel on the order screen shows the weight that enters the grid — the same
number, from the same code, so it cannot disagree with what you are charged.
Change a quantity and it says it is out of date rather than quietly refreshing:
working the weight out needs a round trip to the server, and doing that on
every keystroke makes the screen unusable.

= Kits and bundles =

A kit sold as one product is one parcel to the carrier, whatever the kit plugin
did with its components. Components are folded back into their kit — in the
cart and on the order alike — so a kit is weighed and billed once. Dahu
Composite, WooCommerce Composite Products and WooCommerce Product Bundles are
all recognised, by the meta they publish.

= Other carriers =

Your other carriers' methods can be priced on manual orders too, if somebody
teaches this plugin how. Register a calculator for a method id:

`add_filter( 'dahu_weight_shipping_calculators', function ( $calculators ) {
    $calculators['my_carrier'] = function ( $order, $instance_id, $items ) {
        return array( 'cost' => 12.5, 'details' => array( 'from my carrier' ) );
    };
    return $calculators;
} );`

The rule that must not differ between carriers — free shipping by coupon — is
applied for all of them, so one order cannot come out free on one method and
charged on another.

= What this plugin does not do =

* It does not price by dimensions, volumetric weight or number of parcels.
* It does not talk to any carrier's API, buy labels or track parcels.
* It does not split a cart into several parcels.

== Installation ==

1. Install and activate the plugin.
2. Go to WooCommerce, Settings, Shipping, and open a zone.
3. Add the "Weight-based shipping" method.
4. Paste your carrier's table into the weight price grid, and set whatever
   class rules you need.

Repeat per zone. Each instance keeps its own grid.

== Frequently Asked Questions ==

= Which weight unit does the grid use? =

The shop's own, from WooCommerce, Settings, Products. The grid holds numbers,
not units, so changing the shop's unit changes what the grid means — check it
after such a change.

= Can I have two of these in one zone? =

Yes, and that is how you offer a choice: one instance for a tracked service and
one for a cheaper one, each with its own grid, or one restricted to a class
through "Show only if".

= Why is my method not showing at checkout? =

Three settings can hide it: a required class the cart does not contain, an
excluded class it does, or the instance being disabled in the zone. When a cart
ends up with NO method at all, the plugin records it in the debug log rather
than inventing a fallback price — a made-up figure matches no carrier contract,
and you would find out on the invoice.

= Why does the price field stay empty sometimes on a manual order? =

Because the method does not apply to that order — a required class is missing,
or an excluded one is present. Nothing is written, and a message says so.
Writing 0 there would read as free shipping, which is a different statement.

= Does it work with HPOS? =

Yes. Both order screens are supported, and the plugin declares itself
compatible with custom order tables.

= Does it handle tax? =

The instance has a tax status, and WooCommerce applies tax to the cost at
checkout as it does for any method. On a manual order the plugin fills in the
cost excluding tax only, and leaves the tax to WooCommerce's own
"Recalculate": tax depends on the customer, and that context is absent in the
admin.

== Changelog ==

= 1.1.2 =
* Clearing the weight price grid no longer happens in silence. The grid is still saved -- pricing by shipping class alone is a valid setup -- but when no class carries a cost either, the settings screen now says that the method will quote 0 for every order, and what to do about it.

= 1.1.1 =
* The weight panel on an order with no lines yet now says so, instead of leaving a bold zero under a sentence about the weight that enters the price grid.

= 1.1.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 1.0.1 =
* A shipping zone instance deleted since the order screen was opened is now refused before it is written to the shipping line, instead of binding the line to an instance nothing can price.
* A refusal from the server is now shown on the order screen. It used to leave the cost field untouched without a word.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.1 =
Fixes a shipping line that could be attached to a deleted zone instance, and a refusal that was not shown.

= 1.0.0 =
First public release.
