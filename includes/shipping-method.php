<?php
/**
 * The shipping method itself.
 *
 * One method, added and configured per zone like any native one. Every
 * setting belongs to the zone instance rather than to the site: a shop
 * shipping to two countries at two different price grids configures two
 * instances, and nothing in this plugin has to know that.
 *
 * @package DahuWeightShipping
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'woocommerce_shipping_methods',
	static function ( $methods ) {
		$methods[ DAHU_WEIGHT_SHIPPING_METHOD_ID ] = 'Dahu_Weight_Shipping_Method';
		return $methods;
	}
);

/**
 * Instantiates the method for a given zone instance.
 *
 * Only ever needed outside the checkout. At the front WooCommerce
 * instantiates the method itself and calls calculate_shipping(); on an
 * admin-created order there is neither a cart nor a package, so nothing
 * instantiates anything and the same settings have to be reached by hand.
 *
 * WooCommerce's own WC_Shipping_Zones::get_shipping_method() does the
 * instantiating, and NOT `new Dahu_Weight_Shipping_Method( $instance_id )`.
 * The difference matters more than it looks, because that constructor
 * accepts any number at all: an instance deleted from its zone leaves its id
 * behind on every order line that used it, and constructing it directly
 * yields an object whose settings option no longer exists. Every setting
 * then silently falls back to its default — INCLUDING the price grid. The
 * result is a shipping cost worked out from a grid nobody configured, and it
 * looks exactly like a real price.
 *
 * WooCommerce's API looks the instance up in the database instead, so a
 * deleted one comes back false. It also refuses an instance belonging to
 * SOMEBODY ELSE'S method, which is checked again below: a line can carry one
 * method's id and another method's instance id, and nothing stops it.
 *
 * The class is only declared on `woocommerce_shipping_init`; touching
 * WC()->shipping() is enough to fire that hook if an early AJAX request got
 * here first.
 *
 * @param int $instance_id Zone instance id.
 * @return Dahu_Weight_Shipping_Method|null
 */
function dahu_weight_shipping_get_method( $instance_id ) {

	$instance_id = absint( $instance_id );

	if ( ! $instance_id || ! class_exists( 'WC_Shipping_Zones' ) ) {
		return null;
	}

	if ( ! class_exists( 'Dahu_Weight_Shipping_Method' ) && function_exists( 'WC' ) ) {
		WC()->shipping();
	}

	$method = WC_Shipping_Zones::get_shipping_method( $instance_id );

	if ( ! $method || ! ( $method instanceof Dahu_Weight_Shipping_Method ) ) {
		return null;
	}

	return $method;
}

add_action( 'woocommerce_shipping_init', 'dahu_weight_shipping_method_init' );
/**
 * Declares the method class.
 *
 * Wrapped in this hook because it extends WC_Shipping_Method, which does not
 * exist before WooCommerce has loaded.
 *
 * @return void
 */
