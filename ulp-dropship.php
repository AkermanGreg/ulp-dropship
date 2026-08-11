<?php
/**
 * Plugin Name:       ULP Dropship Connect
 * Plugin URI:        https://ultralifepeptides.com
 * Description:        Receives orders from wholesale partners' WooCommerce stores (native Woo webhooks) and creates matching wholesale orders here — priced at the partner's B2BKing tier, shipped to their end customer. Orders arrive in a held status invisible to ShipStation until payment is confirmed and released. Settings under WooCommerce → Dropship Connect.
 * Version:           1.11.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Gregory Akerman
 * License:           GPL-2.0+
 * Text Domain:       ulp-ds
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ULP_DS_VERSION', '1.11.2' );
define( 'ULP_DS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ULP_DS_PARTNERS_OPTION', 'ulp_ds_partners' ); // slug => partner config
define( 'ULP_DS_LOG_OPTION', 'ulp_ds_log' );
define( 'ULP_DS_STATUS', 'ulp-dropship' );             // custom order status (no wc- prefix)

require_once ULP_DS_DIR . 'includes/class-ulp-ds-builder.php';
require_once ULP_DS_DIR . 'includes/class-ulp-ds-receiver.php';
require_once ULP_DS_DIR . 'includes/class-ulp-ds-shipnotify.php';
require_once ULP_DS_DIR . 'includes/class-ulp-ds-referral.php';
require_once ULP_DS_DIR . 'includes/class-ulp-ds-zelle.php';
require_once ULP_DS_DIR . 'includes/class-ulp-ds-writeback.php';
require_once ULP_DS_DIR . 'includes/class-ulp-ds-account.php';
require_once ULP_DS_DIR . 'includes/class-ulp-ds-admin.php';

/* -------------------------------------------------------------------------
 * Custom order status: "Dropship – Awaiting Payment"
 *
 * Deliberately NOT one of the statuses ShipStation exports (it pulls
 * processing / on-hold / completed / cancelled on this site), so unpaid
 * dropship orders never appear in the warehouse queue. Releasing = flipping
 * to Processing, which ShipStation maps to Awaiting Shipment.
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	register_post_status( 'wc-' . ULP_DS_STATUS, array(
		'label'                     => _x( 'Dropship – Awaiting Payment', 'order status', 'ulp-ds' ),
		'public'                    => true,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		/* translators: %s: number of orders */
		'label_count'               => _n_noop(
			'Dropship – Awaiting Payment <span class="count">(%s)</span>',
			'Dropship – Awaiting Payment <span class="count">(%s)</span>',
			'ulp-ds'
		),
	) );
} );

add_filter( 'wc_order_statuses', function ( $statuses ) {
	// Insert right after on-hold for a sensible dropdown position.
	$out = array();
	foreach ( $statuses as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'wc-on-hold' === $key ) {
			$out[ 'wc-' . ULP_DS_STATUS ] = _x( 'Dropship – Awaiting Payment', 'order status', 'ulp-ds' );
		}
	}
	if ( ! isset( $out[ 'wc-' . ULP_DS_STATUS ] ) ) {
		$out[ 'wc-' . ULP_DS_STATUS ] = _x( 'Dropship – Awaiting Payment', 'order status', 'ulp-ds' );
	}
	return $out;
} );

// Treat the held status as "needs payment" so totals/edit behave sanely.
add_filter( 'woocommerce_valid_order_statuses_for_payment', function ( $statuses ) {
	$statuses[] = ULP_DS_STATUS;
	return $statuses;
} );

