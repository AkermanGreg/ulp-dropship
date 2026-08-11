<?php
/**
 * Zelle payment auto-matching for held dropship orders.
 *
 * The site's Zelle plugin (wc-zelle-pro + emailreceipts.io) receives every
 * Zelle receipt at POST /wc-zelle/v1/update-zelle-order and auto-releases
 * RETAIL orders (payment method zelle, status on-hold). Dropship orders are
 * invisible to it: no payment method, custom held status.
 *
 * This listener runs AFTER that handler on the same request and matches the
 * payment against held dropship orders BY EXACT AMOUNT (partners don't put
 * our order numbers in their Zelle memos):
 *
 *   - Only runs when the Zelle plugin verified the sender signature AND did
 *     not itself apply the payment to a retail order.
 *   - A candidate order's REMAINING DUE (total minus applied deposit credit)
 *     must equal the paid amount to the cent.
 *   - If the partner has "Zelle sender name(s)" configured, the receipt's
 *     sender must match it — prevents cross-partner mistakes.
 *   - Exactly ONE candidate → order note + release to Processing.
 *     Multiple candidates → nothing is released; admin is emailed to pick.
 *     Zero candidates → not our payment; ignored.
 *
 * @package ULP_Dropship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULP_DS_Zelle {

	const ROUTE = '/wc-zelle/v1/update-zelle-order';

	public function init() {
		add_filter( 'rest_request_after_callbacks', array( $this, 'after_zelle_webhook' ), 20, 3 );
	}

	/**
	 * @param mixed           $response Result of the Zelle plugin's handler.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed Unmodified $response — we only observe.
	 */
	public function after_zelle_webhook( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || self::ROUTE !== $request->get_route() ) {
			return $response;
		}

		try {
			$this->maybe_release( $response, $request );
		} catch ( \Throwable $e ) {
			ulp_ds_log( 'Zelle matcher error: ' . $e->getMessage(), 'error' );
		}

		return $response;
	}

	private function maybe_release( $response, WP_REST_Request $request ) {
		// The Zelle plugin returns array( status, message, data ). Respect its
		// outcome: unverified signature → not a trusted receipt; "updated to
		// processing/completed" → the payment was consumed by a retail order.
		$out = ( $response instanceof WP_REST_Response ) ? $response->get_data() : $response;
		if ( ! is_array( $out ) ) {
			return;
		}
		$status  = isset( $out['status'] ) ? (int) $out['status'] : 0;
		$message = isset( $out['message'] ) ? (string) $out['message'] : '';
		if ( 401 === $status ) {
			return;
		}
		if ( preg_match( '/updated to (processing|completed)/i', $message ) ) {
			return;
		}

		$body   = $request->get_body_params();
		$amount = isset( $body['transactionamount'] ) ? round( (float) $body['transactionamount'], 2 ) : 0.0;
		$sender = isset( $body['transactionaccountid'] ) ? trim( (string) $body['transactionaccountid'] ) : '';
		if ( $amount <= 0 ) {
			return;
		}

		// ── Candidates: held dropship orders whose remaining due == amount ──
		$held = wc_get_orders( array(
			'status' => array( 'wc-' . ULP_DS_STATUS ),
			'limit'  => 50,
		) );

		$candidates = array();
		foreach ( $held as $order ) {
			if ( $order->get_meta( '_ulp_ds_simulated' ) ) {
				continue;
			}
			$due = round( (float) $order->get_total() - (float) $order->get_meta( '_ulp_ds_credit_applied' ), 2 );
			if ( abs( $due - $amount ) > 0.005 ) {
				continue;
			}
			if ( ! self::sender_allowed( (string) $order->get_meta( '_ulp_ds_partner' ), $sender ) ) {
				continue;
			}
			$candidates[] = $order;
		}

		if ( empty( $candidates ) ) {
			return; // Not a dropship payment (or amount drifted) — retail flow noise.
		}

		if ( count( $candidates ) > 1 ) {
			$ids = implode( ', #', array_map( static function ( $o ) { return $o->get_order_number(); }, $candidates ) );
			ulp_ds_log( sprintf(
				'Zelle payment of $%s from %s matches %d held dropship orders (#%s) — nothing auto-released, pick manually.',
				number_format( $amount, 2 ), $sender ?: 'unknown sender', count( $candidates ), $ids
			), 'error' );
			wp_mail(
				get_option( 'admin_email' ),
				sprintf( '[Dropship] Zelle payment $%s matches %d held orders — manual release needed', number_format( $amount, 2 ), count( $candidates ) ),
				sprintf(
					"A Zelle payment of $%s from %s matches more than one held dropship order (#%s).\n\nNothing was released automatically — please confirm which order was paid and set it to Processing.\n\nWooCommerce → Dropship Connect has the log.",
					number_format( $amount, 2 ), $sender ?: 'unknown sender', $ids
				)
			);
			return;
		}

		$order = $candidates[0];
		$order->add_order_note( sprintf(
			'ULP Dropship: Zelle payment of $%s received from %s — auto-matched by amount, releasing to Processing.',
			number_format( $amount, 2 ), $sender ?: 'unknown sender'
		) );
		$order->update_status( 'processing' ); // Normal release path: ShipStation + referral.

		ulp_ds_log( sprintf(
			'[%s] Zelle payment of $%s from %s auto-matched → order #%s released.',
			$order->get_meta( '_ulp_ds_partner' ), number_format( $amount, 2 ), $sender ?: 'unknown sender', $order->get_order_number()
		) );
	}

	/**
	 * If the order's partner has zelle_sender configured (comma-separated
	 * names), the receipt sender must contain one of them (case-insensitive).
	 * No configuration = amount-only matching for that partner.
	 */
	private static function sender_allowed( $slug, $sender ) {
		$partner = ulp_ds_partner( $slug );
		$names   = $partner && ! empty( $partner['zelle_sender'] ) ? (string) $partner['zelle_sender'] : '';
		if ( '' === trim( $names ) ) {
			return true;
		}
		if ( '' === $sender ) {
			return false; // Partner requires a sender match but receipt has none.
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', $names ) ) ) as $name ) {
			if ( false !== stripos( $sender, $name ) || false !== stripos( $name, $sender ) ) {
				return true;
			}
		}
		return false;
	}
}
