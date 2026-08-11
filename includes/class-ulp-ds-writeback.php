<?php
/**
 * Phase 2 write-back: push shipping details into the partner's WooCommerce.
 *
 * When ShipStation marks a dropship order shipped, and the order's partner
 * has write-back configured (store URL + REST API key/secret), we make two
 * calls to THEIR store against the origin order:
 *
 *   1. POST /wp-json/wc/v3/orders/{id}/notes   — PRIVATE note with carrier,
 *      tracking number and link (customer_note: false → no email, admin-only).
 *   2. PUT  /wp-json/wc/v3/orders/{id}         — status "completed" + tracking
 *      stored as order meta (_ulp_tracking_number / _ulp_tracking_provider /
 *      _ulp_tracking_url) for their Completed-email template to render.
 *
 * The status change fires the partner store's own branded "Order complete"
 * email to their customer. Auth is Woo REST basic auth over HTTPS.
 *
 * Failures retry via WP-Cron (5-minute intervals, 3 attempts) and end in an
 * admin alert — the partner tracking EMAIL (shipnotify) is sent regardless,
 * so a partner always learns the tracking even if their site is down.
 *
 * @package ULP_Dropship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULP_DS_Writeback {

	const RETRY_HOOK   = 'ulp_ds_writeback_retry';
	const MAX_ATTEMPTS = 3;

	public function init() {
		// After the shipnotify email hook (priority 10) on the same event.
		add_action( 'woocommerce_shipstation_shipnotify', array( $this, 'on_shipnotify' ), 20, 2 );
		add_action( self::RETRY_HOOK, array( $this, 'retry' ) );
	}

	/** True when the partner has a usable write-back configuration. */
	public static function enabled_for( $partner ) {
		return ! empty( $partner['wb_enabled'] )
			&& ! empty( $partner['wb_url'] )
			&& ! empty( $partner['wb_key'] )
			&& ! empty( $partner['wb_secret'] );
	}

	public function on_shipnotify( $order, $args ) {
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order instanceof WC_Order || ! $order->get_meta( '_ulp_ds_partner' ) ) {
			return;
		}
		if ( $order->get_meta( '_ulp_ds_simulated' ) ) {
			return;
		}

		// Persist shipment details so cron retries don't depend on hook args.
		$tracking = isset( $args['tracking_number'] ) ? (string) $args['tracking_number'] : '';
		$carrier  = isset( $args['carrier'] ) ? (string) $args['carrier'] : '';
		if ( '' !== $tracking ) {
			$order->update_meta_data( '_ulp_ds_tracking_number', $tracking );
			$order->update_meta_data( '_ulp_ds_tracking_carrier', $carrier );
			$order->save();
		}

		$this->push( $order->get_id() );
	}

	public function retry( $order_id ) {
		$this->push( (int) $order_id );
	}

	private function push( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_ulp_ds_writeback_done' ) ) {
			return;
		}

		$slug    = (string) $order->get_meta( '_ulp_ds_partner' );
		$partner = ulp_ds_partner( $slug );
		if ( ! $partner || ! self::enabled_for( $partner ) ) {
			return; // Partner without write-back — email-only flow, not an error.
		}

		$tracking = (string) $order->get_meta( '_ulp_ds_tracking_number' );
		$carrier  = (string) $order->get_meta( '_ulp_ds_tracking_carrier' );
		if ( '' === $tracking ) {
			return;
		}

		// Their order ID is the second half of the idempotency key "slug:id"
		// (numeric REST id — the displayed order number may differ).
		$origin_id = (int) substr( (string) $order->get_meta( '_ulp_ds_origin_key' ), strlen( $slug ) + 1 );
		if ( $origin_id <= 0 ) {
			ulp_ds_log( sprintf( '[%s] Write-back skipped for order #%s — no origin order id.', $slug, $order->get_order_number() ), 'error' );
			return;
		}

		$base = untrailingslashit( esc_url_raw( $partner['wb_url'] ) );
		$url  = self::tracking_url( $carrier, $tracking );
		$auth = array(
			'Authorization' => 'Basic ' . base64_encode( $partner['wb_key'] . ':' . $partner['wb_secret'] ),
			'Content-Type'  => 'application/json',
		);

		// ── Call 1: private note (their team's record; sends NO email) ───────
		$note_resp = wp_remote_post( "{$base}/wp-json/wc/v3/orders/{$origin_id}/notes", array(
			'timeout' => 20,
			'headers' => $auth,
			'body'    => wp_json_encode( array(
				'note'          => sprintf( 'Shipped by Ultra Life Peptides via %s. Tracking: %s — %s', $carrier ?: 'carrier', $tracking, $url ),
				'customer_note' => false,
			) ),
		) );

		// ── Call 2: completed + tracking meta (fires THEIR customer email) ───
		$status_resp = wp_remote_request( "{$base}/wp-json/wc/v3/orders/{$origin_id}", array(
			'method'  => 'PUT',
			'timeout' => 20,
			'headers' => $auth,
			'body'    => wp_json_encode( array(
				'status'    => 'completed',
				'meta_data' => array(
					array( 'key' => '_ulp_tracking_number',   'value' => $tracking ),
					array( 'key' => '_ulp_tracking_provider', 'value' => $carrier ),
					array( 'key' => '_ulp_tracking_url',      'value' => $url ),
				),
			) ),
		) );

		$note_ok   = ! is_wp_error( $note_resp ) && wp_remote_retrieve_response_code( $note_resp ) < 300;
		$status_ok = ! is_wp_error( $status_resp ) && wp_remote_retrieve_response_code( $status_resp ) < 300;

		if ( $note_ok && $status_ok ) {
			$order->update_meta_data( '_ulp_ds_writeback_done', current_time( 'mysql' ) );
			$order->save();
			$order->add_order_note( sprintf(
				'ULP Dropship: pushed tracking %s (%s) to partner store — their order #%d noted and marked Completed.',
				$tracking, $carrier ?: 'carrier', $origin_id
			) );
			ulp_ds_log( sprintf( '[%s] Write-back OK — their order #%d marked Completed with tracking %s.', $slug, $origin_id, $tracking ) );
			return;
		}

		// ── Failure: log detail, retry up to MAX_ATTEMPTS, then alert admin ──
		$detail = sprintf(
			'note: %s / status: %s',
			is_wp_error( $note_resp ) ? $note_resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $note_resp ),
			is_wp_error( $status_resp ) ? $status_resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $status_resp )
		);

		$attempt = (int) $order->get_meta( '_ulp_ds_writeback_attempts' ) + 1;
		$order->update_meta_data( '_ulp_ds_writeback_attempts', $attempt );
		$order->save();

		if ( $attempt < self::MAX_ATTEMPTS ) {
			wp_schedule_single_event( time() + 300, self::RETRY_HOOK, array( $order->get_id() ) );
			ulp_ds_log( sprintf( '[%s] Write-back attempt %d failed for their order #%d (%s) — retrying in 5 min.', $slug, $attempt, $origin_id, $detail ), 'error' );
			return;
		}

		ulp_ds_log( sprintf( '[%s] Write-back FAILED after %d attempts for their order #%d (%s).', $slug, $attempt, $origin_id, $detail ), 'error' );
		$order->add_order_note( sprintf( 'ULP Dropship: write-back to partner store FAILED after %d attempts (%s). Update their order #%d manually.', $attempt, $detail, $origin_id ) );
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[Dropship] Write-back failed — %s order #%d needs manual tracking update', $slug, $origin_id ),
			sprintf(
				"Pushing tracking to the partner store failed after %d attempts.\n\nPartner: %s\nTheir order: #%d\nOur order: #%s\nTracking: %s (%s)\nLast error: %s\n\nAdd the tracking to their order manually, or re-save our order's shipment to retry.",
				$attempt, $slug, $origin_id, $order->get_order_number(), $tracking, $carrier, $detail
			)
		);
	}

	/** Public tracking link for the common carriers; blank-safe generic fallback. */
	public static function tracking_url( $carrier, $tracking ) {
		$c = strtolower( (string) $carrier );
		if ( false !== strpos( $c, 'ups' ) ) {
			return 'https://www.ups.com/track?tracknum=' . rawurlencode( $tracking );
		}
		if ( false !== strpos( $c, 'usps' ) || false !== strpos( $c, 'stamps' ) ) {
			return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . rawurlencode( $tracking );
		}
		if ( false !== strpos( $c, 'fedex' ) ) {
			return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode( $tracking );
		}
		if ( false !== strpos( $c, 'dhl' ) ) {
			return 'https://www.dhl.com/us-en/home/tracking.html?tracking-id=' . rawurlencode( $tracking );
		}
		return 'https://www.google.com/search?q=' . rawurlencode( trim( $carrier . ' tracking ' . $tracking ) );
	}
}