// Woo only allows item editing for pending/on-hold orders — held dropship
// orders must be adjustable (fix a skipped SKU, tweak shipping) before release.
add_filter( 'wc_order_is_editable', function ( $editable, $order ) {
	return ( ULP_DS_STATUS === $order->get_status() ) ? true : $editable;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Activity log — durable, table-backed (paginated in the admin).
 *
 * Was a 100-entry rotating option; now every entry is kept permanently in a
 * dedicated table. Existing option entries are migrated in once.
 * ---------------------------------------------------------------------- */

function ulp_ds_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'ulp_ds_log';
}

/** Create the log table once and migrate the old rotating-option entries. */
function ulp_ds_maybe_install_log_table() {
	if ( '1' === get_option( 'ulp_ds_log_db_version' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( 'CREATE TABLE ' . ulp_ds_log_table() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		log_time datetime NOT NULL,
		level varchar(10) NOT NULL DEFAULT 'info',
		message text NOT NULL,
		PRIMARY KEY  (id),
		KEY log_time (log_time)
	) " . $wpdb->get_charset_collate() . ';' );

	// One-time migration of whatever the rotating option still holds.
	$old = get_option( ULP_DS_LOG_OPTION, array() );
	if ( is_array( $old ) ) {
		foreach ( $old as $entry ) {
			$wpdb->insert( ulp_ds_log_table(), array(
				'log_time' => isset( $entry['time'] ) ? $entry['time'] : current_time( 'mysql' ),
				'level'    => isset( $entry['level'] ) ? $entry['level'] : 'info',
				'message'  => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			) );
		}
		delete_option( ULP_DS_LOG_OPTION );
	}

	update_option( 'ulp_ds_log_db_version', '1' );
}

function ulp_ds_log( $message, $level = 'info' ) {
	global $wpdb;
	$ok = $wpdb->insert( ulp_ds_log_table(), array(
		'log_time' => current_time( 'mysql' ),
		'level'    => $level, // info | error
		'message'  => (string) $message,
	) );

	// Fallback (table missing, e.g. first request before install ran): keep
	// the old rotating-option behavior so no entry is ever dropped silently.
	if ( false === $ok ) {
		$log   = get_option( ULP_DS_LOG_OPTION, array() );
		$log[] = array( 'time' => current_time( 'mysql' ), 'level' => $level, 'message' => (string) $message );
		update_option( ULP_DS_LOG_OPTION, array_slice( $log, -100 ), false );
	}
}

/** All partner configs. slug => array. */
function ulp_ds_partners() {
	$partners = get_option( ULP_DS_PARTNERS_OPTION, array() );
	return is_array( $partners ) ? $partners : array();
}

/** One partner config by slug, or null. */
function ulp_ds_partner( $slug ) {
	$partners = ulp_ds_partners();
	return isset( $partners[ $slug ] ) ? $partners[ $slug ] : null;
}

/**
 * Recipient list for partner-facing emails (amount due, tracking).
 * Defaults to the partner user's account email; the per-partner
 * "notify_emails" setting overrides it (one or more addresses).
 *
 * @param array $partner Partner config.
 * @return string[] Valid email addresses (may be empty).
 */
function ulp_ds_partner_recipients( $partner ) {
	$custom = isset( $partner['notify_emails'] ) ? trim( (string) $partner['notify_emails'] ) : '';

	if ( '' !== $custom ) {
		$emails = array_filter( array_map( 'sanitize_email', preg_split( '/[,;\s]+/', $custom ) ) );
		if ( ! empty( $emails ) ) {
			return array_values( array_unique( $emails ) );
		}
	}

	$user = get_userdata( (int) ( isset( $partner['user_id'] ) ? $partner['user_id'] : 0 ) );
	return ( $user && is_email( $user->user_email ) ) ? array( $user->user_email ) : array();
}

/* -------------------------------------------------------------------------
 * Partner deposit credit
 *
 * Partners can pre-pay a deposit; each incoming dropship order automatically
 * consumes available credit. Orders fully covered by credit skip the
 * "Awaiting Payment" hold and release straight to Processing. Trashing or
 * cancelling an order returns whatever credit it consumed.
 * ---------------------------------------------------------------------- */

/** Current credit balance for a partner slug. */
function ulp_ds_get_credit( $slug ) {
	$partner = ulp_ds_partner( $slug );
	return $partner ? round( (float) ( isset( $partner['credit'] ) ? $partner['credit'] : 0 ), 2 ) : 0.0;
}

/**
 * Adjust a partner's credit balance by $delta (positive adds, negative
 * consumes). Reads the option fresh to minimize clobbering between the
 * webhook and admin. Every movement is logged.
 *
 * @return float|false New balance, or false if the partner doesn't exist.
 */
function ulp_ds_adjust_credit( $slug, $delta, $reason = '' ) {
	$partners = get_option( ULP_DS_PARTNERS_OPTION, array() );
	if ( ! is_array( $partners ) || ! isset( $partners[ $slug ] ) ) {
		return false;
	}

	$old   = round( (float) ( isset( $partners[ $slug ]['credit'] ) ? $partners[ $slug ]['credit'] : 0 ), 2 );
	$new   = round( $old + (float) $delta, 2 );
	$partners[ $slug ]['credit'] = $new;
	update_option( ULP_DS_PARTNERS_OPTION, $partners );

	// Durable per-partner ledger (feeds the partner's My Account "Credit" tab
	// and permanent accounting records — the activity log rotates, this doesn't).
	$ledger = get_option( 'ulp_ds_credit_ledger', array() );
	if ( ! is_array( $ledger ) ) {
		$ledger = array();
	}
	$ledger[ $slug ][] = array(
		'time'    => current_time( 'mysql' ),
		'delta'   => round( (float) $delta, 2 ),
		'reason'  => (string) ( $reason ? $reason : 'adjustment' ),
		'balance' => $new,
	);
	$ledger[ $slug ] = array_slice( $ledger[ $slug ], -500 );
	update_option( 'ulp_ds_credit_ledger', $ledger, false );

	ulp_ds_log( sprintf(
		'[%s] Credit %s$%s (%s) — balance $%s → $%s.',
		$slug,
		$delta >= 0 ? '+' : '-',
		number_format( abs( (float) $delta ), 2 ),
		$reason ? $reason : 'manual adjustment',
		number_format( $old, 2 ),
		number_format( $new, 2 )
	) );

	return $new;
}

/** Return an order's consumed credit to its partner (once). */
function ulp_ds_restore_order_credit( $order_id, $context ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$slug    = (string) $order->get_meta( '_ulp_ds_partner' );
	$applied = (float) $order->get_meta( '_ulp_ds_credit_applied' );
	if ( '' === $slug || $applied <= 0 || $order->get_meta( '_ulp_ds_credit_restored' ) ) {
		return;
	}

	// Reference the PARTNER's order number in the ledger (they see this on
	// their My Account credit statement); fall back to our number if absent.
	$origin = (string) $order->get_meta( '_ulp_ds_origin_order' );
	$new    = ulp_ds_adjust_credit( $slug, $applied, sprintf(
		'returned from %s order (your #%s)',
		$context,
		'' !== $origin ? $origin : $order->get_order_number()
	) );
	if ( false !== $new ) {
		$order->update_meta_data( '_ulp_ds_credit_restored', '1' );
		$order->save();
		$order->add_order_note( sprintf(
			'ULP Dropship: $%s deposit credit returned to partner (order %s). Partner balance: $%s.',
			number_format( $applied, 2 ), $context, number_format( $new, 2 )
		) );
	}
}

add_action( 'woocommerce_order_status_cancelled', function ( $order_id ) {
	ulp_ds_restore_order_credit( $order_id, 'cancelled' );
} );
add_action( 'wp_trash_post', function ( $post_id ) {
	if ( 'shop_order' === get_post_type( $post_id ) ) {
		ulp_ds_restore_order_credit( $post_id, 'trashed' );
	}
} );
// HPOS-compatible trash hook (fires instead of wp_trash_post when enabled).
add_action( 'woocommerce_trash_order', function ( $order_id ) {
	ulp_ds_restore_order_credit( $order_id, 'trashed' );
} );

/* -------------------------------------------------------------------------
 * Boot
 * ---------------------------------------------------------------------- */

add_action( 'plugins_loaded', function () {
	if ( ! function_exists( 'wc_create_order' ) ) {
		return; // WooCommerce inactive.
	}

	ulp_ds_maybe_install_log_table();

	( new ULP_DS_Receiver() )->init();
	( new ULP_DS_Shipnotify() )->init();
	( new ULP_DS_Referral() )->init();
	( new ULP_DS_Zelle() )->init();
	( new ULP_DS_Writeback() )->init();
	( new ULP_DS_Account() )->init();

	if ( is_admin() ) {
		( new ULP_DS_Admin() )->init();
	}
} );
