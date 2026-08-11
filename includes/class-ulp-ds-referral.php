<?php
/**
 * AffiliateWP attribution for dropship orders.
 *
 * WHY THIS EXISTS: AffiliateWP's WooCommerce integration (and Lifetime
 * Commissions on top of it) creates referrals during the CHECKOUT flow.
 * API-created dropship orders never pass through checkout, so partner orders
 * were getting no attribution (verified: real Azoth orders carry referrals,
 * dropship order #5822 did not).
 *
 * WHAT IT DOES: when a dropship order is RELEASED (any transition into
 * processing/completed for an order with dropship meta), it looks up the
 * partner's Lifetime Commissions linked affiliate and creates the referral
 * directly — the same approach the site's coupon-attribution snippet uses.
 * The referral amount goes through affwp_calc_referral_amount(), so the
 * site's tiered wholesale commission logic applies (wholesale role + order
 * quantity are read from the order itself).
 *
 * Partners with NO linked affiliate (e.g. TruFusion) simply generate no
 * referral — logged, not an error.
 *
 * @package ULP_Dropship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULP_DS_Referral {

	public function init() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_create_referral' ), 20, 4 );
	}

	/**
	 * @param int      $order_id
	 * @param string   $old_status
	 * @param string   $new_status
	 * @param WC_Order $order
	 */
	public function maybe_create_referral( $order_id, $old_status, $new_status, $order ) {
		if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
		}

		$slug = $order->get_meta( '_ulp_ds_partner' );
		if ( ! $slug ) {
			return; // Not a dropship order.
		}
		if ( $order->get_meta( '_ulp_ds_referral_done' ) ) {
			return; // Already handled (e.g. processing → completed later).
		}

		if ( ! function_exists( 'affiliate_wp' ) || ! function_exists( 'affwp_add_referral' ) ) {
			return;
		}

		// Mark handled up-front so a failure path can't loop on later saves;
		// the outcome is recorded in the order notes either way.
		$order->update_meta_data( '_ulp_ds_referral_done', '1' );
		$order->save();

		// Never double-attribute: bail if any referral already references this order.
		$existing = affiliate_wp()->referrals->get_by( 'reference', $order_id, 'woocommerce' );
		if ( $existing ) {
			return;
		}

		// ── Resolve the partner's Lifetime Commissions linked affiliate ──────
		$affiliate_id = 0;
		$user         = get_userdata( (int) $order->get_customer_id() );

		if ( $user && function_exists( 'affiliate_wp_lifetime_commissions' ) ) {
			$integrations = affiliate_wp_lifetime_commissions()->integrations;
			if ( $integrations && method_exists( $integrations, 'get_affiliate_id_from_customer_email' ) ) {
				$affiliate_id = (int) $integrations->get_affiliate_id_from_customer_email( $user->user_email );
			}
		}

		if ( ! $affiliate_id ) {
			ulp_ds_log( sprintf( '[%s] Order #%s released — no lifetime-linked affiliate, no referral created.', $slug, $order->get_order_number() ) );
			return;
		}

		if ( ! affwp_is_active_affiliate( $affiliate_id ) ) {
			ulp_ds_log( sprintf( '[%s] Order #%s — linked affiliate #%d is not active; no referral created.', $slug, $order->get_order_number(), $affiliate_id ), 'error' );
			$order->add_order_note( sprintf( 'ULP Dropship: linked affiliate #%d is not active — referral NOT created.', $affiliate_id ) );
			return;
		}

		// ── Amount: same base as the site's commission engine ────────────────
		// Handling Fee and Private Label Fee are charged to the partner but are
		// NOT commissionable — subtract matching fee lines from the base.
		$excluded_fee_total = 0.0;
		$excluded_fee_names = (array) apply_filters( 'ulp_ds_commission_excluded_fees', array( 'handling fee', 'private label fee' ) );
		foreach ( $order->get_fees() as $fee_item ) {
			$fee_name = strtolower( trim( (string) $fee_item->get_name() ) );
			foreach ( $excluded_fee_names as $needle ) {
				if ( '' !== $needle && false !== strpos( $fee_name, strtolower( $needle ) ) ) {
					$excluded_fee_total += (float) $fee_item->get_total();
					break;
				}
			}
		}

		$base_amount = (float) $order->get_total() - (float) $order->get_total_tax() - (float) $order->get_shipping_total() - $excluded_fee_total;
		if ( $base_amount < 0 ) {
			$base_amount = max( 0, (float) $order->get_total() - $excluded_fee_total );
		}

		// Runs through the site's tiered wholesale commission filter — the
		// partner's wholesale role and total order quantity are read from
		// this order, so the correct tier rate applies automatically.
		$amount = affwp_calc_referral_amount( $base_amount, $affiliate_id, $order_id, '', 0 );

		$description = array();
		foreach ( $order->get_items() as $item ) {
			$description[] = $item->get_name();
		}

		$referral_id = affwp_add_referral( array(
			'affiliate_id' => $affiliate_id,
			'amount'       => $amount,
			'reference'    => $order_id,
			'description'  => implode( ', ', $description ),
			'context'      => 'woocommerce',
			'status'       => 'unpaid',
			'custom'       => array( 'lifetime_referral', 'ulp_dropship' ),
		) );

		if ( $referral_id ) {
			$name = function_exists( 'affwp_get_affiliate_name' ) ? affwp_get_affiliate_name( $affiliate_id ) : '';
			$order->add_order_note( sprintf(
				'ULP Dropship: referral #%d created for affiliate %s (#%d) — %s.',
				$referral_id, $name ?: '?', $affiliate_id,
				html_entity_decode( wp_strip_all_tags( affwp_currency_filter( $amount ) ), ENT_QUOTES, 'UTF-8' )
			) );
			ulp_ds_log( sprintf( '[%s] Order #%s released — referral #%d created for affiliate #%d ($%s).',
				$slug, $order->get_order_number(), $referral_id, $affiliate_id, number_format( (float) $amount, 2 ) ) );
		} else {
			ulp_ds_log( sprintf( '[%s] Order #%s — affwp_add_referral FAILED.', $slug, $order->get_order_number() ), 'error' );
			$order->add_order_note( 'ULP Dropship: referral creation FAILED — check AffiliateWP.' );
		}
	}
}
