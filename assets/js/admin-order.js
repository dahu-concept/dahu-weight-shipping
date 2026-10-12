/**
 * The order screen.
 *
 * Three features, all living on WooCommerce's order edit screen:
 *
 *  1. "Add shipping" — one button that lists the shipping methods actually
 *     available for this order's destination, creates the line, attaches it
 *     to the exact zone instance chosen, and prices it.
 *  2. Pricing an existing line, including on WooCommerce's own
 *     "Recalculate", which is intercepted so the cost is refreshed before
 *     the native recalculation works out the tax on it.
 *  3. The shipping weight panel.
 *
 * Two things about this screen shape the whole file. WooCommerce rebuilds the
 * entire items panel after every change, so nothing can be bound once to an
 * element and trusted to survive; and every request here is slow enough to
 * be felt, so none is made that could have been avoided.
 *
 * @package DahuWeightShipping
 */

/* global jQuery, dahuWeightShipping */

( function ( $, settings ) {
	'use strict';

	if ( ! settings ) {
		return;
	}

	var i18n = settings.i18n || {};

	/** Guards against a second line being created while one is in flight. */
	var busy = false;

	/** True while this script is itself writing a cost field. */
	var programmaticCostChange = false;

	/** Lets the replayed native "Recalculate" click through the interceptor. */
	var skipIntercept = false;

	/** True while a cost refresh triggered by a "Recalculate" click runs. */
	var refreshing = false;

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Fills the placeholders of a translated string, in order.
	 *
	 * @param {string} template String holding %s or %1$s placeholders.
	 * @param {...string} args Replacements.
	 * @return {string}
	 */
	function format( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;

		return String( template || '' ).replace( /%(\d+\$)?s/g, function ( match, position ) {
			if ( position ) {
				return args[ parseInt( position, 10 ) - 1 ];
			}
			return args[ index++ ];
		} );
	}

	/**
	 * The order's id, read from the screen.
	 *
	 * #post_ID on both order screens: WooCommerce's own HPOS edit screen
	 * renders a hidden field with that same id, so one selector covers the
	 * modern and the legacy storage alike — checked against WooCommerce's
	 * source rather than assumed.
	 *
	 * The URL is the last resort, covering both shapes of edit link
	 * (?post=123 on the legacy screen, ?id=123 on the HPOS one).
	 *
	 * Absent on an order that has never been saved, which is a case to
	 * report rather than to work around: there is no line to attach
	 * anything to yet.
	 *
	 * @return {number}
	 */
	function getOrderId() {
		var $input = $( '#post_ID' );
		var id = $input.length ? parseInt( $input.val(), 10 ) : NaN;

		if ( ! isNaN( id ) && id > 0 ) {
			return id;
		}

		var match = /[?&](?:post|id)=(\d+)/.exec( window.location.search );

		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}

	/**
	 * The destination country as currently shown in the form.
	 *
	 * Read live rather than from the saved order, for the same reason the
	 * quantities are: on an order being edited the country chosen has not
	 * reached the database yet, so the stored order would answer with an
	 * empty string while a country is plainly selected on screen.
	 *
	 * @return {string}
	 */
	function getLiveCountry() {
		var shipping = $( '#_shipping_country' ).val();

		if ( shipping ) {
			return shipping;
		}

		return $( '#_billing_country' ).val() || '';
	}

	/**
	 * Order line ids and quantities AS SHOWN ON SCREEN, including a quantity
	 * just typed and not yet saved.
	 *
	 * The order LINE id is sent, not a product id scraped from the item's
	 * link: that link always points at the parent product, never at the
	 * variation actually ordered, and the variation is what carries its own
	 * shipping class.
	 *
	 * @return {Array<{order_item_id: number, quantity: number}>}
	 */
	function getLiveItems() {
		var items = [];

		$( '#order_line_items tr.item' ).each( function () {
			var $row = $( this );
			var orderItemId = parseInt( $row.data( 'order_item_id' ), 10 );
			var $qty = $row.find( 'input.quantity[name^="order_item_qty"]' );
			var quantity = $qty.length ? parseInt( $qty.val(), 10 ) : NaN;

			if ( ! orderItemId || isNaN( quantity ) || quantity <= 0 ) {
				return;
			}

			items.push( { order_item_id: orderItemId, quantity: quantity } );
		} );

		return items;
	}

	/**
	 * Posts to one of this plugin's endpoints.
	 *
	 * @param {string} action Action suffix.
	 * @param {Object} data   Extra fields.
	 * @return {jqXHR}
	 */
	function post( action, data ) {
		return $.post(
			settings.ajaxUrl,
			$.extend(
				{
					action: 'dahu_weight_shipping_' + action,
					nonce: settings.nonce
				},
				data || {}
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Recognising the rows this plugin prices
	 * ------------------------------------------------------------------ */

	/**
	 * The shipping method id of a shipping row.
	 *
	 * Read from the select WooCommerce renders on every shipping row, whose
	 * value IS the method id. That select sits inside a hidden .edit
	 * container until the row is opened for editing, which does not matter:
	 * it is in the markup either way, and reading a hidden field is exactly
	 * as reliable as reading a visible one.
	 *
	 * This is worth spelling out because the obvious alternative — matching
	 * the row's NAME against a fragment of the carrier's name — is wrong in
	 * two ways that only show up later. Instance titles are free text, so a
	 * renamed instance stops being recognised; and the match is textual, so
	 * it depends on the shop's language. An exact method id has neither
	 * problem.
	 *
	 * @param {jQuery} $row Shipping row.
	 * @return {string}
	 */
	function getRowMethodId( $row ) {
		return $row.find( 'select.shipping_method' ).val() || '';
	}

	/**
	 * Can this plugin work out the price of this row?
	 *
	 * @param {jQuery} $row Shipping row.
	 * @return {boolean}
	 */
	function isManagedRow( $row ) {
		var methodId = getRowMethodId( $row );

		if ( ! methodId ) {
			return false;
		}

		return ( settings.methodIds || [] ).indexOf( methodId ) !== -1;
	}

	/**
	 * Every shipping row this plugin can price.
	 *
	 * ALL of them, not the first. An order may legitimately carry two
	 * shipping lines — a parcel and a pallet — and pricing only the first
	 * would leave the second showing a figure that belongs to nothing, with
	 * nothing on screen to say it had been skipped.
	 *
	 * @return {jQuery[]}
	 */
	function findManagedRows() {
		var rows = [];

		$( '#order_shipping_line_items tr.shipping' ).each( function () {
			var $row = $( this );

			if ( isManagedRow( $row ) ) {
				rows.push( $row );
			}
		} );

		return rows;
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	/**
	 * Shows a message.
	 *
	 * Three tones, and the distinction matters more than it looks: most of
	 * what this plugin has to say is NORMAL operation that nonetheless needs
	 * explaining — a method that does not apply to this order, a price left
	 * to be typed in. A blocking alert() would be out of proportion for
	 * those, and silence would leave somebody staring at an empty field.
	 *
	 * @param {string} message Message, possibly several lines.
	 * @param {string} [tone]  'info', 'warning' or 'success'.
	 * @param {Object} [options] persist: true to keep it until replaced.
	 * @return {jQuery}
	 */
	function notice( message, tone, options ) {
		options = options || {};

		$( '.dahu-ws-notice' ).remove();

		var $notice = $( '<div class="dahu-ws-notice" role="status" aria-live="polite"></div>' )
			.addClass( 'dahu-ws-notice--' + ( tone || 'info' ) );

		$( '<div class="dahu-ws-notice__text"></div>' ).text( message ).appendTo( $notice );

		$( '<button type="button" class="dahu-ws-notice__close"></button>' )
			.attr( 'aria-label', i18n.dismiss || 'Dismiss' )
			.on( 'click', function () {
				$notice.remove();
			} )
			.appendTo( $notice );

		$notice.appendTo( 'body' );

		if ( ! options.persist ) {
			window.setTimeout(
				function () {
					$notice.fadeOut( 300, function () {
						$( this ).remove();
					} );
				},
				'warning' === tone ? 12000 : 7000
			);
		}

		return $notice;
	}

	/**
	 * Removes whatever notice is on screen.
	 *
	 * @return {void}
	 */
	function clearNotice() {
		$( '.dahu-ws-notice' ).remove();
	}

	/**
	 * A busy notice, which stays until something replaces it.
	 *
	 * Anchored to the body rather than to the items panel: WooCommerce
	 * rebuilds that panel while a line is being created, which would destroy
	 * anything attached to it. The body survives.
	 *
	 * @param {string} message Message.
	 * @return {jQuery}
	 */
	function busyNotice( message ) {
		return notice( message, 'info', { persist: true } ).addClass( 'dahu-ws-notice--busy' );
	}

	/**
	 * Appends the calculation details to a message.
	 *
	 * @param {string}   message Main message.
	 * @param {string[]} details Lines explaining the figure.
	 * @return {string}
	 */
	function withDetails( message, details ) {
		if ( ! details || ! details.length ) {
			return message;
		}

		return message + '\n— ' + details.join( '\n— ' );
	}

	/* ---------------------------------------------------------------------
	 * Writing a cost
	 * ------------------------------------------------------------------ */

	/**
	 * Puts an amount in the form a WooCommerce price field accepts.
	 *
	 * MANDATORY before writing to one, and not a display nicety.
	 *
	 * WooCommerce binds a sanitiser to those fields on 'keyup change' which
	 * STRIPS every character outside [0-9], '-', '%' and the site's decimal
	 * separator. On a shop using a comma, writing "8.33" and then firing
	 * 'change' — which is exactly what setCost does — makes the point
	 * disappear: the value becomes "833", and the tax is then worked out
	 * perfectly correctly on that wrong basis.
	 *
	 * This is not a thousands-separator confusion. The point is simply
	 * erased, which multiplies the amount by a hundred for two decimals and
	 * by ten for one.
	 *
	 * @param {number|string} value Amount, decimal point, as JSON carries it.
	 * @return {string}
	 */
	function formatPriceForInput( value ) {
		var amount = parseFloat( value );

		if ( isNaN( amount ) ) {
			return '';
		}

		return amount.toFixed( 2 ).replace( '.', settings.decimalSep );
	}

	/**
	 * Writes a cost into a shipping row, marking the change as this
	 * script's own.
	 *
	 * THE single place where this plugin writes to a price field, so the
	 * formatting above happens once and cannot be forgotten somewhere else.
	 * The flag is what stops our own calculation from being mistaken for
	 * somebody editing the price by hand, which would switch automatic
	 * pricing off for that line.
	 *
	 * @param {jQuery} $costInput The cost field.
	 * @param {number} value      Amount.
	 * @return {void}
	 */
	function setCost( $costInput, value ) {
		programmaticCostChange = true;
		$costInput.val( formatPriceForInput( value ) ).trigger( 'change' );
		programmaticCostChange = false;
	}

	/**
	 * Tells the server a line's cost has been edited by hand.
	 *
	 * @param {jQuery} $row Shipping row.
	 * @return {void}
	 */
	function markManualOverride( $row ) {
		var orderItemId = parseInt( $row.data( 'order_item_id' ), 10 );
		var orderId = getOrderId();

		if ( ! orderItemId || ! orderId ) {
			return;
		}

		post( 'mark_manual', {
			order_id: orderId,
			shipping_order_item_id: orderItemId
		} );
	}

	// Any hand edit of a cost field on a row this plugin prices switches
	// automatic pricing off for that line, for good. Somebody who overrides
	// a price has a reason this plugin cannot see, and overwriting it on the
	// next recalculation is how people stop trusting an automation.
	//
	// Delegated on document: these rows are recreated every time WooCommerce
	// rebuilds the items panel.
	$( document ).on( 'change', '#order_shipping_line_items input[name^="shipping_cost"]', function () {
		if ( programmaticCostChange ) {
			return;
		}

		var $row = $( this ).closest( 'tr.shipping' );

		if ( ! isManagedRow( $row ) ) {
			return;
		}

		markManualOverride( $row );
	} );

	/* ---------------------------------------------------------------------
	 * Pricing a line
	 * ------------------------------------------------------------------ */

	/**
	 * Handles a pricing response: writes the amount, or says why it did not.
	 *
	 * Shared by both entry points — creating a line and "Recalculate" —
	 * which return exactly the same shape of response and therefore have to
	 * treat it exactly the same way.
	 *
	 * @param {jQuery} $row     Shipping row.
	 * @param {Object} response AJAX response.
	 * @return {string} 'cost', 'manual', 'no_rate', 'skip' or 'error'.
	 */
	function applyCostResponse( $row, response ) {
		if ( ! response || ! response.success || ! response.data ) {
			return 'error';
		}

		// The weight travels with the pricing response, so the side panel
		// refreshes itself without a request of its own. Done before the
		// branching below so no branch can forget it.
		setOrderWeight( response.data.order_weight_display );

		if ( response.data.skip ) {
			// Edited by hand on this line: never touched again.
			return 'skip';
		}

		// Normal operation, not a failure: a method this plugin cannot
		// price, or a line with no zone instance attached.
		if ( response.data.manual ) {
			notice( withDetails( response.data.message, response.data.details ), 'info' );
			return 'manual';
		}

		// The method does not apply to this order. Nothing is written to the
		// cost field, but it is said out loud: a 0 sitting there would read
		// as free shipping, which is a different statement entirely.
		if ( response.data.no_rate ) {
			notice( withDetails( response.data.message, response.data.details ), 'warning' );
			return 'no_rate';
		}

		if ( 'undefined' === typeof response.data.cost ) {
			return 'error';
		}

		var $costInput = $row.find( 'input[name^="shipping_cost"]' );

		if ( ! $costInput.length ) {
			return 'error';
		}

		setCost( $costInput, response.data.cost );

		notice(
			withDetails(
				format(
					i18n.costCalculated,
					formatPriceForInput( response.data.cost ) + ' ' + ( settings.currencySymbol || '' )
				),
				response.data.details
			),
			'success'
		);

		return 'cost';
	}

	/**
	 * Works out and writes the cost of one shipping row.
	 *
	 * @param {jQuery}   $row     Shipping row.
	 * @param {Function} callback Called with the outcome, success or not.
	 * @return {void}
	 */
	function fillShippingCost( $row, callback ) {
		var orderId = getOrderId();

		// Pessimistic default: until a branch has ruled, the operation
		// counts as failed. An optimistic one would fire the native
		// recalculation on a line whose state is unknown.
		var outcome = 'error';

		if ( ! orderId ) {
			if ( callback ) {
				callback( outcome );
			}
			return;
		}

		post( 'calculate', {
			order_id: orderId,
			country: getLiveCountry(),
			shipping_order_item_id: parseInt( $row.data( 'order_item_id' ), 10 ) || 0,
			live_items: JSON.stringify( getLiveItems() )
		} )
			.done( function ( response ) {
				outcome = applyCostResponse( $row, response );
			} )
			.always( function () {
				if ( callback ) {
					callback( outcome );
				}
			} );
	}

	/**
	 * Prices several rows one after another, then reports.
	 *
	 * Sequential and not parallel: these requests are slow and the server is
	 * usually the bottleneck, so firing them together would not make them
	 * finish sooner — and each one writes to the same screen.
	 *
	 * @param {jQuery[]} rows     Rows to price.
	 * @param {Function} callback Called once, when all of them are done.
	 * @return {void}
	 */
	function fillShippingCosts( rows, callback ) {
		var index = 0;
		var outcomes = [];

		function next() {
			if ( index >= rows.length ) {
				if ( callback ) {
					callback( outcomes );
				}
				return;
			}

			var $row = rows[ index++ ];

			fillShippingCost( $row, function ( outcome ) {
				outcomes.push( outcome );
				next();
			} );
		}

		next();
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce's own "Recalculate"
	 * ------------------------------------------------------------------ */

	/**
	 * Runs a function with window.confirm forced to true.
	 *
	 * WooCommerce asks for confirmation before recalculating. The click
	 * replayed below is the direct consequence of a click somebody already
	 * made, and recalculating totals destroys nothing, so asking twice would
	 * only be noise.
	 *
	 * The try/finally is the point of having this helper. Without it, an
	 * exception thrown by any WooCommerce handler during the click would
	 * leave window.confirm overridden for the rest of the page — and every
	 * later confirmation, a deletion included, would then be accepted in
	 * silence.
	 *
	 * @param {Function} fn Function to run.
	 * @return {void}
	 */
	function withConfirmSuppressed( fn ) {
		var original = window.confirm;

		window.confirm = function () {
			return true;
		};

		try {
			fn();
		} finally {
			window.confirm = original;
		}
	}

	/*
	 * Intercepts every click on "Recalculate", whoever made it: the costs of
	 * the rows this plugin prices are refreshed from the order's current
	 * weight FIRST, and only then is WooCommerce's native recalculation —
	 * tax and display — allowed to run, by replaying a real click on the
	 * same button.
	 *
	 * Capture phase, so this runs before WooCommerce's own jQuery handlers.
	 *
	 * skipIntercept is set true ONLY just before the replayed click, never
	 * earlier: setting it up front is what once made the very first click
	 * pass through unintercepted.
	 */
	document.addEventListener(
		'click',
		function ( event ) {
			var button = event.target.closest && event.target.closest( '.calculate-action' );

			if ( ! button ) {
				return;
			}

			if ( skipIntercept ) {
				skipIntercept = false;
				return; // The replayed click: let WooCommerce have it.
			}

			if ( refreshing ) {
				return; // A refresh is already running.
			}

			var rows = findManagedRows();

			if ( ! rows.length ) {
				return; // Nothing here for this plugin to price.
			}

			event.preventDefault();
			event.stopPropagation();

			refreshing = true;
			button.disabled = true;
			busyNotice( i18n.calculating );

			fillShippingCosts( rows, function () {
				refreshing = false;
				button.disabled = false;

				withConfirmSuppressed( function () {
					skipIntercept = true;
					button.click();
				} );
			} );
		},
		true
	);

	/**
	 * Fires WooCommerce's own "Recalculate" once a shipping line is complete.
	 *
	 * WHY. The method, title and instance are persisted server-side as soon
	 * as the line is created. The COST is not: it is written into a DOM
	 * field, and only reaches the database when the form is next submitted.
	 * This click IS that submission — it persists the cost, works out the
	 * tax, and returns the row to its display state.
	 *
	 * skipIntercept is set BEFORE the click. Without it, the interceptor
	 * above would see a row it prices, block this click and start a pricing
	 * request identical to the one that has just finished — a wasted round
	 * trip on a screen where every one of them is felt.
	 *
	 * @return {void}
	 */
	function triggerRecalculate() {
		// A refresh started by somebody's own click is already running: the
		// interceptor would ignore this click, which would then be lost in
		// silence. That refresh will submit the form anyway, cost included.
		if ( refreshing ) {
			return;
		}

		// WooCommerce rebuilds the items panel while the line is created, so
		// the button may not be the one seen earlier, or may not be there.
		var $button = $( '.calculate-action' );

		if ( ! $button.length ) {
			return;
		}

		withConfirmSuppressed( function () {
			skipIntercept = true;
			$button.get( 0 ).click();
		} );
	}

	/* ---------------------------------------------------------------------
	 * Creating a shipping line
	 * ------------------------------------------------------------------ */

	/**
	 * Writes the chosen method and title onto the row that has just been
	 * created, then attaches the zone instance.
	 *
	 * WooCommerce's own select knows method TYPES only, never a particular
	 * instance: two instances of one method in one zone appear there as a
	 * single entry. So the instance id is ours to write, through a request
	 * made once the line exists and its id is known.
	 *
	 * @param {Object} method Chosen method.
	 * @return {boolean} True when the row was found and filled.
	 */
	function fillLatestShippingRow( method ) {
		var $row = $( '#order_shipping_line_items tr.shipping' ).last();

		if ( ! $row.length ) {
			return false;
		}

		var $select = $row.find( 'select.shipping_method' );
		var $title = $row.find( '.shipping_method_name' );

		if ( ! $select.length || ! $title.length ) {
			return false;
		}

		$select.val( method.id ).trigger( 'change' );
		$title.val( method.title );

		attachInstance( $row, method );

		return true;
	}

	/**
	 * Persists the zone instance on the new line AND prices it, in one
	 * request.
	 *
	 * One request, not two. Splitting them meant the second read back from
	 * the database the method and instance the first had just written there
	 * — a whole round trip to recover something both sides already knew.
	 *
	 * country and live_items are sent here for a reason worth stating:
	 * leaving them out produces no error at all. The calculation would
	 * quietly fall back to the quantities in the database and an empty
	 * country, and return a plausible but wrong price whenever the screen
	 * differs from the database — which is the normal state of an order
	 * being edited.
	 *
	 * @param {jQuery} $row   The new shipping row.
	 * @param {Object} method Chosen method.
	 * @return {void}
	 */
	function attachInstance( $row, method ) {
		var orderItemId = parseInt( $row.data( 'order_item_id' ), 10 );
		var orderId = getOrderId();

		if ( ! orderItemId || ! orderId || ! method.instance_id ) {
			return;
		}

		post( 'set_instance', {
			order_id: orderId,
			shipping_order_item_id: orderItemId,
			instance_id: method.instance_id,
			method_id: method.id,
			method_title: method.title,
			country: getLiveCountry(),
			live_items: JSON.stringify( getLiveItems() )
		} )
			.done( function ( response ) {
				// Which methods can be priced is decided SERVER-SIDE, the one
				// place that list is written. This only reads the verdict.
				var outcome = applyCostResponse( $row, response );

				/*
				 * no_rate: the method does not apply to this order and no cost
				 * was written. Recalculating would give that inapplicable line
				 * the look of a finished line at zero, contradicting the
				 * warning just shown. Leave it to somebody to decide: fix the
				 * zone, or delete the line.
				 */
				if ( 'no_rate' === outcome ) {
					return;
				}

				triggerRecalculate();
			} );
	}

	/**
	 * Waits for WooCommerce to create the new row, then fills it.
	 *
	 * @param {number}   initialCount Rows before the creation.
	 * @param {Object}   method       Chosen method.
	 * @param {number}   attemptsLeft Attempts remaining.
	 * @param {jQuery}   $button      The button to re-enable.
	 * @return {void}
	 */
	function waitForNewRow( initialCount, method, attemptsLeft, $button ) {
		var currentCount = $( '#order_shipping_line_items tr.shipping' ).length;

		if ( currentCount > initialCount && fillLatestShippingRow( method ) ) {
			busy = false;
			clearNotice();

			if ( $button ) {
				$button.prop( 'disabled', false );
			}

			return;
		}

		if ( attemptsLeft <= 0 ) {
			busy = false;

			if ( $button ) {
				$button.prop( 'disabled', false );
			}

			// A VISIBLE message, not a console warning nobody reads: if the
			// native request does finish after this, an empty row will appear
			// having never been filled in.
			notice( i18n.slowCreation, 'warning', { persist: true } );

			return;
		}

		window.setTimeout( function () {
			waitForNewRow( initialCount, method, attemptsLeft - 1, $button );
		}, settings.attemptDelay );
	}

	/**
	 * Creates the shipping line for the chosen method and instance.
	 *
	 * The line itself is created by WooCommerce's own request, by clicking
	 * its button. That button is hidden by this plugin's stylesheet but
	 * stays in the DOM, enabled, with its handlers intact — a CSS-hidden
	 * element receives and handles a programmatic click perfectly well,
	 * since nothing is being rendered. Disabling it or removing it would
	 * break line creation entirely.
	 *
	 * @param {Object} method  Chosen method.
	 * @param {jQuery} $button The button to disable while this runs.
	 * @return {void}
	 */
	function createShippingLine( method, $button ) {
		if ( busy ) {
			return;
		}

		if ( ! getOrderId() ) {
			notice( i18n.saveFirst, 'warning' );
			return;
		}

		busy = true;

		if ( $button ) {
			$button.prop( 'disabled', true );
		}

		// Without an indicator, nothing distinguishes a slow operation from a
		// stuck one, and people click again while the first request is still
		// in flight — which creates duplicate lines.
		busyNotice( i18n.creatingLine );

		var initialCount = $( '#order_shipping_line_items tr.shipping' ).length;

		$( '.add-order-shipping' ).trigger( 'click' );

		waitForNewRow( initialCount, method, settings.maxAttempts, $button );
	}

	/* ---------------------------------------------------------------------
	 * The zone's methods
	 * ------------------------------------------------------------------ */

	/*
	 * The list is cached and prefetched when the page opens.
	 *
	 * Fetching it on click meant the picker stayed shut for the length of
	 * that request, in front of somebody who had not yet chosen anything:
	 * the most visible dead time of the sequence.
	 *
	 * The country is the cache key, and not out of caution: the list depends
	 * on the zone, so on the country shown on screen, which can change while
	 * an order is being edited. A list prefetched for one country and served
	 * for another would offer the methods of a zone that no longer applies.
	 */
	var zoneCache = null;
	var zoneCountry = null;
	var zoneLoading = false;
	var zoneWaiters = [];

	/**
	 * Hands the loaded list to everybody waiting for it.
	 *
	 * @param {Object|null} payload Methods and zone name, or null on failure.
	 * @return {void}
	 */
	function resolveZoneMethods( payload ) {
		zoneLoading = false;

		var waiters = zoneWaiters;
		zoneWaiters = [];

		waiters.forEach( function ( waiter ) {
			waiter( payload );
		} );
	}

	/**
	 * Loads the shipping methods available for the destination on screen.
	 *
	 * @param {Function} callback Called with the payload, or null on failure.
	 * @return {void}
	 */
	function loadZoneMethods( callback ) {
		var country = getLiveCountry();

		// Cache valid for the country currently shown: answer at once.
		if ( null !== zoneCache && country === zoneCountry ) {
			callback( zoneCache );
			return;
		}

		// A request for this country is already in flight: join it rather
		// than start a second. Without this, a click during the initial
		// prefetch would double the call.
		if ( zoneLoading && country === zoneCountry ) {
			zoneWaiters.push( callback );
			return;
		}

		// A request for ANOTHER country is in flight, and its callbacks are
		// waiting for an answer that no longer means anything. Release them
		// with null rather than leave them hanging — a button would stay
		// disabled forever.
		if ( zoneLoading ) {
			resolveZoneMethods( null );
		}

		zoneLoading = true;
		zoneCountry = country;
		zoneWaiters = [ callback ];

		post( 'zone_methods', { country: country } )
			.done( function ( response ) {
				var payload = ( response && response.success && response.data )
					? {
						methods: response.data.methods || [],
						zoneName: response.data.zone_name || ''
					}
					: { methods: [], zoneName: '' };

				zoneCache = payload;
				resolveZoneMethods( payload );
			} )
			.fail( function () {
				// A network failure is NOT cached: the next click has to be
				// able to retry, not inherit an empty list for good.
				zoneCache = null;
				zoneCountry = null;
				resolveZoneMethods( null );
			} );
	}

	// Changing the country empties the cache and prefetches again, so the
	// next click still finds the new list waiting.
	$( document ).on( 'change', '#_shipping_country, #_billing_country', function () {
		zoneCache = null;
		zoneCountry = null;
		loadZoneMethods( function () {} );
	} );

	/* ---------------------------------------------------------------------
	 * The method picker
	 * ------------------------------------------------------------------ */

	/**
	 * Closes the picker.
	 *
	 * @return {void}
	 */
	function closePicker() {
		$( '.dahu-ws-picker' ).remove();
		$( document ).off( 'keydown.dahuWsPicker click.dahuWsPicker' );
	}

	/**
	 * Builds the picker next to the button.
	 *
	 * A list of buttons rather than a select, for three reasons that all
	 * matter on this screen: each entry can say whether this plugin will
	 * price it or whether the amount has to be typed in, the zone the list
	 * came from can be named — the most common cause of a surprising list is
	 * the wrong country on the order — and one click chooses, where a select
	 * takes two.
	 *
	 * @param {Object} payload Methods and zone name.
	 * @param {jQuery} $button The button it hangs from.
	 * @return {void}
	 */
	function showMethodPicker( payload, $button ) {
		closePicker();

		var $picker = $( '<div class="dahu-ws-picker" role="dialog"></div>' )
			.attr( 'aria-label', i18n.chooseMethod );

		var $header = $( '<div class="dahu-ws-picker__header"></div>' );

		$( '<span class="dahu-ws-picker__title"></span>' ).text( i18n.chooseMethod ).appendTo( $header );

		if ( payload.zoneName ) {
			$( '<span class="dahu-ws-picker__zone"></span>' )
				.text( format( i18n.zoneLabel, payload.zoneName ) )
				.appendTo( $header );
		}

		$header.appendTo( $picker );

		var $list = $( '<ul class="dahu-ws-picker__list"></ul>' );

		payload.methods.forEach( function ( method ) {
			var $entry = $( '<button type="button" class="dahu-ws-picker__option"></button>' );

			$( '<span class="dahu-ws-picker__option-title"></span>' ).text( method.title ).appendTo( $entry );

			$( '<span class="dahu-ws-picker__option-hint"></span>' )
				.addClass( method.calculable ? 'is-auto' : 'is-manual' )
				.text( method.calculable ? i18n.autoPriced : i18n.manualPriced )
				.appendTo( $entry );

			$entry.on( 'click', function () {
				closePicker();
				createShippingLine( method, $button );
			} );

			$( '<li></li>' ).append( $entry ).appendTo( $list );
		} );

		$list.appendTo( $picker );
		$button.after( $picker );

		$picker.find( '.dahu-ws-picker__option' ).first().trigger( 'focus' );

		$( document ).on( 'keydown.dahuWsPicker', function ( event ) {
			if ( 'Escape' === event.key ) {
				closePicker();
				$button.trigger( 'focus' );
			}
		} );

		// Bound on the next tick so the click that opened the picker does not
		// immediately close it.
		window.setTimeout( function () {
			$( document ).on( 'click.dahuWsPicker', function ( event ) {
				if ( ! $( event.target ).closest( '.dahu-ws-picker, .dahu-ws-add-shipping' ).length ) {
					closePicker();
				}
			} );
		}, 0 );
	}

	/* ---------------------------------------------------------------------
	 * The button
	 * ------------------------------------------------------------------ */

	/**
	 * Injects the "Add shipping" button into the items panel.
	 *
	 * Called again after every rebuild of that panel, so it has to be
	 * idempotent.
	 *
	 * @return {void}
	 */
	function addButton() {
		var $container = $( '.wc-order-bulk-actions .add-items' );

		if ( ! $container.length || $container.find( '.dahu-ws-add-shipping' ).length ) {
			return;
		}

		var $anchor = $container.find( '.add-line-item' );

		if ( ! $anchor.length ) {
			return;
		}

		var $button = $( '<button type="button" class="button dahu-ws-add-shipping"></button>' )
			.text( i18n.addShipping );

		// WooCommerce rebuilds the panel while a line is being created, so
		// this button is recreated while that creation is still running: it
		// has to be born disabled, or the busy lock is bypassed visually and
		// somebody clicks again.
		if ( busy ) {
			$button.prop( 'disabled', true );
		}

		$button.on( 'click', function ( event ) {
			event.preventDefault();

			if ( busy ) {
				return;
			}

			// Picker already open: a second click closes it.
			if ( $( '.dahu-ws-picker' ).length ) {
				closePicker();
				return;
			}

			if ( ! getOrderId() ) {
				notice( i18n.saveFirst, 'warning' );
				return;
			}

			$button.prop( 'disabled', true );

			// Rare case: a click right after the page opens, before the
			// prefetch has answered. A disabled button alone would say
			// nothing, hence a visible state, removed as soon as it answers.
			var prefetched = ( null !== zoneCache && getLiveCountry() === zoneCountry );

			if ( ! prefetched ) {
				busyNotice( i18n.loadingMethods );
			}

			loadZoneMethods( function ( payload ) {
				$button.prop( 'disabled', false );

				if ( ! prefetched ) {
					clearNotice();
				}

				if ( null === payload ) {
					notice( i18n.networkError, 'warning' );
					return;
				}

				if ( ! payload.methods.length ) {
					notice( i18n.noMethods, 'warning', { persist: true } );
					return;
				}

				showMethodPicker( payload, $button );
			} );
		} );

		$anchor.after( $button );

		/*
		 * WooCommerce's own "Add shipping" button is hidden only once ours is
		 * actually on screen — the class is added here, after a successful
		 * injection, and the stylesheet does nothing without it. If ours is
		 * missing because the script failed, the native button stays visible
		 * and there is still a way to add a shipping line.
		 *
		 * The hiding is PURELY visual; see createShippingLine.
		 */
		$( 'body' ).addClass( 'dahu-ws-has-add-shipping' );
	}

	/* ---------------------------------------------------------------------
	 * The weight panel
	 * ------------------------------------------------------------------ */

	/**
	 * Updates the weight shown, and clears the stale state.
	 *
	 * The value is the string the SERVER formatted: the browser has no
	 * business guessing the site's number conventions.
	 *
	 * @param {string} display Formatted weight.
	 * @return {void}
	 */
	function setOrderWeight( display ) {
		var $value = $( '#dahu-ws-weight-value' );

		if ( ! $value.length || 'undefined' === typeof display || null === display ) {
			return;
		}

		$value.text( display );
		$( '#dahu-ws-weight-panel' ).removeClass( 'is-stale' );
		$( '#dahu-ws-weight-stale' ).prop( 'hidden', true );
	}

	/**
	 * Says the weight shown no longer matches the screen.
	 *
	 * No network call here, and that is the entire point: a round trip costs
	 * seconds on a modest host, so refreshing on every keystroke in a
	 * quantity field would make the screen unusable. Saying the figure has
	 * aged is enough; somebody decides whether to refresh it.
	 *
	 * @return {void}
	 */
	function markOrderWeightStale() {
		if ( ! $( '#dahu-ws-weight-value' ).length ) {
			return;
		}

		$( '#dahu-ws-weight-panel' ).addClass( 'is-stale' );
		$( '#dahu-ws-weight-stale' ).prop( 'hidden', false );
	}

	$( document ).on( 'change keyup', '#order_line_items input.quantity', markOrderWeightStale );

	// The only network call dedicated to this panel.
	$( document ).on( 'click', '#dahu-ws-weight-refresh', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var orderId = getOrderId();

		if ( ! orderId ) {
			return;
		}

		$button.prop( 'disabled', true ).text( i18n.refreshing );

		post( 'order_weight', {
			order_id: orderId,
			live_items: JSON.stringify( getLiveItems() )
		} )
			.done( function ( response ) {
				if ( response && response.success && response.data ) {
					setOrderWeight( response.data.order_weight_display );
				}
			} )
			.always( function () {
				$button.prop( 'disabled', false ).text( i18n.refresh );
			} );
	} );

	/* ---------------------------------------------------------------------
	 * Start-up
	 * ------------------------------------------------------------------ */

	$( function () {
		addButton();

		/*
		 * Prefetch the zone's methods as the page opens, so the picker opens
		 * with no wait in the common case.
		 *
		 * The cost is one request on every order screen, including the ones
		 * where nobody touches the button. That is accepted: it goes out in
		 * the background and blocks nothing, replacing a wait somebody would
		 * otherwise sit through.
		 */
		loadZoneMethods( function () {} );

		/*
		 * WooCommerce rebuilds the items panel on every change — adding a
		 * product, recalculating — which destroys our button along with it.
		 * Watching for that is the only way to put it back.
		 *
		 * Debounced, because a rebuild arrives as a burst of mutations and
		 * reacting to each one is how duplicate buttons appear.
		 */
		var $panel = $( '#woocommerce-order-items' );

		if ( ! $panel.length || 'undefined' === typeof window.MutationObserver ) {
			return;
		}

		var timer = null;

		new window.MutationObserver( function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( addButton, 100 );
		} ).observe( $panel.get( 0 ), { childList: true, subtree: true } );
	} );
}( jQuery, window.dahuWeightShipping ) );
