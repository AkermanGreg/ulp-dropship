<?php
/**
 * Partner-facing "Credit" tab on My Account.
 *
 * Visible ONLY to logged-in users whose account is a configured dropship
 * partner (matched by user id). Shows the current deposit balance and the
 * movement ledger written by ulp_ds_adjust_credit(): deposits recorded by
 * admin, credit applied to incoming orders, and credit returned from
 * cancelled/trashed orders.
 *
 * The ledger starts at this feature's deployment; anything earlier is shown
 * as a single computed "Opening balance" row (current balance minus the sum
 * of ledgered movements), so the math always reconciles on screen.
 *
 * @package ULP_Dropship
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULP_DS_Account {

	const ENDPOINT = 'partner-credit';

	public function init() {
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ), 40 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', function () {
			return __( 'Deposit Credit', 'ulp-ds' );
		} );
	}

	public function register_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		// One-time flush per plugin version so the endpoint URL resolves
		// without a manual Permalinks save.
		if ( get_option( 'ulp_ds_endpoint_flushed' ) !== ULP_DS_VERSION ) {
			flush_rewrite_rules();
			update_option( 'ulp_ds_endpoint_flushed', ULP_DS_VERSION );
		}
	}

	/** Partner slugs owned by a WP user (usually one; supports several). */
	public static function slugs_for_user( $user_id ) {
		$slugs = array();
		foreach ( ulp_ds_partners() as $slug => $partner ) {
			if ( (int) ( isset( $partner['user_id'] ) ? $partner['user_id'] : 0 ) === (int) $user_id ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	public function menu_item( $items ) {
		if ( empty( self::slugs_for_user( get_current_user_id() ) ) ) {
			return $items; // Not a partner — no tab.
		}

		// Insert before Log out for a sensible position.
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Deposit Credit', 'ulp-ds' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Deposit Credit', 'ulp-ds' );
		}
		return $out;
	}

	public function render() {
		$slugs = self::slugs_for_user( get_current_user_id() );
		if ( empty( $slugs ) ) {
			echo '<p>' . esc_html__( 'No partner account is linked to this login.', 'ulp-ds' ) . '</p>';
			return;
		}

		$ledger_all = get_option( 'ulp_ds_credit_ledger', array() );

		foreach ( $slugs as $slug ) {
			$partner = ulp_ds_partner( $slug );
			$balance = ulp_ds_get_credit( $slug );
			$entries = ( isset( $ledger_all[ $slug ] ) && is_array( $ledger_all[ $slug ] ) ) ? $ledger_all[ $slug ] : array();

			if ( count( $slugs ) > 1 && $partner ) {
				echo '<h3>' . esc_html( $partner['label'] ) . '</h3>';
			}

			echo '<p style="font-size:1.35em;margin:0.4em 0 1em;">' . esc_html__( 'Current balance:', 'ulp-ds' ) . ' <strong>' . wp_kses_post( wc_price( $balance ) ) . '</strong></p>';

			// Opening balance = current balance minus all ledgered deltas, so
			// the visible rows always add up to the balance shown above.
			$delta_sum = 0.0;
			foreach ( $entries as $entry ) {
				$delta_sum += (float) $entry['delta'];
			}
			$opening = round( $balance - $delta_sum, 2 );

			if ( empty( $entries ) && 0.0 === $opening ) {
				echo '<p>' . esc_html__( 'No credit activity yet. Deposits appear here as soon as they are recorded, and each order shows the credit it used.', 'ulp-ds' ) . '</p>';
				continue;
			}

			echo '<table class="woocommerce-orders-table shop_table shop_table_responsive">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Date', 'ulp-ds' ) . '</th>';
			echo '<th>' . esc_html__( 'Description', 'ulp-ds' ) . '</th>';
			echo '<th>' . esc_html__( 'Amount', 'ulp-ds' ) . '</th>';
			echo '<th>' . esc_html__( 'Balance', 'ulp-ds' ) . '</th>';
			echo '</tr></thead><tbody>';

			// Newest first.
			foreach ( array_reverse( $entries ) as $entry ) {
				$delta = (float) $entry['delta'];
				echo '<tr>';
				echo '<td data-title="' . esc_attr__( 'Date', 'ulp-ds' ) . '">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $entry['time'] ) ) ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Description', 'ulp-ds' ) . '">' . esc_html( $entry['reason'] ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Amount', 'ulp-ds' ) . '" style="color:' . ( $delta >= 0 ? '#1a7a2e' : '#b32d2e' ) . ';">' . ( $delta >= 0 ? '+' : '−' ) . wp_kses_post( wc_price( abs( $delta ) ) ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Balance', 'ulp-ds' ) . '">' . wp_kses_post( wc_price( (float) $entry['balance'] ) ) . '</td>';
				echo '</tr>';
			}

			if ( 0.0 !== $opening || ! empty( $entries ) ) {
				echo '<tr>';
				echo '<td data-title="' . esc_attr__( 'Date', 'ulp-ds' ) . '">—</td>';
				echo '<td data-title="' . esc_attr__( 'Description', 'ulp-ds' ) . '">' . esc_html__( 'Opening balance', 'ulp-ds' ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Amount', 'ulp-ds' ) . '"></td>';
				echo '<td data-title="' . esc_attr__( 'Balance', 'ulp-ds' ) . '">' . wp_kses_post( wc_price( $opening ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<p class="woocommerce-info" style="margin-top:1em;">' . esc_html__( 'Deposits are applied automatically to your incoming orders. Questions about your balance? Contact us.', 'ulp-ds' ) . '</p>';
	}
}
