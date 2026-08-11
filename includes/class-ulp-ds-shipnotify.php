<?php
/**
 * Tracking-back: when ShipStation marks a dropship order shipped, email the
 * partner the tracking number (pilot). Phase 2 replaces the email with a REST
 * push into the partner's own WooCommerce order.
 *
 * The ShipStation integration fires `woocommerce_shipstation_shipnotify`
 * with the order and shipment args (tracking_number, carrier, ship_date).
 *
 * @package ULP_Dropship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULP_DS_Shipnotify {

	public function init() {
		add_action( 'woocommerce_shipstation_shipnotify', array( $this, 'on_shipnotify' ), 10, 2 );

		// ── ShipStation Custom Field 3 = the partner's Private Label Name ────
		// The integration exports the order meta named by the first filter as
		// CustomField3. We chain defensively: only claim the slot if nothing
		// else has, and only override the value for orders that carry our meta
		// — non-dropship orders are untouched.
		// ── ShipStation Custom Field 2 = the PARTNER's order number ──────────
		// Same defensive chaining as CF3: claim the slot only if unused, and
		// only override values for orders carrying our meta.
		add_filter( 'woocommerce_shipstation_export_custom_field_2', function ( $meta_key ) {
			return empty( $meta_key ) ? '_ulp_ds_origin_order' : $meta_key;
		}, 20 );

		add_filter( 'woocommerce_shipstation_export_custom_field_2_value', function ( $value, $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$origin = $order->get_meta( '_ulp_ds_origin_order' );
				if ( '' !== (string) $origin ) {
					$label = $order->get_meta( '_ulp_ds_partner' );
					return trim( ( $label ? $label . ' ' : '' ) . '#' . $origin );
				}
			}
			return $value;
		}, 20, 2 );

		add_filter( 'woocommerce_shipstation_export_custom_field_3', function ( $meta_key ) {
			return empty( $meta_key ) ? '_ulp_ds_private_label' : $meta_key;
		}, 20 );

		add_filter( 'woocommerce_shipstation_export_custom_field_3_value', function ( $value, $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$label = $order->get_meta( '_ulp_ds_private_label' );
				if ( '' !== (string) $label ) {
					return $label;
				}
			}
			return $value;
		}, 20, 2 );
	}

	/**
	 * @param WC_Order|int $order Order object (or id, defensively).
	 * @param array        $args  { tracking_number, carrier, ship_date, ... }
	 */
	public function on_shipnotify( $order, $args ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$slug = $order->get_meta( '_ulp_ds_partner' );
		if ( ! $slug ) {
			return; // Not a dropship order.
		}

		$partner = ulp_ds_partner( $slug );
		if ( ! $partner ) {
			return;
		}

		$tracking = isset( $args['tracking_number'] ) ? $args['tracking_number'] : '';
		$carrier  = isset( $args['carrier'] ) ? $args['carrier'] : '';
		$origin   = $order->get_meta( '_ulp_ds_origin_order' );
		$label    = isset( $partner['label'] ) ? $partner['label'] : $slug;

		ulp_ds_log( sprintf( '[%s] Order #%s shipped — %s %s (their order #%s).',
			$slug, $order->get_order_number(), $carrier, $tracking, $origin ) );

		$recipients = ulp_ds_partner_recipients( $partner );
		if ( empty( $recipients ) ) {
			return;
		}

		$closing = ( class_exists( 'ULP_DS_Writeback' ) && ULP_DS_Writeback::enabled_for( $partner ) )
			? "Your store has been updated automatically — the order is marked Completed with this tracking attached, and your customer receives your store's confirmation email."
			: "Please add this tracking number to your customer's order.";

		wp_mail(
			$recipients,
			sprintf( 'Shipped: your order #%s — tracking %s', $origin, $tracking ),
			"Your dropship order has shipped to your customer.\n\n"
			. "Your order: #{$origin}\n"
			. "Carrier: {$carrier}\n"
			. "Tracking: {$tracking}\n\n"
			. $closing . "\n\n— Ultra Life Peptides"
		);
	}
}
