<?php
/**
 * Builds the local wholesale order from a partner order payload.
 *
 *  - SKU-matches line items to our products (unmatched SKUs are skipped and
 *    reported — partners may sell non-ULP items).
 *  - Prices every line at the PARTNER's rate: their B2BKing individual
 *    override → group fixed sale/regular → group quantity tier for the line
 *    qty → base product price. (Self-contained resolver; same logic verified
 *    against production data in the notifications plugin.)
 *  - Ships to the END CUSTOMER; bills the PARTNER (their account email — so
 *    all WooCommerce emails go to the partner, never their customer).
 *  - Shipping line auto-calculated from our shipping zones using the
 *    partner's configured method (default "2-Day Shipping"), with a flat
 *    fallback. Editable by admin before release — nothing ships until the
 *    order is manually flipped to Processing after payment.
 *  - Tax: totals are calculated normally; the partner's AvaTax RESALE
 *    exemption applies exactly as it does on their manual orders (confirmed
 *    desired behaviour).
 *
 * @package ULP_Dropship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULP_DS_Builder {

	/* =====================================================================
	 * Order creation
	 * ================================================================== */

	/**
	 * @param string $slug      Partner slug.
	 * @param array  $partner   Partner config (user_id, ship_method, ship_fallback...).
	 * @param array  $payload   Decoded partner order JSON (Woo REST shape).
	 * @param bool   $simulated Mark simulated orders clearly.
	 * @return array|WP_Error { order_id, order_number, item_count, skipped_skus }
	 */
	public static function create_order( $slug, $partner, array $payload, $simulated = false ) {
		$user_id = (int) $partner['user_id'];
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'ulp_ds_no_user', 'Partner user account not found.' );
		}

		// ── Match line items by SKU ───────────────────────────────────────────
		// Per-partner SKU map first (their SKU → our SKU); anything without a
		// mapping entry must match our SKU directly (unchanged behavior).
		$sku_map      = isset( $partner['sku_map'] ) && is_array( $partner['sku_map'] ) ? $partner['sku_map'] : array();
		$lines        = array();
		$skipped_skus = array();
		$mapped_used  = array();

		foreach ( (array) ( isset( $payload['line_items'] ) ? $payload['line_items'] : array() ) as $item ) {
			$sku = isset( $item['sku'] ) ? trim( (string) $item['sku'] ) : '';
			$qty = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			if ( '' === $sku ) {
				$skipped_skus[] = '(no sku: ' . ( isset( $item['name'] ) ? $item['name'] : '?' ) . ')';
				continue;
			}

			$lookup = $sku;
			$mapped = false;
			$map_key = strtolower( $sku );
			if ( isset( $sku_map[ $map_key ] ) ) {
				$lookup = $sku_map[ $map_key ];
				$mapped = true;
			}

			$product_id = wc_get_product_id_by_sku( $lookup );
			if ( ! $product_id ) {
				$skipped_skus[] = $mapped
					? sprintf( '%s (mapped → %s, target not found!)', $sku, $lookup )
					: $sku;
				continue;
			}

			if ( $mapped ) {
				$mapped_used[] = $sku . ' → ' . $lookup;
			}
			$lines[] = array( 'product_id' => $product_id, 'sku' => $lookup, 'qty' => $qty );
		}

		if ( empty( $lines ) ) {
			return new WP_Error( 'ulp_ds_no_items', 'No line items matched our SKUs. Skipped: ' . implode( ', ', $skipped_skus ) );
		}

		// ── Create the order ──────────────────────────────────────────────────
		$order = wc_create_order( array(
			'customer_id' => $user_id,
			'created_via' => 'ulp-dropship',
		) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$item_count = 0;
		foreach ( $lines as $line ) {
			$product = wc_get_product( $line['product_id'] );
			if ( ! $product ) {
				continue;
			}

			$unit  = self::unit_price( $line['product_id'], $user_id, $line['qty'] );
			$total = round( $unit * $line['qty'], 2 );

			$order_item = new WC_Order_Item_Product();
			$order_item->set_product( $product );
			$order_item->set_quantity( $line['qty'] );
			$order_item->set_subtotal( $total );
			$order_item->set_total( $total );
			$order->add_item( $order_item );
			$item_count += $line['qty'];
		}

		// ── Addresses: bill the partner, ship to their end customer ──────────
		$customer = new WC_Customer( $user_id );
		$order->set_address( array(
			'first_name' => $customer->get_billing_first_name() ?: $user->first_name,
			'last_name'  => $customer->get_billing_last_name() ?: $user->last_name,
			'company'    => $customer->get_billing_company(),
			'address_1'  => $customer->get_billing_address_1(),
			'address_2'  => $customer->get_billing_address_2(),
			'city'       => $customer->get_billing_city(),
			'state'      => $customer->get_billing_state(),
			'postcode'   => $customer->get_billing_postcode(),
			'country'    => $customer->get_billing_country() ?: 'US',
			'email'      => $user->user_email, // Woo emails go to the PARTNER.
			'phone'      => $customer->get_billing_phone(),
		), 'billing' );

		$ship = isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) ? $payload['shipping'] : array();
		$order->set_address( array(
			'first_name' => isset( $ship['first_name'] ) ? $ship['first_name'] : '',
			'last_name'  => isset( $ship['last_name'] ) ? $ship['last_name'] : '',
			'company'    => isset( $ship['company'] ) ? $ship['company'] : '',
			'address_1'  => isset( $ship['address_1'] ) ? $ship['address_1'] : '',
			'address_2'  => isset( $ship['address_2'] ) ? $ship['address_2'] : '',
			'city'       => isset( $ship['city'] ) ? $ship['city'] : '',
			'state'      => isset( $ship['state'] ) ? $ship['state'] : '',
			'postcode'   => isset( $ship['postcode'] ) ? $ship['postcode'] : '',
			'country'    => isset( $ship['country'] ) && $ship['country'] ? $ship['country'] : 'US',
		), 'shipping' );

		if ( empty( $ship['address_1'] ) ) {
			$order->add_order_note( 'ULP Dropship: WARNING — partner payload had no shipping address.' );
		}

		// ── Private Label Fee (mirrors the checkout snippet, which only runs
		//    on carts — API orders would silently skip it otherwise) ──────────
		self::maybe_add_label_fee( $order, $partner, $user_id );

		// ── Flat per-order Handling Fee (per-partner setting) ────────────────
		$handling = isset( $partner['handling_fee'] ) ? (float) $partner['handling_fee'] : 0;
		if ( $handling > 0 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( 'Handling Fee' );
			// amount AND total: set_total() alone leaves _fee_amount empty,
			// which the QuickBooks (OneSaas) connector reads as $0.
			$fee->set_amount( round( $handling, 2 ) );
			$fee->set_total( round( $handling, 2 ) );
			$fee->set_tax_status( 'none' );
			$order->add_item( $fee );
		}

		// ── Shipping line: partner-selected option first, else zone rate ─────
		// Partners can send a shipping choice token in their webhook meta
		// (e.g. trufusion_shipping_method = 2day|express|pickup). The PRICE
		// always comes from OUR per-partner option map — never from their
		// payload. Unknown/missing token falls back to zone resolution with a
		// loud note.
		$ship_meta_key = isset( $partner['ship_meta_key'] ) ? trim( (string) $partner['ship_meta_key'] ) : '';
		$ship_options  = isset( $partner['ship_options'] ) && is_array( $partner['ship_options'] ) ? $partner['ship_options'] : array();
		$ship_token    = '';
		$shipping      = null;

		if ( '' !== $ship_meta_key && ! empty( $ship_options ) ) {
			$ship_token = strtolower( trim( self::payload_meta( $payload, $ship_meta_key ) ) );
			if ( '' !== $ship_token && isset( $ship_options[ $ship_token ] ) ) {
				$opt      = $ship_options[ $ship_token ];
				$shipping = array(
					'title'     => $opt['title'],
					'method_id' => 'flat_rate',
					'cost'      => (float) $opt['price'],
					'note'      => sprintf( 'Partner-selected shipping "%s" → %s ($%s).', $ship_token, $opt['title'], number_format( (float) $opt['price'], 2 ) ),
				);
			}
		}

		if ( null === $shipping ) {
			$shipping = self::resolve_shipping( $order, $partner );
			if ( '' !== $ship_meta_key && ! empty( $ship_options ) ) {
				$shipping['note'] .= ( '' !== $ship_token )
					? sprintf( ' Partner sent UNKNOWN shipping option "%s" — zone rate used, ADJUST BEFORE RELEASING if needed.', $ship_token )
					: ' Partner sent no shipping option — zone rate used.';
			}
		}

		$ship_item = new WC_Order_Item_Shipping();
		$ship_item->set_method_title( $shipping['title'] );
		$ship_item->set_method_id( $shipping['method_id'] );
		$ship_item->set_total( $shipping['cost'] );
		$order->add_item( $ship_item );
		if ( '' !== $ship_token ) {
			$order->update_meta_data( '_ulp_ds_ship_option', $ship_token );
		}

		// ── Meta, totals, status ──────────────────────────────────────────────
		$origin_id  = (string) $payload['id'];
		$origin_num = isset( $payload['number'] ) ? (string) $payload['number'] : $origin_id;

		$order->update_meta_data( '_ulp_ds_origin_key', $slug . ':' . $origin_id );
		$order->update_meta_data( '_ulp_ds_partner', $slug );
		$order->update_meta_data( '_ulp_ds_origin_order', $origin_num );
		// Full incoming payload, kept with the order for debugging/auditing.
		// Lives and dies with the order; never autoloaded.
		$order->update_meta_data( '_ulp_ds_raw_payload', wp_json_encode( $payload ) );
		if ( ! empty( $partner['private_label_name'] ) ) {
			// Exported to ShipStation as Custom Field 3 (packing slips / automation).
			$order->update_meta_data( '_ulp_ds_private_label', sanitize_text_field( $partner['private_label_name'] ) );
		}
		if ( ! empty( $payload['billing']['email'] ) ) {
			// End-customer contact kept in meta ONLY — never used as a send-to.
			$order->update_meta_data( '_ulp_ds_end_customer_email', sanitize_email( $payload['billing']['email'] ) );
		}
		if ( $simulated ) {
			$order->update_meta_data( '_ulp_ds_simulated', '1' );
		}

		$order->calculate_totals(); // AvaTax exemption on the partner user applies here.

		// ── Deposit credit: consume available partner credit ─────────────────
		// Fully covered → skip the payment hold and release straight to
		// Processing (which also triggers the referral, like a manual release).
		// Simulated orders never touch real balances.
		$credit_applied = 0.0;
		$credit_after   = null;
		$fully_covered  = false;
		if ( ! $simulated ) {
			$balance = ulp_ds_get_credit( $slug );
			$grand   = (float) $order->get_total();
			if ( $balance > 0 && $grand > 0 ) {
				$credit_applied = min( $balance, $grand );
				$credit_after   = ulp_ds_adjust_credit( $slug, -$credit_applied, sprintf( 'applied to incoming order (their #%s)', $origin_num ) );
				$fully_covered  = ( $credit_applied >= $grand - 0.005 );
				$order->update_meta_data( '_ulp_ds_credit_applied', wc_format_decimal( $credit_applied, 2 ) );
			}
		}
		// Per-partner override: credit applies, but release stays manual.
		$auto_release = $fully_covered && empty( $partner['hold_covered'] );

		$partner_label = isset( $partner['label'] ) ? $partner['label'] : $slug;
		if ( $auto_release ) {
			$order->set_status(
				'processing',
				sprintf( 'ULP Dropship: created from %s order #%s%s Fully covered by deposit credit ($%s applied, partner balance now $%s) — released automatically.',
					$partner_label,
					$origin_num,
					$shipping['note'] ? ' ' . $shipping['note'] : '',
					number_format( $credit_applied, 2 ),
					number_format( (float) $credit_after, 2 )
				)
			);
		} else {
			if ( $fully_covered ) {
				$credit_note = sprintf( ' Fully covered by deposit credit ($%s applied) — held for manual release per partner setting.', number_format( $credit_applied, 2 ) );
			} elseif ( $credit_applied > 0 ) {
				$credit_note = sprintf( ' Deposit credit applied: $%s — remaining due $%s.', number_format( $credit_applied, 2 ), number_format( (float) $order->get_total() - $credit_applied, 2 ) );
			} else {
				$credit_note = '';
			}
			$order->set_status(
				ULP_DS_STATUS,
				sprintf( 'ULP Dropship: created from %s order #%s%s.%s %s',
					$partner_label,
					$origin_num,
					$shipping['note'] ? ' ' . $shipping['note'] : '',
					$credit_note,
					$fully_covered
						? 'Flip to Processing to release to ShipStation.'
						: 'Awaiting partner payment — flip to Processing to release to ShipStation.'
				)
			);
		}
		$order->save();

		if ( ! empty( $mapped_used ) ) {
			$order->add_order_note( 'ULP Dropship: SKU mappings applied: ' . implode( '; ', $mapped_used ) );
		}
		if ( ! empty( $skipped_skus ) ) {
			$order->add_order_note( 'ULP Dropship: skipped non-ULP line items (SKUs): ' . implode( ', ', $skipped_skus ) );
		}

		self::notify_new_order( $slug, $partner, $order, $origin_num, $skipped_skus, $simulated, $credit_applied, $credit_after, $fully_covered, $auto_release );

		return array(
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'item_count'   => $item_count,
			'skipped_skus' => $skipped_skus,
		);
	}

	/**
	 * Read one value from the payload's meta_data by key. Key comparison
	 * ignores underscores/dashes and case, so "trufusion_shipping_method"
	 * and "tru_fusion_shipping_method" both match either configured form.
	 *
	 * @param array  $payload Decoded Woo order payload.
	 * @param string $key     Meta key to find.
	 * @return string Scalar value, or '' when absent/non-scalar.
	 */
	public static function payload_meta( array $payload, $key ) {
		$want = strtolower( str_replace( array( '_', '-' ), '', (string) $key ) );
		if ( '' === $want ) {
			return '';
		}
		foreach ( (array) ( isset( $payload['meta_data'] ) ? $payload['meta_data'] : array() ) as $meta ) {
			$have = isset( $meta['key'] ) ? strtolower( str_replace( array( '_', '-' ), '', (string) $meta['key'] ) ) : '';
			if ( $have === $want ) {
				return ( isset( $meta['value'] ) && is_scalar( $meta['value'] ) ) ? (string) $meta['value'] : '';
			}
		}
		return '';
	}

	/* =====================================================================
	 * Per-line pricing (B2BKing tier resolver — self-contained copy of the
	 * logic validated against production in the notifications plugin)
	 * ================================================================== */

	/** Effective unit price for a partner user buying $qty of a product. */
	public static function unit_price( $product_id, $user_id, $qty ) {
		// 1) Individual per-user override (B2BKing Premium; generic discovery).
		$individual = self::individual_pricing( $product_id, $user_id );
		if ( null !== $individual ) {
			$tiered = self::tier_price_for_qty( $individual['tiers'], $qty );
			if ( null !== $tiered ) {
				return $tiered;
			}
			if ( null !== $individual['sale'] ) {
				return $individual['sale'];
			}
			if ( null !== $individual['regular'] ) {
				return $individual['regular'];
			}
		}

		// 2) Group pricing.
		$suffix = self::group_suffix( $user_id );
		$tiers  = self::parse_tiers( get_post_meta( $product_id, 'b2bking_product_pricetiers_group_' . $suffix, true ) );
		$tiered = self::tier_price_for_qty( $tiers, $qty );
		if ( null !== $tiered ) {
			return $tiered;
		}

		$sale = self::num( get_post_meta( $product_id, 'b2bking_sale_product_price_group_' . $suffix, true ) );
		if ( null !== $sale ) {
			return $sale;
		}
		$regular = self::num( get_post_meta( $product_id, 'b2bking_regular_product_price_group_' . $suffix, true ) );
		if ( null !== $regular ) {
			return $regular;
		}

		// 3) Base product price.
		$base_sale = self::num( get_post_meta( $product_id, '_sale_price', true ) );
		if ( null !== $base_sale ) {
			return $base_sale;
		}
		$base = self::num( get_post_meta( $product_id, '_regular_price', true ) );
		return null !== $base ? $base : 0.0;
	}

	/** Highest tier whose min qty <= $qty, or null if no tier applies. */
	private static function tier_price_for_qty( array $tiers, $qty ) {
		$price = null;
		foreach ( $tiers as $tier ) { // sorted ascending by min
			if ( $qty >= $tier[0] ) {
				$price = $tier[1];
			}
		}
		return $price;
	}

	private static function group_suffix( $user_id ) {
		$group = get_user_meta( $user_id, 'b2bking_customergroup', true );
		return ( is_numeric( $group ) && (int) $group > 0 ) ? (string) (int) $group : 'b2c';
	}

	/** B2BKing Premium individual prices via generic meta discovery. */
	private static function individual_pricing( $product_id, $user_id ) {
		static $memo = array();
		$key = "{$product_id}:{$user_id}";
		if ( array_key_exists( $key, $memo ) ) {
			return $memo[ $key ];
		}

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->postmeta}
			 WHERE post_id = %d AND meta_key LIKE %s AND meta_key LIKE %s AND meta_key NOT LIKE %s",
			$product_id, '%b2bking%', '%' . $wpdb->esc_like( (string) $user_id ) . '%', '%_group_%'
		), ARRAY_A );

		$regular = null; $sale = null; $tiers = array();
		foreach ( (array) $rows as $row ) {
			$k = strtolower( $row['meta_key'] );
			if ( false !== strpos( $k, 'tier' ) ) {
				$parsed = self::parse_tiers( $row['meta_value'] );
				if ( $parsed ) { $tiers = $parsed; }
			} elseif ( false !== strpos( $k, 'sale' ) ) {
				$sale = self::num( $row['meta_value'] );
			} elseif ( false !== strpos( $k, 'regular' ) || false !== strpos( $k, 'price' ) ) {
				$n = self::num( $row['meta_value'] );
				if ( null !== $n && ( null === $regular || false !== strpos( $k, 'regular' ) ) ) {
					$regular = $n;
				}
			}
		}

		$memo[ $key ] = ( null === $regular && null === $sale && empty( $tiers ) )
			? null
			: array( 'regular' => $regular, 'sale' => $sale, 'tiers' => $tiers );
		return $memo[ $key ];
	}

	/** Parse "qty:price;qty:price;" defensively → [[qty,price],...] asc. */
	private static function parse_tiers( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$tiers = array();
		foreach ( (array) preg_split( '/[;,]+/', trim( $raw ) ) as $pair ) {
			if ( false === strpos( $pair, ':' ) ) { continue; }
			list( $q, $p ) = array_pad( explode( ':', trim( $pair ), 2 ), 2, '' );
			$p = self::num( $p );
			if ( is_numeric( trim( $q ) ) && null !== $p ) {
				$tiers[] = array( (float) trim( $q ), $p );
			}
		}
		usort( $tiers, static function ( $a, $b ) { return $a[0] <=> $b[0]; } );
		return $tiers;
	}

	private static function num( $v ) {
		$v = trim( (string) $v );
		return ( '' !== $v && is_numeric( $v ) ) ? (float) $v : null;
	}

	/* =====================================================================
	 * Private Label Fee (mirror of Code Snippets id 59 checkout logic)
	 *
	 * Checkout charges wholesale_customer users whose profile label
	 * (wwlc_cf_product_label) is "Private Label": $1.75 per unit on lines
	 * under 26 units, with an excluded-products list. That hook only runs on
	 * carts, so dropship orders must replicate it here.
	 *
	 * Per-partner modes: auto (default — follow the user profile, like
	 * checkout does) | always | never.
	 * ================================================================== */

	private static function maybe_add_label_fee( WC_Order $order, array $partner, $user_id ) {
		$mode = isset( $partner['label_fee'] ) ? $partner['label_fee'] : 'auto';

		if ( 'never' === $mode ) {
			return;
		}
		if ( 'auto' === $mode ) {
			$user = get_userdata( $user_id );
			if ( ! $user || ! in_array( 'wholesale_customer', (array) $user->roles, true ) ) {
				return;
			}
			if ( 'Private Label' !== get_user_meta( $user_id, 'wwlc_cf_product_label', true ) ) {
				return;
			}
		}

		// Keep these in sync with the checkout snippet (filterable for drift).
		$rate     = (float) apply_filters( 'ulp_ds_label_fee_rate', 1.75 );
		$min_free = (int) apply_filters( 'ulp_ds_label_fee_free_qty', 26 );
		$excluded = (array) apply_filters( 'ulp_ds_label_fee_excluded', array(
			4879, 4886, 4905, 4883, 4906, 4873, 4907, 4908, 4909, 4910,
			2308, 4912, 4913, 4915, 4916, 4029, 1973, 2559,
		) );

		$units = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$pid       = (int) $item->get_product_id();
			$variation = (int) $item->get_variation_id();
			$qty       = (int) $item->get_quantity();

			if ( in_array( $pid, $excluded, true ) || ( $variation && in_array( $variation, $excluded, true ) ) ) {
				continue;
			}
			if ( $qty >= $min_free ) {
				continue; // 26+ of a single line = no fee on that line.
			}
			$units += $qty;
		}

		if ( $units <= 0 ) {
			return;
		}

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Private Label Fee' );
		// amount AND total: set_total() alone leaves _fee_amount empty,
		// which the QuickBooks (OneSaas) connector reads as $0.
		$fee->set_amount( round( $rate * $units, 2 ) );
		$fee->set_total( round( $rate * $units, 2 ) );
		$fee->set_tax_status( 'none' ); // Checkout adds it non-taxable.
		$order->add_item( $fee );

		$order->add_order_note( sprintf(
			'ULP Dropship: Private Label Fee applied — %d unit(s) × $%s (mode: %s).',
			$units, number_format( $rate, 2 ), $mode
		) );
	}

	/* =====================================================================
	 * Shipping: our real zone rate for the destination
	 * ================================================================== */

	/**
	 * Find the partner's configured method (default "2-Day Shipping") in the
	 * shipping zone matching the order's destination and take its cost.
	 * Falls back to the partner's flat fallback amount with a clear note.
	 *
	 * @return array { title, method_id, cost, note }
	 */
	private static function resolve_shipping( WC_Order $order, array $partner ) {
		$wanted   = ! empty( $partner['ship_method'] ) ? $partner['ship_method'] : '2-Day Shipping';
		$fallback = isset( $partner['ship_fallback'] ) ? (float) $partner['ship_fallback'] : 15.0;

		try {
			$package = array(
				'contents'        => array(),
				'contents_cost'   => (float) $order->get_subtotal(),
				'applied_coupons' => array(),
				'user'            => array( 'ID' => $order->get_customer_id() ),
				'destination'     => array(
					'country'  => $order->get_shipping_country(),
					'state'    => $order->get_shipping_state(),
					'postcode' => $order->get_shipping_postcode(),
					'city'     => $order->get_shipping_city(),
					'address'  => $order->get_shipping_address_1(),
				),
			);

			$zone = WC_Shipping_Zones::get_zone_matching_package( $package );

			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				$title = method_exists( $method, 'get_title' ) ? $method->get_title() : '';
				if ( 0 !== strcasecmp( trim( $title ), trim( $wanted ) ) ) {
					continue;
				}
				// Flat-rate style methods keep cost in instance settings; only
				// trust a plain numeric cost (skip formula costs like "10*[qty]").
				$cost = isset( $method->instance_settings['cost'] ) ? trim( (string) $method->instance_settings['cost'] ) : '';
				if ( is_numeric( $cost ) ) {
					return array(
						'title'     => $title,
						'method_id' => $method->id,
						'cost'      => (float) $cost,
						'note'      => sprintf( 'Shipping "%s" from zone "%s".', $title, $zone->get_zone_name() ),
					);
				}
			}
		} catch ( \Throwable $e ) {
			// fall through to fallback
		}

		return array(
			'title'     => $wanted,
			'method_id' => 'flat_rate',
			'cost'      => $fallback,
			'note'      => sprintf( 'Shipping method "%s" not auto-resolved for this destination — used fallback $%s. ADJUST BEFORE RELEASING if needed.', $wanted, number_format( $fallback, 2 ) ),
		);
	}

	/* =====================================================================
	 * Notifications
	 * ================================================================== */

	private static function notify_new_order( $slug, $partner, WC_Order $order, $origin_num, array $skipped, $simulated, $credit_applied = 0.0, $credit_after = null, $fully_covered = false, $auto_release = false ) {
		$label = isset( $partner['label'] ) ? $partner['label'] : $slug;
		$total = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
		$edit  = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
		$due   = max( 0, (float) $order->get_total() - (float) $credit_applied );

		if ( $auto_release ) {
			$status_line = $total . ' paid by deposit — RELEASED';
		} elseif ( $fully_covered ) {
			$status_line = $total . ' paid by deposit — awaiting release';
		} else {
			$status_line = $total . ' awaiting payment';
		}
		$subject = sprintf( '%s[Dropship] %s order #%s → our #%s — %s',
			$simulated ? '[TEST] ' : '', $label, $origin_num, $order->get_order_number(), $status_line );

		if ( $auto_release ) {
			$body = "A dropship order was created, fully covered by the partner's deposit credit, and released to fulfillment automatically.\n\n";
		} elseif ( $fully_covered ) {
			$body = "A dropship order was created and is fully covered by the partner's deposit credit. It is HELD per this partner's settings — flip to Processing to release.\n\n";
		} else {
			$body = "A dropship order was created and is awaiting payment.\n\n";
		}
		$body .= "Partner: {$label}\nPartner order: #{$origin_num}\nOur order: #{$order->get_order_number()}\nWholesale total: {$total}\n";
		if ( $credit_applied > 0 ) {
			$body .= sprintf( "Deposit credit applied: $%s\n", number_format( $credit_applied, 2 ) );
			if ( ! $fully_covered ) {
				$body .= sprintf( "Remaining due: $%s\n", number_format( $due, 2 ) );
			}
			$body .= sprintf( "Partner credit balance now: $%s\n", number_format( (float) $credit_after, 2 ) );
		}
		$body .= 'Ship to: ' . preg_replace( '/\s+/', ' ', wp_strip_all_tags( $order->get_formatted_shipping_address() ) ) . "\n";
		if ( $skipped ) {
			$body .= 'Skipped non-ULP SKUs: ' . implode( ', ', $skipped ) . "\n";
		}
		if ( $auto_release ) {
			$body .= "\nNo action needed — review the order here:\n{$edit}\n";
		} elseif ( $fully_covered ) {
			$body .= "\nRelease when ready (no payment outstanding):\n{$edit}\n";
		} else {
			$body .= "\nReview & release (set to Processing once paid):\n{$edit}\n";
		}

		$admin_email = get_option( 'admin_email' );
		wp_mail( $admin_email, $subject, $body );

		// Tell the partner what (if anything) is due — account email, or their
		// configured notification recipients if set.
		if ( ! empty( $partner['notify_partner'] ) && ! $simulated ) {
			$recipients = ulp_ds_partner_recipients( $partner );
			if ( empty( $recipients ) ) {
				return;
			}

			if ( $fully_covered ) {
				wp_mail(
					$recipients,
					sprintf( 'Your dropship order #%s is covered by your deposit — no payment needed', $origin_num ),
					"We received your store's order #{$origin_num}.\n\n"
					. "Wholesale total: {$total}\n"
					. sprintf( "Covered by your deposit credit: $%s\n", number_format( $credit_applied, 2 ) )
					. sprintf( "Your remaining deposit balance: $%s\n", number_format( (float) $credit_after, 2 ) )
					. "\nNo payment needed — the order will ship to your customer shortly.\n\n— Ultra Life Peptides"
				);
			} elseif ( $credit_applied > 0 ) {
				wp_mail(
					$recipients,
					sprintf( 'Your dropship order #%s has been received — $%s due after deposit credit', $origin_num, number_format( $due, 2 ) ),
					"We received your store's order #{$origin_num}.\n\n"
					. "Wholesale total: {$total}\n"
					. sprintf( "Deposit credit applied: $%s\n", number_format( $credit_applied, 2 ) )
					. sprintf( "Remaining due: $%s\n", number_format( $due, 2 ) )
					. "\nPlease send the remaining amount via Zelle as usual. The order ships to your customer as soon as payment is confirmed.\n\n— Ultra Life Peptides"
				);
			} else {
				wp_mail(
					$recipients,
					sprintf( 'Your dropship order #%s has been received — %s due', $origin_num, $total ),
					"We received your store's order #{$origin_num}.\n\nWholesale total due: {$total}\nPlease send payment via Zelle as usual. The order ships to your customer as soon as payment is confirmed.\n\n— Ultra Life Peptides"
				);
			}
		}
	}

	public static function notify_admin_error( $slug, $partner, $origin_num, $message ) {
		$label = isset( $partner['label'] ) ? $partner['label'] : $slug;
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[Dropship ERROR] %s order #%s could not be created', $label, $origin_num ),
			"Partner: {$label}\nPartner order: #{$origin_num}\nError: {$message}\n\nSee WooCommerce → Dropship Connect for the log."
		);
	}
}
