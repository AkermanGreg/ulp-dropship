<?php
/**
 * Webhook receiver for partner stores.
 *
 * Endpoint:  POST /wp-json/ulp/v1/dropship/webhook/{partner-slug}
 *
 * Speaks native WooCommerce webhook format: the partner configures a standard
 * "Order updated" webhook pointing here, with the shared secret we issue.
 * WooCommerce signs every delivery with HMAC-SHA256 over the raw body
 * (X-WC-Webhook-Signature, base64) — we verify before touching anything.
 *
 * Processing rules:
 *  - Woo's initial "ping" delivery (webhook_id only) is acknowledged with 200.
 *  - Only orders in a PAID state on the partner store (processing/completed)
 *    are considered; other updates are acknowledged and ignored.
 *  - Idempotent: one partner order can only ever create one local order
 *    (meta _ulp_ds_origin_key = "{slug}:{origin_id}").
 *  - Only line items whose SKU matches one of OUR products are forwarded.
 *
 * @package ULP_Dropship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULP_DS_Receiver {

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route() {
		register_rest_route( 'ulp/v1', '/dropship/webhook/(?P<partner>[a-z0-9\-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle' ),
			// Auth happens inside via HMAC signature — Woo webhooks cannot
			// send Authorization headers, only the signature.
			'permission_callback' => '__return_true',
		) );
	}

	public function handle( WP_REST_Request $request ) {
		$slug    = sanitize_key( $request['partner'] );
		$partner = ulp_ds_partner( $slug );

		if ( ! $partner || empty( $partner['enabled'] ) ) {
			return new WP_REST_Response( array( 'error' => 'unknown or disabled partner' ), 404 );
		}

		$body = $request->get_body();

		// ── Woo's setup "ping" comes FIRST and is UNSIGNED ───────────────────
		// WooCommerce delivers it form-encoded ("webhook_id=123") with no
		// X-WC-Webhook-Signature header, so it must be acknowledged before
		// signature verification or saving the webhook shows an error.
		// A ping carries no order data, so answering it unsigned is harmless.
		$ping_id = 0;
		if ( preg_match( '/^webhook_id=(\d+)$/', trim( $body ), $m ) ) {
			$ping_id = (int) $m[1];
		} else {
			$maybe = json_decode( $body, true );
			if ( is_array( $maybe ) && isset( $maybe['webhook_id'] ) && ! isset( $maybe['id'] ) ) {
				$ping_id = (int) $maybe['webhook_id'];
			}
		}
		if ( $ping_id ) {
			ulp_ds_log( sprintf( '[%s] Webhook ping received (webhook id %d) — connection OK.', $slug, $ping_id ) );
			return new WP_REST_Response( array( 'ok' => 'pong' ), 200 );
		}

		// ── Payload inspector: capture the next (non-ping) delivery ──────────
		// Armed from the admin page; stores ONE raw body for inspection, then
		// disarms itself. Runs before signature verification on purpose, so a
		// delivery that would be rejected can still be examined.
		if ( get_option( 'ulp_ds_capture_armed' ) === $slug ) {
			update_option( 'ulp_ds_capture_data', array(
				'slug'   => $slug,
				'time'   => current_time( 'mysql' ),
				'topic'  => (string) $request->get_header( 'x-wc-webhook-topic' ),
				'source' => (string) $request->get_header( 'x-wc-webhook-source' ),
				'bytes'  => strlen( $body ),
				'body'   => substr( $body, 0, 200000 ),
			), false );
			delete_option( 'ulp_ds_capture_armed' );
			ulp_ds_log( sprintf( '[%s] Webhook payload captured (%d bytes) — view it in the Payload inspector on this page.', $slug, strlen( $body ) ) );
		}

		// ── Verify the WooCommerce webhook signature ─────────────────────────
		$signature = $request->get_header( 'x-wc-webhook-signature' );
		$expected  = base64_encode( hash_hmac( 'sha256', $body, $partner['secret'], true ) );

		if ( empty( $signature ) || ! hash_equals( $expected, $signature ) ) {
			ulp_ds_log( sprintf(
				'[%s] Rejected webhook: bad signature (%s, body %d bytes, topic: %s). Check the Secret matches on the partner\'s webhook.',
				$slug,
				empty( $signature ) ? 'no signature header' : 'signature mismatch',
				strlen( $body ),
				$request->get_header( 'x-wc-webhook-topic' ) ?: '?'
			), 'error' );
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) || empty( $payload['id'] ) ) {
			// Include a short body snippet so we can see WHAT arrived — e.g. Woo
			// delivers its REST error object ("Invalid ID.") when the origin
			// order was trashed/deleted before the queued webhook fired.
			$snippet = preg_replace( '/[^\x20-\x7E]/', '', substr( $body, 0, 120 ) );
			ulp_ds_log( sprintf(
				'[%s] Webhook arrived (signature OK) but payload was not a recognizable order (%d bytes). Body starts: %s',
				$slug, strlen( $body ), $snippet
			), 'error' );
			return new WP_REST_Response( array( 'error' => 'unrecognized payload' ), 400 );
		}

		return rest_ensure_response( $this->process_order_payload( $slug, $partner, $payload, false ) );
	}

	/**
	 * Shared by the live webhook and the admin "simulate" tool.
	 *
	 * @param string $slug
	 * @param array  $partner
	 * @param array  $payload   Decoded Woo order JSON.
	 * @param bool   $simulated
	 * @return array Result summary (also returned to the webhook caller).
	 */
	public function process_order_payload( $slug, $partner, array $payload, $simulated ) {
		$origin_id  = (string) $payload['id'];
		$origin_num = isset( $payload['number'] ) ? (string) $payload['number'] : $origin_id;
		$status     = isset( $payload['status'] ) ? (string) $payload['status'] : '';
		$tag        = $simulated ? ' (SIMULATED)' : '';

		// Only act when the partner-side order is paid.
		$paid_statuses = apply_filters( 'ulp_ds_paid_statuses', array( 'processing', 'completed' ) );
		if ( ! in_array( $status, $paid_statuses, true ) ) {
			ulp_ds_log( sprintf(
				'[%s] Order #%s%s ignored — status "%s" is not a paid status. It will sync when it reaches processing/completed on the partner site.',
				$slug, $origin_num, $tag, $status ?: 'unknown'
			) );
			return array( 'ok' => 'ignored', 'reason' => "status '{$status}' not a paid status" );
		}

		// Idempotency: never create twice for the same origin order.
		$origin_key = $slug . ':' . $origin_id;
		$existing   = wc_get_orders( array(
			'limit'      => 1,
			'meta_key'   => '_ulp_ds_origin_key',
			'meta_value' => $origin_key,
			'return'     => 'ids',
		) );
		if ( ! empty( $existing ) ) {
			ulp_ds_log( sprintf(
				'[%s] Order #%s%s update received — already imported as local order #%d, no action.',
				$slug, $origin_num, $tag, (int) $existing[0]
			) );
			return array( 'ok' => 'duplicate', 'local_order' => (int) $existing[0] );
		}

		$result = ULP_DS_Builder::create_order( $slug, $partner, $payload, $simulated );

		if ( is_wp_error( $result ) ) {
			// "No matching SKUs" can be routine for partners who also sell
			// products we don't carry — per-partner toggle silences the alert
			// EMAIL for that case only. The wp-admin log entry always remains.
			$is_nomatch = ( 'ulp_ds_no_items' === $result->get_error_code() );
			$silenced   = $is_nomatch && ! empty( $partner['silence_nomatch'] );

			ulp_ds_log( sprintf(
				'[%s] Order #%s FAILED%s: %s%s',
				$slug, $origin_num, $tag, $result->get_error_message(),
				$silenced ? ' (alert email silenced for this partner)' : ''
			), 'error' );

			// Failed imports create no order to hang the payload on — keep the
			// last 5 failed payloads in a bounded buffer for inspection.
			$failed   = get_option( 'ulp_ds_failed_payloads', array() );
			$failed[] = array(
				'time'   => current_time( 'mysql' ),
				'slug'   => $slug,
				'origin' => $origin_num,
				'error'  => $result->get_error_message(),
				'body'   => substr( (string) wp_json_encode( $payload ), 0, 100000 ),
			);
			update_option( 'ulp_ds_failed_payloads', array_slice( $failed, -5 ), false );

			if ( ! $silenced ) {
				ULP_DS_Builder::notify_admin_error( $slug, $partner, $origin_num, $result->get_error_message() );
			}
			return array( 'error' => $result->get_error_message() );
		}

		$skipped_note = ! empty( $result['skipped_skus'] )
			? ' Skipped non-matching SKUs: ' . implode( ', ', $result['skipped_skus'] ) . '.'
			: '';
		ulp_ds_log( sprintf(
			'[%s] Partner order #%s%s → created local order #%s (%s items, awaiting payment).%s',
			$slug, $origin_num, $tag, $result['order_number'], $result['item_count'], $skipped_note
		) );

		return array(
			'ok'          => 'created',
			'local_order' => $result['order_id'],
			'skipped_skus' => $result['skipped_skus'],
		);
	}
}