function dahu_weight_shipping_method_init() {

	if ( class_exists( 'Dahu_Weight_Shipping_Method' ) ) {
		return;
	}

	/**
	 * Weight-based shipping method.
	 *
	 * The price of an order is read from a per-zone grid of weight brackets,
	 * on top of which shipping classes can add a flat per-unit cost, waive
	 * the weight entirely, make the order free, or hide the method
	 * altogether. None of those rules is written in code: each is a setting,
	 * so a shop expresses its own carrier contract without a line of PHP.
	 */
	class Dahu_Weight_Shipping_Method extends WC_Shipping_Method {

		/**
		 * Constructor.
		 *
		 * @param int $instance_id Zone instance id.
		 */
		public function __construct( $instance_id = 0 ) {

			$this->id                 = DAHU_WEIGHT_SHIPPING_METHOD_ID;
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Weight-based shipping', 'dahu-weight-shipping' );
			$this->method_description = __( 'Prices a shipment from the real weight of the cart, using a price grid belonging to this zone, with per-class rules for flat costs, free shipping and exclusions.', 'dahu-weight-shipping' );
			$this->supports           = array(
				'shipping-zones',
				'instance-settings',
				'instance-settings-modal',
			);

			$this->init();
		}

		/**
		 * Loads this instance's settings.
		 *
		 * @return void
		 */
		public function init() {

			$this->init_form_fields();
			$this->init_settings();

			$this->title      = $this->get_option( 'title' );
			$this->tax_status = $this->get_option( 'tax_status' );
		}

		/* -----------------------------------------------------------------
		 * Settings
		 * -------------------------------------------------------------- */

		/**
		 * Shipping classes, to populate the multi-selects.
		 *
		 * The taxonomy is queried directly rather than through
		 * WC()->shipping()->get_shipping_classes(): this method can be
		 * instantiated WHILE WC_Shipping is initialising, and calling back
		 * into WC()->shipping() at that moment recurses.
		 *
		 * @return array<string, string> Class id => name.
		 */
		private function get_shipping_class_options() {

			$options = array();

			$terms = get_terms(
				array(
					'taxonomy'   => 'product_shipping_class',
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) ) {
				return $options;
			}

			foreach ( $terms as $term ) {
				$options[ (string) $term->term_id ] = $term->name;
			}

			return $options;
		}

		/**
		 * Settings form, per zone instance.
		 *
		 * @return void
		 */
		public function init_form_fields() {

			$class_options = $this->get_shipping_class_options();

			$fields = array(

				'title'                  => array(
					'title'       => __( 'Title', 'dahu-weight-shipping' ),
					'type'        => 'text',
					'description' => __( 'Title shown to the customer during checkout. Some label-printing services pick the carrier from this text, so it is worth naming the carrier in it.', 'dahu-weight-shipping' ),
					'default'     => __( 'Shipping', 'dahu-weight-shipping' ),
					'desc_tip'    => true,
				),

				'tax_status'             => array(
					'title'       => __( 'Tax status', 'dahu-weight-shipping' ),
					'type'        => 'select',
					'description' => __( 'Whether tax applies to the calculated shipping cost.', 'dahu-weight-shipping' ),
					'options'     => array(
						'taxable' => __( 'Taxable', 'dahu-weight-shipping' ),
						'none'    => _x( 'None', 'Tax status', 'dahu-weight-shipping' ),
					),
					'default'     => 'taxable',
					'desc_tip'    => true,
				),

				'weight_grid'            => array(
					'title'       => __( 'Weight price grid', 'dahu-weight-shipping' ),
					'type'        => 'textarea',
					'description' => __( 'One bracket per line, as <code>min-max:price</code> — for example <code>0-2:18</code>. Weights in the shop\'s weight unit, prices excluding tax. The minimum is EXCLUDED from the bracket and the maximum INCLUDED, so "0-2:18" covers anything above 0 and up to 2. Past the last bracket, that bracket\'s price applies. This grid belongs to this zone only.', 'dahu-weight-shipping' ),
					'default'     => dahu_weight_shipping_format_grid( dahu_weight_shipping_default_grid() ),
					'css'         => 'width: 320px; height: 180px; font-family: monospace;',
				),

				'rules_title'            => array(
					'title'       => __( 'Shipping class rules', 'dahu-weight-shipping' ),
					'type'        => 'title',
					'description' => __( 'Each rule below is read from the classes present in the cart. Leaving a field empty disables that rule entirely.', 'dahu-weight-shipping' ),
				),

				'required_classes'       => array(
					'title'       => __( 'Show only if the cart contains', 'dahu-weight-shipping' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'description' => __( 'With one or more classes selected, this method only appears when the cart holds at least one product in one of them. That is how an instance gets dedicated to one kind of product. Leave empty for no condition.', 'dahu-weight-shipping' ),
					'options'     => $class_options,
					'default'     => array(),
					'desc_tip'    => true,
				),

				'excluded_classes'       => array(
					'title'       => __( 'Hide if the cart contains', 'dahu-weight-shipping' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'description' => __( 'This method will NOT be offered when any product in the cart carries one of these classes. Use it to route a cart to another carrier rather than to price it here.', 'dahu-weight-shipping' ),
					'options'     => $class_options,
					'default'     => array(),
					'desc_tip'    => true,
				),

				'flat_rate_classes'      => array(
					'title'       => __( 'Classes kept out of the weight grid', 'dahu-weight-shipping' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'description' => __( 'Products in these classes never contribute their weight to the grid. They are billed only through their own flat cost below. This is for goods a separate carrier handles at a contract price — a bulky item on a pallet, for instance — whose weight would otherwise push the rest of the order into a higher bracket.', 'dahu-weight-shipping' ),
					'options'     => $class_options,
					'default'     => array(),
					'desc_tip'    => true,
				),

				'weight_waiving_classes' => array(
					'title'       => __( 'Classes that cancel the weight grid', 'dahu-weight-shipping' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'description' => __( 'As soon as one product in the cart carries one of these classes, NO weight at all enters the grid — not its own, and not that of the items alongside it. Only the flat class costs below are charged. This covers the case of a product whose shipping is already priced into it and which carries whatever ships with it.', 'dahu-weight-shipping' ),
					'options'     => $class_options,
					'default'     => array(),
					'desc_tip'    => true,
				),

				'free_title'             => array(
					'title' => __( 'Free shipping', 'dahu-weight-shipping' ),
					'type'  => 'title',
				),

				'always_free'            => array(
					'title'       => __( 'Always free', 'dahu-weight-shipping' ),
					'type'        => 'checkbox',
					'description' => __( 'When ticked, this method costs nothing whatever the cart holds. The grid and the class costs are still read, and still ignored.', 'dahu-weight-shipping' ),
					'default'     => 'no',
					'desc_tip'    => true,
					'label'       => ' ',
				),

				'classes_free_shipping'  => array(
					'title'       => __( 'Classes earning free shipping', 'dahu-weight-shipping' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'description' => __( 'Products in these classes make the shipment free. Whether that applies to the whole order or only to an order made up of nothing else is the next setting.', 'dahu-weight-shipping' ),
					'options'     => $class_options,
					'default'     => array(),
					'desc_tip'    => true,
				),

				'free_for_whole_cart'    => array(
					'title'       => __( 'One such product is enough', 'dahu-weight-shipping' ),
					'type'        => 'checkbox',
					'description' => __( 'When ticked, the WHOLE order ships free as soon as one product carries a class earning free shipping, even if the others do not. Unticked, only an order made up exclusively of those classes ships free.', 'dahu-weight-shipping' ),
					'default'     => 'yes',
					'desc_tip'    => true,
					'label'       => ' ',
				),

				'title_free'             => array(
					'title'       => __( 'Title when free', 'dahu-weight-shipping' ),
					'type'        => 'text',
					'description' => __( 'Title shown to the customer when the shipment is free. Leave empty to always use the standard title.', 'dahu-weight-shipping' ),
					'default'     => '',
					'desc_tip'    => true,
				),

				'class_costs_title'      => array(
					'title'       => __( 'Flat cost per shipping class', 'dahu-weight-shipping' ),
					'type'        => 'title',
					'description' => __( 'Charged PER UNIT, on top of whatever the weight grid returns. Leave a class empty to charge nothing for it.', 'dahu-weight-shipping' ),
				),
			);

			if ( empty( $class_options ) ) {
				$fields['class_costs_title']['description'] = __( 'This shop has no shipping class yet, so there is nothing to price here. Shipping classes are created under Products, and every rule above works from them.', 'dahu-weight-shipping' );
			}

			foreach ( $class_options as $class_id => $class_name ) {
				$fields[ 'class_cost_' . $class_id ] = array(
					/* translators: %s: shipping class name. */
					'title'       => sprintf( __( 'Cost for "%s"', 'dahu-weight-shipping' ), $class_name ),
					'type'        => 'price',
					'default'     => '',
					'placeholder' => '0',
				);
			}

			$this->instance_form_fields = $fields;
		}

		/**
		 * Validates the grid as typed.
		 *
		 * Valid lines are normalised, malformed ones are refused with a
		 * message on screen rather than swallowed. And when NOTHING is valid
		 * the previous grid is kept: saving an empty grid would price every
		 * weight at zero, which reads as free shipping rather than as a
		 * mistake.
		 *
		 * @param string $key   Field key.
		 * @param string $value Submitted value.
		 * @return string Normalised grid.
		 */
		public function validate_weight_grid_field( $key, $value ) {

			$errors = array();
			$grid   = dahu_weight_shipping_parse_grid( $value, $errors );

			$can_report = class_exists( 'WC_Admin_Settings' );

			foreach ( $errors as $error ) {
				if ( $can_report ) {
					WC_Admin_Settings::add_error( __( 'Weight price grid', 'dahu-weight-shipping' ) . ' — ' . $error );
				}
			}

			if ( empty( $grid ) && ! empty( $errors ) ) {

				if ( $can_report ) {
					WC_Admin_Settings::add_error(
						__( 'Weight price grid', 'dahu-weight-shipping' ) . ' — '
						. __( 'no valid bracket was found, so the previous grid has been kept.', 'dahu-weight-shipping' )
					);
				}

				return (string) $this->get_option( 'weight_grid', '' );
			}

			return dahu_weight_shipping_format_grid( $grid );
		}

		/* -----------------------------------------------------------------
		 * Reading the settings
		 * -------------------------------------------------------------- */

		/**
		 * This instance's grid, as brackets.
		 *
		 * @return array<int, array>
		 */
		public function get_weight_grid() {

			$errors = array();

			return dahu_weight_shipping_parse_grid( $this->get_option( 'weight_grid', '' ), $errors );
		}

		/**
		 * Class ids configured in one of the multi-select settings.
		 *
		 * @param string $option Option name.
		 * @return array<int, string>
		 */
		private function get_class_list( $option ) {
			return array_map( 'strval', (array) $this->get_option( $option, array() ) );
		}

		/**
		 * Flat per-unit cost configured for one shipping class.
		 *
		 * @param int|string $class_id Class id.
		 * @return float
		 */
		public function get_class_cost( $class_id ) {

			$class_id = (int) $class_id;

			if ( ! $class_id ) {
				return 0.0;
			}

			$raw_cost = $this->get_option( 'class_cost_' . $class_id, '' );

			return '' !== $raw_cost ? (float) $raw_cost : 0.0;
		}

		/* -----------------------------------------------------------------
		 * Reading the package
		 * -------------------------------------------------------------- */

		/**
		 * Shipping classes present in the package.
		 *
		 * No `needs_shipping()` filter here: a kit container may be virtual
		 * and still carry the kit's class, and skipping it would make the
		 * kit's own rules stop applying. Products with no class are present
		 * as '0'.
		 *
		 * @param array $package Shipping package.
		 * @return array<int, string> Class ids, as strings.
		 */
		private function get_package_classes( $package ) {

			$classes = array();

			foreach ( $package['contents'] as $item ) {

				if ( empty( $item['data'] ) ) {
					continue;
				}

				$classes[] = (string) (int) $item['data']->get_shipping_class_id();
			}

			return array_values( array_unique( $classes ) );
		}

		/**
		 * Total unit quantity per shipping class.
		 *
		 * @param array $package Shipping package.
		 * @return array<string, int> Class id => quantity.
		 */
		private function get_quantity_per_class( $package ) {

			$quantities = array();

			foreach ( $package['contents'] as $item ) {

				if ( empty( $item['data'] ) ) {
					continue;
				}

				$class_id = (string) (int) $item['data']->get_shipping_class_id();

				if ( ! isset( $quantities[ $class_id ] ) ) {
					$quantities[ $class_id ] = 0;
				}

				$quantities[ $class_id ] += (int) $item['quantity'];
			}

			return $quantities;
		}

		/**
		 * Weight entering the grid.
		 *
		 * Items in a class kept out of the grid never contribute. And if any
		 * class cancelling the grid is present, the answer is zero — not the
		 * weight of the rest of the cart.
		 *
		 * An item with no shipping of its own — a virtual or downloadable
		 * product — is skipped, except when it is a kit container, which
		 * carries the kit however it is configured.
		 *
		 * @param array $package       Shipping package.
		 * @param array $package_classes Classes present in the package.
		 * @return float
		 */
		private function get_grid_weight( $package, $package_classes ) {

			$waiving = $this->get_class_list( 'weight_waiving_classes' );

			if ( ! empty( $waiving ) && ! empty( array_intersect( $waiving, $package_classes ) ) ) {
				return 0.0;
			}

			$kept_out = $this->get_class_list( 'flat_rate_classes' );
			$weight   = 0.0;

			foreach ( $package['contents'] as $item ) {

				if ( empty( $item['data'] ) ) {
					continue;
				}

				$product  = $item['data'];
				$class_id = (string) (int) $product->get_shipping_class_id();

				if ( in_array( $class_id, $kept_out, true ) ) {
					continue;
				}

				if ( ! $product->needs_shipping() ) {
					continue;
				}

				$weight += (float) $product->get_weight() * (int) $item['quantity'];
			}

			return $weight;
		}

		/* -----------------------------------------------------------------
		 * The rules
		 * -------------------------------------------------------------- */

		/**
		 * Is a class hiding this method present?
		 *
		 * @param array $package_classes Classes present in the package.
		 * @return bool
		 */
		private function has_excluded_class( $package_classes ) {

			$excluded = $this->get_class_list( 'excluded_classes' );

			if ( empty( $excluded ) ) {
				return false;
			}

			return ! empty( array_intersect( $excluded, $package_classes ) );
		}

		/**
		 * Is the required-class condition satisfied?
		 *
		 * An empty field means no condition, so an instance that does not
		 * configure it behaves exactly as if this rule did not exist.
		 *
		 * @param array $package_classes Classes present in the package.
		 * @return bool
		 */
		private function has_required_class( $package_classes ) {

			$required = $this->get_class_list( 'required_classes' );

			if ( empty( $required ) ) {
				return true;
			}

			return ! empty( array_intersect( $required, $package_classes ) );
		}

		/**
		 * Does this package ship free?
		 *
		 * @param array $package_classes Classes present in the package.
		 * @return bool
		 */
		private function is_free_for_package( $package_classes ) {

			if ( 'yes' === $this->get_option( 'always_free', 'no' ) ) {
				return true;
			}

			$free_classes = $this->get_class_list( 'classes_free_shipping' );

			if ( empty( $free_classes ) ) {
				return false;
			}

			if ( empty( array_intersect( $free_classes, $package_classes ) ) ) {
				return false;
			}

			$has_other_paying_classes = ! empty( array_diff( $package_classes, $free_classes ) );

			return ! $has_other_paying_classes
				|| 'yes' === $this->get_option( 'free_for_whole_cart', 'yes' );
		}

		/**
		 * Cost before free shipping is applied.
		 *
		 * @param array $package         Shipping package.
		 * @param array $package_classes Classes present in the package.
		 * @return float
		 */
		private function calculate_cost( $package, $package_classes ) {

			$grid_weight = $this->get_grid_weight( $package, $package_classes );

			$cost = $grid_weight > 0
				? dahu_weight_shipping_price_for_weight( $grid_weight, $this->get_weight_grid() )
				: 0.0;

			/*
			 * Flat class costs, charged per unit, for EVERY class that has
			 * one configured.
			 *
			 * No `needs_shipping()` filter on these quantities: a kit
			 * container may be virtual while carrying the class the shop
			 * charges for, and skipping it would ship that kit for free.
			 */
			foreach ( $this->get_quantity_per_class( $package ) as $class_id => $quantity ) {
				$cost += $this->get_class_cost( $class_id ) * $quantity;
			}

			return (float) $cost;
		}

		/* -----------------------------------------------------------------
		 * WooCommerce entry point
		 * -------------------------------------------------------------- */

		/**
		 * Calculates the rate and offers it.
		 *
		 * Geography is deliberately not filtered here: the zone this method
		 * is added to defines its coverage, which is what a shop manager
		 * expects and what makes a second zone with its own grid a matter of
		 * configuration rather than of code.
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return void
		 */
		public function calculate_shipping( $package = array() ) {

			if ( empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
				return;
			}

			/*
			 * The ORIGINAL package is kept for add_rate(), which WooCommerce
			 * ties the rate to; every rule reads the BILLABLE one, where a
			 * still-visible kit component is folded back into its kit. A
			 * package coming from the order screen is unaffected: its
			 * contents carry no parent key.
			 */
			$original_package = $package;
			$package          = dahu_weight_shipping_billable_package( $package );

			$package_classes = $this->get_package_classes( $package );

			/*
			 * 1. Hidden because a required class is missing. Evaluated BEFORE
			 * the excluded classes on purpose: if both fields were filled on
			 * the same instance, a missing required class wins and the method
			 * does not show.
			 */
			if ( ! $this->has_required_class( $package_classes ) ) {
				return;
			}

			// 2. Hidden by an excluded class.
			if ( $this->has_excluded_class( $package_classes ) ) {
				return;
			}

			// 3. Cost.
			$cost = $this->calculate_cost( $package, $package_classes );

			// 4. Free shipping, which overrides whatever the grid returned.
			if ( $this->is_free_for_package( $package_classes ) ) {
				$cost = 0.0;
			}

			/**
			 * Filters the cost about to be offered for this package.
			 *
			 * Last word on the price, after the grid, the class costs and
			 * free shipping. The intended use is a surcharge nothing here
			 * models — an island supplement, a fuel adjustment.
			 *
			 * @since 1.0.0
			 *
			 * @param float                       $cost    Cost excluding tax.
			 * @param array                       $package Billable package.
			 * @param Dahu_Weight_Shipping_Method $method  The method instance.
			 */
			$cost = (float) apply_filters( 'dahu_weight_shipping_cost', $cost, $package, $this );

			// 5. Offer the rate, switching the label when free.
			$title_free = (string) $this->get_option( 'title_free', '' );
			$label      = ( 0.0 === (float) $cost && '' !== $title_free ) ? $title_free : $this->title;

			$this->add_rate(
				array(
					'id'      => $this->get_rate_id(),
					'label'   => $label,
					'cost'    => $cost,
					'package' => $original_package,
				)
			);
		}
	}
}
